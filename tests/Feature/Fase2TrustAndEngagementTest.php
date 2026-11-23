<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateKycNikException;
use App\Exceptions\SelfDealingException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Product;
use App\Models\SellerKycVerification;
use App\Models\SellerProfile;
use App\Models\StoreOperatingHour;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\UserNotification;
use App\Models\WithdrawalRequest;
use App\Services\BankAccountService;
use App\Services\ConversationService;
use App\Services\EscrowLedgerService;
use App\Services\NotificationService;
use App\Services\OrderStateAndSlaService;
use App\Services\SellerKycService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Fase2TrustAndEngagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_pre_transaction_chat_creates_single_unique_conversation_moderates_bypass_and_blocks_self_chat(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $pSteam120 = Product::where('sku_code', 'STM-IDR-120K')->firstOrFail();
        $pSteam60 = Product::where('sku_code', 'STM-IDR-60K')->firstOrFail();

        // Start chat from Product 1
        $resp1 = $this->actingAs($buyer1)->postJson(route('inbox.start'), [
            'seller_id' => $seller1->id,
            'product_id' => $pSteam120->id,
        ]);
        $resp1->assertOk()->assertJson(['ok' => true]);
        $convId1 = (int) $resp1->json('conversation_id');

        // Start chat again from Product 2 of the SAME seller -> must reuse single (buyer_id, seller_id) row
        $resp2 = $this->actingAs($buyer1)->postJson(route('inbox.start'), [
            'seller_id' => $seller1->id,
            'product_id' => $pSteam60->id,
        ]);
        $resp2->assertOk()->assertJson(['ok' => true]);
        $convId2 = (int) $resp2->json('conversation_id');

        $this->assertSame($convId1, $convId2);
        $this->assertSame(1, Conversation::where('buyer_id', $buyer1->id)->where('seller_id', $seller1->id)->count());

        // Send message with attached product card + phone number & off-platform payment keyword
        $sendResp = $this->actingAs($buyer1)->postJson(route('inbox.send', $convId1), [
            'message' => 'Halo bang, stok 60K ready? Chat WA aja ke 081234567890 biar transfer langsung via DANA.',
            'attached_product_id' => $pSteam60->id,
        ]);
        $sendResp->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message.is_flagged_bypass', true)
            ->assertJsonPath('message.attached_product.id', $pSteam60->id);

        $savedMsg = ConversationMessage::findOrFail((int) $sendResp->json('message.id'));
        $this->assertTrue($savedMsg->is_flagged_bypass);
        $this->assertStringContainsString('[SENSOR ANTI-BYPASS NEXATRADE]', $savedMsg->sanitized_message);
        $this->assertStringNotContainsString('081234567890', $savedMsg->sanitized_message);

        // Seller unread count incremented & chat notification delivered
        $conv = Conversation::findOrFail($convId1);
        $this->assertSame(1, $conv->seller_unread_count);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $seller1->id,
            'category' => 'chat',
            'reference_type' => 'conversation',
            'reference_id' => $convId1,
        ]);

        // Seller fetches messages -> marks conversation read and resets seller_unread_count to 0
        $pollResp = $this->actingAs($seller1)->getJson(route('inbox.messages', $convId1));
        $pollResp->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(0, $conv->fresh()->seller_unread_count);
        $this->assertNotNull($savedMsg->fresh()->read_at);

        // Self-chatting must be rejected (HTTP 422 + SelfDealingException)
        $selfResp = $this->actingAs($seller1)->postJson(route('inbox.start'), [
            'seller_id' => $seller1->id,
        ]);
        $selfResp->assertStatus(422)->assertJson(['ok' => false]);

        $this->expectException(SelfDealingException::class);
        app(ConversationService::class)->startOrGetConversation($seller1->id, $seller1->id);
    }

    public function test_buyer_submits_kyc_stored_on_private_local_disk_encrypted_nik_and_blind_hash(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $nik = '3174091234567890';

        $resp = $this->actingAs($buyer1)->post(route('buyer.open-store.submit'), [
            'proposed_store_name' => 'Bagas Digital Mart',
            'proposed_store_slug' => 'bagas-digital-mart',
            'proposed_store_tagline' => 'Top Up Cepat & Brankas Instan',
            'full_legal_name' => 'BAGAS PUJI FERIAN',
            'nik' => $nik,
            'id_card_file' => UploadedFile::fake()->create('ktp.jpg', 120, 'image/jpeg'),
            'selfie_file' => UploadedFile::fake()->create('selfie.png', 150, 'image/png'),
        ]);

        $resp->assertRedirect(route('buyer.open-store'));
        $resp->assertSessionHas('success');

        $kyc = SellerKycVerification::where('user_id', $buyer1->id)->firstOrFail();
        $this->assertSame('pending', $kyc->status);
        $this->assertSame('7890', $kyc->nik_last4);
        $this->assertSame($nik, Crypt::decryptString($kyc->nik_encrypted));
        $this->assertSame($kyc->nik_blind_hash, $kyc->active_nik_lock_hash);
        $this->assertSame($buyer1->id, $kyc->active_user_lock);

        // Files MUST exist on private local disk and NEVER on public disk
        Storage::disk('local')->assertExists($kyc->id_card_private_path);
        Storage::disk('local')->assertExists($kyc->selfie_private_path);
        $this->assertEmpty(Storage::disk('public')->allFiles());

        // Admin receives KYC notification
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $admin->id,
            'category' => 'kyc',
            'reference_type' => 'seller_kyc',
            'reference_id' => $kyc->id,
        ]);
    }

    public function test_nik_anti_tuyul_blocks_duplicate_16_digit_nik_across_accounts(): void
    {
        Storage::fake('local');

        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $duplicateNik = '3578030303950003'; // Already locked by Buyer 2's pending KYC!

        // HTTP submission rejected with error flash
        $httpResp = $this->actingAs($buyer1)->post(route('buyer.open-store.submit'), [
            'proposed_store_name' => 'Clone Store Tuyul',
            'proposed_store_slug' => 'clone-store-tuyul',
            'full_legal_name' => 'AKUN TUYUL',
            'nik' => $duplicateNik,
            'id_card_file' => UploadedFile::fake()->create('ktp.jpg', 100, 'image/jpeg'),
            'selfie_file' => UploadedFile::fake()->create('selfie.jpg', 100, 'image/jpeg'),
        ]);
        $httpResp->assertRedirect();
        $httpResp->assertSessionHas('error');
        $this->assertDatabaseMissing('seller_kyc_verifications', ['user_id' => $buyer1->id]);

        // Direct service invocation throws DuplicateKycNikException
        $this->expectException(DuplicateKycNikException::class);
        app(SellerKycService::class)->submitApplication(
            userId: $buyer1->id,
            data: [
                'proposed_store_name' => 'Clone Store Tuyul',
                'proposed_store_slug' => 'clone-store-tuyul',
                'full_legal_name' => 'AKUN TUYUL',
                'nik' => $duplicateNik,
            ],
            idCardFile: UploadedFile::fake()->create('ktp2.jpg', 100, 'image/jpeg'),
            selfieFile: UploadedFile::fake()->create('selfie2.jpg', 100, 'image/jpeg'),
        );
    }

    public function test_kyc_reject_releases_active_locks_and_allows_resubmission_with_same_nik(): void
    {
        Storage::fake('local');

        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $pendingKyc = SellerKycVerification::where('user_id', $buyer2->id)->where('status', 'pending')->firstOrFail();
        $originalNik = Crypt::decryptString($pendingKyc->nik_encrypted);

        // Admin rejects pending KYC
        $rejectResp = $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingKyc->id), [
            'rejection_reason' => 'Foto KTP terlalu gelap dan sudut terpotong, silakan foto ulang.',
        ]);
        $rejectResp->assertRedirect(route('admin.kyc.index', ['tab' => 'rejected']));
        $rejectResp->assertSessionHas('success');

        $pendingKyc->refresh();
        $this->assertSame('rejected', $pendingKyc->status);
        $this->assertNull($pendingKyc->active_nik_lock_hash);
        $this->assertNull($pendingKyc->active_user_lock);
        $this->assertSame($admin->id, $pendingKyc->reviewed_by);

        // Buyer 2 notified of rejection
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $buyer2->id,
            'category' => 'kyc',
            'reference_id' => $pendingKyc->id,
        ]);

        // Buyer 2 legitimately re-submits with the EXACT SAME 16-digit NIK
        $resubmitResp = $this->actingAs($buyer2)->post(route('buyer.open-store.submit'), [
            'proposed_store_name' => 'ProPlayer TopUp Store',
            'proposed_store_slug' => 'proplayer-topup-store',
            'proposed_store_tagline' => 'Top Up Kilat Resmi 24 Jam',
            'full_legal_name' => 'FAJAR NUGROHO',
            'nik' => $originalNik,
            'id_card_file' => UploadedFile::fake()->create('ktp-clear.jpg', 120, 'image/jpeg'),
            'selfie_file' => UploadedFile::fake()->create('selfie-clear.jpg', 120, 'image/jpeg'),
        ]);
        $resubmitResp->assertRedirect(route('buyer.open-store'));
        $resubmitResp->assertSessionHas('success');

        $newKyc = SellerKycVerification::where('user_id', $buyer2->id)
            ->where('status', 'pending')
            ->latest('id')
            ->firstOrFail();
        $this->assertNotSame($pendingKyc->id, $newKyc->id);
        $this->assertSame($pendingKyc->nik_blind_hash, $newKyc->active_nik_lock_hash);
        $this->assertSame($buyer2->id, $newKyc->active_user_lock);
    }

    public function test_kyc_approval_atomically_upgrades_user_creates_store_profile_7_operating_hours_and_notifies(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $pendingKyc = SellerKycVerification::where('user_id', $buyer2->id)->where('status', 'pending')->firstOrFail();

        $this->assertSame('buyer', $buyer2->role);
        $this->assertNull(SellerProfile::where('user_id', $buyer2->id)->first());

        $approveResp = $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingKyc->id));
        $approveResp->assertRedirect(route('admin.kyc.index', ['tab' => 'approved']));
        $approveResp->assertSessionHas('success');

        $buyer2->refresh();
        $pendingKyc->refresh();

        $this->assertSame('approved', $pendingKyc->status);
        $this->assertSame('seller', $buyer2->role);

        $profile = SellerProfile::where('user_id', $buyer2->id)->firstOrFail();
        $this->assertSame('ProPlayer TopUp Store', $profile->store_name);
        $this->assertSame('proplayer-topup-store', $profile->slug);
        $this->assertTrue($profile->is_store_open);
        $this->assertFalse($profile->is_sla_10m_badge);

        // 7 operating hours seeded (day 0..6)
        $this->assertSame(7, StoreOperatingHour::where('seller_id', $buyer2->id)->count());

        // Approval notification sent
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $buyer2->id,
            'category' => 'kyc',
            'reference_id' => $pendingKyc->id,
        ]);
    }

    public function test_private_kyc_document_streaming_enforces_strict_admin_or_owner_authorization(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        $kyc = SellerKycVerification::where('user_id', $buyer2->id)->where('status', 'pending')->firstOrFail();

        // Admin authorized -> HTTP 200
        $this->actingAs($admin)
            ->get(route('admin.kyc.document', ['kyc' => $kyc->id, 'type' => 'id_card']))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.kyc.document', ['kyc' => $kyc->id, 'type' => 'selfie']))
            ->assertOk();

        // Submitting owner authorized -> HTTP 200
        $this->actingAs($buyer2)
            ->get(route('admin.kyc.document', ['kyc' => $kyc->id, 'type' => 'id_card']))
            ->assertOk();

        // Unrelated buyer & unrelated seller forbidden -> HTTP 403
        $this->actingAs($buyer1)
            ->get(route('admin.kyc.document', ['kyc' => $kyc->id, 'type' => 'id_card']))
            ->assertForbidden();
        $this->actingAs($seller1)
            ->get(route('admin.kyc.document', ['kyc' => $kyc->id, 'type' => 'selfie']))
            ->assertForbidden();
    }

    public function test_saved_bank_accounts_primary_switch_withdrawal_snapshot_and_pending_deletion_guard(): void
    {
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();

        // Ensure Seller 2 has enough available balance for withdrawal test
        app(EscrowLedgerService::class)->adminDemoTopup($seller2->id, 200000.00, 'TOPUP-PHASE2-TEST');

        $bankService = app(BankAccountService::class);
        $accBca = $bankService->addBankAccount($seller2->id, [
            'bank_code' => 'BCA',
            'account_number' => '7711223344',
            'account_holder_name' => 'DIMAS MAHENDRA',
            'is_primary' => false,
        ]);
        $accGopay = $bankService->addBankAccount($seller2->id, [
            'bank_code' => 'GOPAY',
            'account_number' => '081234567899',
            'account_holder_name' => 'DIMAS MAHENDRA',
            'is_primary' => false,
        ]);

        // Atomic primary switch via HTTP
        $this->actingAs($seller2)
            ->post(route('wallet.bank-accounts.primary', $accGopay->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($accGopay->fresh()->is_primary);
        $this->assertSame(1, UserBankAccount::where('user_id', $seller2->id)->where('is_primary', true)->count());

        // Submit withdrawal referencing saved user_bank_account_id
        $wdResp = $this->actingAs($seller2)->post(route('seller.wallet.withdraw'), [
            'user_bank_account_id' => $accGopay->id,
            'amount' => 100000,
        ]);
        $wdResp->assertRedirect()->assertSessionHas('success');

        $withdrawal = WithdrawalRequest::where('user_id', $seller2->id)
            ->where('user_bank_account_id', $accGopay->id)
            ->latest('id')
            ->firstOrFail();

        // Snapshot fields accurately copied from saved bank account
        $this->assertSame('GoPay', $withdrawal->bank_name);
        $this->assertSame('081234567899', $withdrawal->account_number);
        $this->assertSame('DIMAS MAHENDRA', $withdrawal->account_holder);
        $this->assertSame('PENDING', $withdrawal->status);

        // BLOCKED from deleting bank account while PENDING withdrawal references it
        $blockedDel = $this->actingAs($seller2)->delete(route('wallet.bank-accounts.destroy', $accGopay->id));
        $blockedDel->assertRedirect()->assertSessionHas('error');
        $this->assertNull($accGopay->fresh()->deleted_at);

        // Admin approves withdrawal -> Seller 2 notified -> now deletion succeeds & another account promoted to primary
        app(OrderStateAndSlaService::class)->approveWithdrawal($withdrawal->id, $admin->id);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $seller2->id,
            'category' => 'withdrawal',
            'reference_id' => $withdrawal->id,
        ]);

        $okDel = $this->actingAs($seller2)->delete(route('wallet.bank-accounts.destroy', $accGopay->id));
        $okDel->assertRedirect()->assertSessionHas('success');
        $this->assertSoftDeleted('user_bank_accounts', ['id' => $accGopay->id]);
        $this->assertSame(1, UserBankAccount::where('user_id', $seller2->id)->where('is_primary', true)->count());
    }

    public function test_notification_bell_lifecycle_and_all_phase2_views_render_http_200(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();

        $notifService = app(NotificationService::class);
        $beforeBell = $notifService->getBellSummary($buyer1->id);

        $created = $notifService->notify(
            userId: $buyer1->id,
            category: 'escrow',
            title: 'Test Escrow Update',
            body: 'Dana escrow terverifikasi aman.',
            actionUrl: '/buyer/orders',
        );

        $afterBell = $notifService->getBellSummary($buyer1->id);
        $this->assertSame($beforeBell['unread_count'] + 1, $afterBell['unread_count']);

        // Another user cannot mark Buyer 1's notification read (scoped strictly to auth user -> 404)
        $this->actingAs($buyer2)
            ->postJson(route('notifications.read', $created->id))
            ->assertNotFound();

        // Buyer 1 marks single notification read
        $this->actingAs($buyer1)
            ->postJson(route('notifications.read', $created->id))
            ->assertOk()
            ->assertJson(['ok' => true]);
        $this->assertNotNull($created->fresh()->read_at);

        // Buyer 1 marks all remaining notifications read
        $this->actingAs($buyer1)
            ->postJson(route('notifications.read-all'))
            ->assertOk()
            ->assertJson(['ok' => true, 'unread_count' => 0]);
        $this->assertSame(0, UserNotification::where('user_id', $buyer1->id)->whereNull('read_at')->count());

        // Full HTTP 200 smoke test across all Phase 2 Light Mode views
        $this->actingAs($buyer1)->get(route('inbox.index'))->assertOk()->assertSee('Inbox Chat Pra-Transaksi');
        $this->actingAs($buyer1)->get(route('buyer.open-store'))->assertOk()->assertSee('Buka Toko');
        $this->actingAs($buyer2)->get(route('buyer.open-store'))->assertOk()->assertSee('Sedang Ditinjau Tim Keamanan NexaTrade');
        $this->actingAs($admin)->get(route('admin.kyc.index'))->assertOk()->assertSee('ProPlayer TopUp Store');
        $this->actingAs($admin)->get(route('admin.kyc.index', ['tab' => 'flagged_chat']))->assertOk()->assertSee('Audit Chat Terindikasi Bypass');
        $this->actingAs($seller1)->get(route('seller.bank-accounts'))->assertOk()->assertSee('8830192841');
        $this->actingAs($seller1)->get(route('seller.wallet'))->assertOk()->assertSee('Pilih Rekening Tujuan Tersimpan');
        $this->actingAs($buyer1)->get(route('notifications.index'))->assertOk()->assertSee('Pusat Notifikasi');
        $this->actingAs($buyer1)->get(route('notifications.index', ['category' => 'escrow']))->assertOk();
        $this->get(route('stores.toko', 'juragan-voucher-official'))->assertOk()->assertSee('Juragan Voucher Official');
    }
}
