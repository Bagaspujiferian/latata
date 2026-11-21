<?php

namespace Tests\Feature;

use App\Exceptions\AntiGestunWithdrawalException;
use App\Exceptions\DuplicateBankAccountException;
use App\Models\AdCreditPackage;
use App\Models\Game;
use App\Models\GameCategory;
use App\Models\LedgerAuditSnapshot;
use App\Models\Order;
use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Models\ProductBoost;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\Wallet;
use App\Models\WalletPinResetToken;
use App\Models\WalletTopUp;
use App\Services\AccountRecoveryService;
use App\Services\AdCreditAndStoreLevelService;
use App\Services\BankAccountService;
use App\Services\CheckoutSplitOrderService;
use App\Services\OrderStateAndSlaService;
use App\Services\WalletPinService;
use App\Services\WalletTopUpService;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class Fase5EnterprisePolishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_time_boxed_ad_boost_and_sweeper_expiry(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $product = Product::where('seller_id', $seller1->id)
            ->where('is_featured', false)
            ->firstOrFail();

        $creditsBefore = (int) $seller1->sellerProfile->fresh()->ad_credits;

        // 1. Boost product as Featured (3 credits, 7 days)
        $this->actingAs($seller1)
            ->post(route('seller.ad-credits.boost', $product))
            ->assertRedirect()
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertTrue((bool) $product->is_featured);
        $this->assertNotNull($product->featured_until);
        $this->assertTrue($product->featured_until->isFuture());
        $this->assertSame($creditsBefore - 3, (int) $seller1->sellerProfile->fresh()->ad_credits);

        $boost = ProductBoost::where('product_id', $product->id)
            ->where('status', 'active')
            ->firstOrFail();
        $this->assertSame('featured', $boost->boost_type);
        $this->assertSame(3, (int) $boost->credits_spent);

        // 2. Shift ends_at into the past and run nexatrade:expire-boosts sweeper
        DB::table('product_boosts')
            ->where('product_id', $product->id)
            ->update(['ends_at' => now()->subMinutes(10)]);

        $this->artisan('nexatrade:expire-boosts')
            ->assertExitCode(0);

        $boost->refresh();
        $product->refresh();

        $this->assertSame('expired', $boost->status);
        $this->assertFalse((bool) $product->is_featured);
        $this->assertNull($product->featured_until);
    }

    public function test_anti_gaming_store_level_guard_blocks_dummy_orders_and_single_buyer_farming(): void
    {
        $levelService = app(AdCreditAndStoreLevelService::class);

        $seller = User::create([
            'name' => 'Anti Gaming Test Seller',
            'email' => 'antigaming.seller@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'seller',
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $seller->id]);
        SellerProfile::create([
            'user_id' => $seller->id,
            'store_name' => 'Anti Gaming Test Store',
            'slug' => 'anti-gaming-test-store',
            'is_store_open' => true,
            'auto_schedule_enabled' => false,
            'avg_delivery_minutes' => '5.00',
            'store_level' => 1,
        ]);

        // Create 12 distinct test buyers
        $buyers = [];
        for ($i = 1; $i <= 12; $i++) {
            $b = User::create([
                'name' => "Test Buyer {$i}",
                'email' => "ag.buyer{$i}@nexatrade.id",
                'password' => Hash::make('password123'),
                'role' => 'buyer',
                'is_active' => true,
            ]);
            Wallet::create(['user_id' => $b->id]);
            $buyers[] = $b;
        }

        // 1. Insert 30 dummy Rp 100 COMPLETED orders across 12 buyers -> MUST stay Level 1!
        for ($i = 0; $i < 30; $i++) {
            $buyer = $buyers[$i % count($buyers)];
            $this->createCompletedOrderFixture($buyer->id, $seller->id, 100.00);
        }

        $afterDummy = $levelService->recalculateStoreLevel($seller->id, true);
        $this->assertSame(1, $afterDummy['level']);
        $this->assertSame(0, $afterDummy['qualifying_orders']);

        // 2. Insert 30 real-value (Rp 150.000) COMPLETED orders from ONLY 1 single buyer -> MUST stay Level 1!
        for ($i = 0; $i < 30; $i++) {
            $this->createCompletedOrderFixture($buyers[0]->id, $seller->id, 150000.00);
        }

        $afterSingleBuyer = $levelService->recalculateStoreLevel($seller->id, true);
        $this->assertSame(1, $afterSingleBuyer['level']);
        $this->assertSame(30, $afterSingleBuyer['qualifying_orders']);
        $this->assertSame(1, $afterSingleBuyer['unique_buyers']);

        // 3. Add 9 more distinct buyers (>= 10 unique buyers total, >= 25 orders >= 10k, GMV >= 2.5M, SLA <= 10m) -> Level 2!
        for ($i = 1; $i <= 9; $i++) {
            $this->createCompletedOrderFixture($buyers[$i]->id, $seller->id, 100000.00);
        }

        $afterQualified = $levelService->recalculateStoreLevel($seller->id, true);
        $this->assertSame(2, $afterQualified['level']);
        $this->assertSame('Juragan Pro', $afterQualified['tier_name']);
        $this->assertSame(0.5, $afterQualified['commission_discount_percent']);
        $this->assertSame(10, $afterQualified['unique_buyers']);
        $this->assertSame(2, (int) $seller->sellerProfile->fresh()->store_level);
    }

    public function test_http_idempotency_guard_prevents_double_click_and_rejects_payload_mismatch(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $bank = UserBankAccount::where('user_id', $seller1->id)->firstOrFail();
        $pkgStarter = AdCreditPackage::where('code', 'STARTER-10')->firstOrFail();
        $pkgPro = AdCreditPackage::where('code', 'PRO-30')->firstOrFail();

        // A. Ad Credits purchase double-click with identical Idempotency-Key -> charged ONCE!
        $availBefore = (float) $seller1->wallet->fresh()->available_balance;
        $creditsBefore = (int) $seller1->sellerProfile->fresh()->ad_credits;
        $adKey = 'idem-adcred-'.Str::random(10);

        $firstAd = $this->actingAs($seller1)
            ->withHeader('Idempotency-Key', $adKey)
            ->post(route('seller.ad-credits.purchase'), ['package_id' => $pkgStarter->id]);
        $firstAd->assertRedirect()->assertSessionHas('success');

        $secondAd = $this->actingAs($seller1)
            ->withHeader('Idempotency-Key', $adKey)
            ->post(route('seller.ad-credits.purchase'), ['package_id' => $pkgStarter->id]);
        $secondAd->assertRedirect()->assertHeader('X-Idempotency-Replayed', 'true');

        $this->assertEqualsWithDelta($availBefore - 15000.00, (float) $seller1->wallet->fresh()->available_balance, 0.01);
        $this->assertSame($creditsBefore + 10, (int) $seller1->sellerProfile->fresh()->ad_credits);

        // B. Reusing same Idempotency-Key with a DIFFERENT payload -> MUST return HTTP 422!
        $mismatch = $this->actingAs($seller1)
            ->withHeader('Idempotency-Key', $adKey)
            ->post(route('seller.ad-credits.purchase'), ['package_id' => $pkgPro->id]);
        $mismatch->assertStatus(422);

        // C. Withdrawal double-click with identical Idempotency-Key -> held ONCE!
        $availBeforeWd = (float) $seller1->wallet->fresh()->available_balance;
        $wdCountBefore = DB::table('withdrawal_requests')->where('user_id', $seller1->id)->count();
        $wdKey = 'idem-wd-'.Str::random(10);

        $this->actingAs($seller1)
            ->withHeader('Idempotency-Key', $wdKey)
            ->post(route('seller.wallet.withdraw'), [
                'user_bank_account_id' => $bank->id,
                'amount' => 20000,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($seller1)
            ->withHeader('Idempotency-Key', $wdKey)
            ->post(route('seller.wallet.withdraw'), [
                'user_bank_account_id' => $bank->id,
                'amount' => 20000,
            ])
            ->assertRedirect()
            ->assertHeader('X-Idempotency-Replayed', 'true');

        $this->assertSame($wdCountBefore + 1, DB::table('withdrawal_requests')->where('user_id', $seller1->id)->count());
        $this->assertEqualsWithDelta($availBeforeWd - 20000.00, (float) $seller1->wallet->fresh()->available_balance, 0.01);
    }

    public function test_partial_dispute_split_settlement_anti_farming_and_balanced_ledger(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        // Create a deterministic Rp 100.000 product under a 5% commission category (seller_escrow = Rp 95.000)
        $game = Game::firstOrFail();
        $category5Pct = GameCategory::create([
            'game_id' => $game->id,
            'name' => 'Split Test Manual 5%',
            'slug' => 'split-test-manual-5pct',
            'delivery_method' => 'manual_trade',
            'platform_fee_percent' => '5.00',
            'escrow_hold_hours' => 24,
            'buyer_input_schema' => [],
        ]);
        $product100k = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $category5Pct->id,
            'sku_code' => 'SPLIT-TEST-100K',
            'title' => 'Item Digital Split Settlement Rp 100.000',
            'slug' => 'item-digital-split-settlement-100k',
            'description' => 'Produk uji putusan parsial Split Settlement 60/40 dengan voucher platform.',
            'price' => '100000.00',
            'stock' => 5,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 15,
            'is_active' => true,
        ]);

        // Checkout Rp 100.000 with Platform Voucher NEXAHEMAT20 (Rp 20.000 subsidy)
        // -> buyer_payable = 80.000, seller_escrow = 95.000
        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [['product_id' => $product100k->id, 'quantity' => 1]],
            clearCart: false,
            platformVoucherCode: 'NEXAHEMAT20',
        );
        $order = $invoice->orders()->firstOrFail();
        $this->assertSame('100000.00', (string) $order->gross_amount);
        $this->assertSame('20000.00', (string) $order->platform_discount_amount);
        $this->assertSame('80000.00', (string) $order->buyer_payable_amount);
        $this->assertSame('95000.00', (string) $order->seller_escrow_amount);

        // Seller delivers -> Buyer opens dispute
        $orderState = app(OrderStateAndSlaService::class);
        $orderState->sellerSubmitManualDelivery($order->id, $seller1->id, 'Item dikirim sebagian.');
        $orderState->buyerOpenDispute($order->id, $buyer1->id, 'Hanya 40% item yang diterima, mohon penyelesaian parsial.');

        $buyerAvailBefore = (float) $buyer1->wallet->fresh()->available_balance;
        $sellerAvailBefore = (float) $seller1->wallet->fresh()->available_balance;
        $sellerPendingBefore = (float) $seller1->wallet->fresh()->pending_balance;

        // Admin executes SPLIT_SETTLEMENT: 60% Buyer / 40% Seller
        $this->actingAs($admin)
            ->post(route('admin.disputes.resolve', $order->order_code), [
                'verdict' => 'SPLIT_SETTLEMENT',
                'buyer_refund_percent' => 60,
                'admin_notes' => 'Putusan parsial 60% pembeli (Rp 48k) dan 40% penjual (Rp 38k).',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $buyerWallet = $buyer1->wallet->fresh();
        $sellerWallet = $seller1->wallet->fresh();

        $this->assertSame('COMPLETED', $order->status);
        $this->assertSame(60, (int) $order->dispute_buyer_refund_percent);
        $this->assertSame('48000.00', (string) $order->dispute_buyer_refund_amount);
        $this->assertSame('38000.00', (string) $order->dispute_seller_release_amount);

        // Anti-Farming: Buyer gets 60% x 80.000 = 48.000 (NOT 60% x 100.000 = 60.000!)
        $this->assertEqualsWithDelta($buyerAvailBefore + 48000.00, (float) $buyerWallet->available_balance, 0.01);
        $this->assertSame('0.00', (string) $buyerWallet->non_withdrawable_portion);

        // Seller gets 40% x 95.000 = 38.000 available & pending drops by FULL 95.000!
        $this->assertEqualsWithDelta($sellerAvailBefore + 38000.00, (float) $sellerWallet->available_balance, 0.01);
        $this->assertEqualsWithDelta($sellerPendingBefore - 95000.00, (float) $sellerWallet->pending_balance, 0.01);

        $this->assertDatabaseHas('wallet_mutations', [
            'order_id' => $order->id,
            'mutation_type' => 'DISPUTE_PARTIAL_RELEASE',
            'balance_bucket' => 'available',
            'direction' => 'CREDIT',
            'amount' => '38000.00',
        ]);
        $this->assertDatabaseHas('wallet_mutations', [
            'order_id' => $order->id,
            'mutation_type' => 'DISPUTE_PARTIAL_REFUND',
            'balance_bucket' => 'available',
            'direction' => 'CREDIT',
            'amount' => '48000.00',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'dispute.split_settlement',
            'entity_id' => $order->id,
        ]);

        // Global ledger reconciliation MUST remain BALANCED with Rp 0.00 discrepancy!
        $this->artisan('nexatrade:audit-ledger', ['--triggered-by' => 'fase5_split'])
            ->assertExitCode(0);
        $latest = LedgerAuditSnapshot::orderByDesc('id')->firstOrFail();
        $this->assertSame('BALANCED', $latest->status);
        $this->assertSame('0.00', (string) $latest->discrepancy_amount);
    }

    public function test_buyer_wallet_topup_anti_gestun_lock_erosion_and_balanced_ledger(): void
    {
        $user = User::create([
            'name' => 'Anti Gestun Buyer',
            'email' => 'antigestun@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'pending_balance' => '0.00',
            'available_balance' => '0.00',
            'non_withdrawable_portion' => '0.00',
        ]);

        // 1. Create Rp 200.000 Top-Up & simulate payment via HTTP routes
        $this->actingAs($user)
            ->post(route('buyer.wallet.topup'), [
                '_idempotency_key' => (string) Str::uuid(),
                'amount' => 200000,
                'payment_channel' => 'qris',
            ])
            ->assertRedirect();

        $topup = WalletTopUp::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('UNPAID', $topup->status);

        $this->actingAs($user)
            ->post(route('buyer.wallet.topup.simulate-pay', $topup->topup_code))
            ->assertRedirect(route('buyer.wallet'));

        // Idempotency replay check on settleTopUp -> MUST NOT double credit!
        app(WalletTopUpService::class)->settleTopUp($topup->topup_code, 'REPLAY-REF-001');

        $wallet = $user->wallet->fresh();
        $this->assertSame('200000.00', (string) $wallet->available_balance);
        $this->assertSame('200000.00', (string) $wallet->non_withdrawable_portion);

        // 2. Attempting withdrawal of Top-Up funds MUST throw AntiGestunWithdrawalException!
        try {
            app(OrderStateAndSlaService::class)->requestWithdrawal(
                userId: $user->id,
                bankName: 'BCA',
                accountNumber: '5511223344',
                accountHolder: 'ANTI GESTUN BUYER',
                amount: 50000.00,
            );
            $this->fail('Expected AntiGestunWithdrawalException for Top-Up fund withdrawal.');
        } catch (AntiGestunWithdrawalException $e) {
            $this->assertStringContainsString('top-up', strtolower($e->getMessage()));
        }

        // 3. Shop Rp 120.000 using wallet_full -> non_withdrawable_portion erodes to exact Rp 80.000!
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $game = Game::firstOrFail();
        $cat = GameCategory::create([
            'game_id' => $game->id,
            'name' => 'Gestun Test Manual',
            'slug' => 'gestun-test-manual',
            'delivery_method' => 'manual_trade',
            'platform_fee_percent' => '5.00',
            'escrow_hold_hours' => 24,
            'buyer_input_schema' => [],
        ]);
        $prod120k = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $cat->id,
            'sku_code' => 'GESTUN-SHOP-120K',
            'title' => 'Item Belanja Penggerus Top-Up Rp 120.000',
            'slug' => 'item-belanja-penggerus-topup-120k',
            'description' => 'Produk uji penggerusan non_withdrawable_portion otomatis saat checkout.',
            'price' => '120000.00',
            'stock' => 10,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 10,
            'is_active' => true,
        ]);

        app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $user->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [['product_id' => $prod120k->id, 'quantity' => 1]],
            clearCart: false,
        );

        $wallet->refresh();
        $this->assertSame('80000.00', (string) $wallet->available_balance);
        $this->assertSame('80000.00', (string) $wallet->non_withdrawable_portion);

        // 4. Global ledger reconciliation MUST remain BALANCED!
        $this->artisan('nexatrade:audit-ledger', ['--triggered-by' => 'fase5_topup'])
            ->assertExitCode(0);
        $latest = LedgerAuditSnapshot::orderByDesc('id')->firstOrFail();
        $this->assertSame('BALANCED', $latest->status);
        $this->assertSame('0.00', (string) $latest->discrepancy_amount);
    }

    public function test_cross_user_bank_account_anti_tuyul_blind_hash_lock(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $bankService = app(BankAccountService::class);

        // 1. User A registers BCA 7711223344
        $accA = $bankService->addBankAccount($buyer1->id, [
            'bank_code' => 'BCA',
            'account_number' => '7711223344',
            'account_holder_name' => 'BAGAS BUYER',
            'is_primary' => true,
        ]);
        $this->assertSame(64, strlen((string) $accA->account_blind_hash));
        $this->assertSame($accA->account_blind_hash, $accA->active_account_lock_hash);

        // 2. User B tries registering the exact same BCA 7711223344 -> MUST throw DuplicateBankAccountException!
        try {
            $bankService->addBankAccount($buyer2->id, [
                'bank_code' => 'BCA',
                'account_number' => '7711-2233-44',
                'account_holder_name' => 'FAJAR TUYUL',
                'is_primary' => true,
            ]);
            $this->fail('Expected DuplicateBankAccountException when another user registers an active bank account.');
        } catch (DuplicateBankAccountException $e) {
            $this->assertStringContainsString('akun nexatrade lain', strtolower($e->getMessage()));
        }

        // 3. User A deletes the account -> active_account_lock_hash becomes NULL -> User B is now allowed!
        $bankService->deleteBankAccount($buyer1->id, $accA->id);

        $deletedA = UserBankAccount::withTrashed()->findOrFail($accA->id);
        $this->assertNotNull($deletedA->deleted_at);
        $this->assertNull($deletedA->active_account_lock_hash);

        $accB = $bankService->addBankAccount($buyer2->id, [
            'bank_code' => 'BCA',
            'account_number' => '7711223344',
            'account_holder_name' => 'FAJAR LEGIT',
            'is_primary' => true,
        ]);
        $this->assertSame($buyer2->id, (int) $accB->user_id);
        $this->assertSame($accA->account_blind_hash, $accB->active_account_lock_hash);
    }

    public function test_self_service_forgot_password_reset_wallet_pin_unlock_and_light_mode_smoke(): void
    {
        config(['nexatrade.demo_switcher_enabled' => true]);

        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        $recoveryService = app(AccountRecoveryService::class);
        $pinService = app(WalletPinService::class);

        // 1. Self-Service Forgot & Reset Password
        $pwdReq = $recoveryService->requestPasswordReset($buyer1->email);
        $this->assertNotNull($pwdReq['preview_token']);

        // Wrong token rejected
        try {
            $recoveryService->resetPasswordWithToken($buyer1->email, 'WRONG-TOKEN', 'NewStrongPass#2026');
            $this->fail('Expected invalid password reset token to be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('tidak valid', strtolower($e->getMessage()));
        }

        // Valid token resets password cleanly
        $this->post(route('password.update'), [
            'token' => $pwdReq['preview_token'],
            'email' => $buyer1->email,
            'password' => 'NewStrongPass#2026',
            'password_confirmation' => 'NewStrongPass#2026',
        ])->assertRedirect(route('login'))->assertSessionHas('success');

        $this->assertTrue(Hash::check('NewStrongPass#2026', $buyer1->fresh()->password));

        // 2. Self-Service Wallet PIN 6-Digit Reset while PIN is LOCKED (5x failed attempts)
        $pinService->setPin($seller1->id, '847291', 'password123');
        for ($i = 0; $i < 5; $i++) {
            try {
                $pinService->verifyPinOrFail($seller1->id, '999999', true);
            } catch (DomainException) {
                // expected 5x lock progression
            }
        }
        $this->assertTrue($seller1->fresh()->isWalletPinLocked());

        // Request PIN reset token via HTTP route
        $pinReqResp = $this->actingAs($seller1)->post(route('account.security.pin-reset.request'), [
            'current_password' => 'password123',
        ]);
        $pinReqResp->assertRedirect()->assertSessionHas('pin_reset_preview_token');
        $plainPinToken = (string) session('pin_reset_preview_token');
        $this->assertNotSame('', $plainPinToken);

        // Weak PIN (123456) rejected even with valid token
        try {
            $recoveryService->resetWalletPinWithToken($seller1->id, $plainPinToken, '123456');
            $this->fail('Expected weak PIN 123456 to be rejected during PIN reset.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('lemah', strtolower($e->getMessage()));
        }

        // Expired token rejected
        $expiredIssue = $recoveryService->requestWalletPinReset($seller1->id, 'password123');
        $expiredHash = hash('sha256', (string) $expiredIssue['preview_token']);
        WalletPinResetToken::where('token_hash', $expiredHash)->update(['expires_at' => now()->subMinutes(2)]);
        try {
            $recoveryService->resetWalletPinWithToken($seller1->id, (string) $expiredIssue['preview_token'], '739154');
            $this->fail('Expected expired PIN reset token to be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('kedaluwarsa', strtolower($e->getMessage()));
        }

        // Valid token sets strong new PIN, clears lockout, and marks token used_at!
        $this->actingAs($seller1)->post(route('account.security.pin-reset.confirm'), [
            'token' => $plainPinToken,
            'new_pin' => '739154',
            'new_pin_confirmation' => '739154',
        ])->assertRedirect(route('account.security'))->assertSessionHas('success');

        $seller1->refresh();
        $this->assertNull($seller1->wallet_pin_locked_until);
        $this->assertSame(0, (int) $seller1->wallet_pin_attempts);
        $pinService->verifyPinOrFail($seller1->id, '739154', true);

        $usedRecord = WalletPinResetToken::where('token_hash', hash('sha256', $plainPinToken))->firstOrFail();
        $this->assertNotNull($usedRecord->used_at);

        // Single-use replay of the exact same token MUST be rejected!
        try {
            $recoveryService->resetWalletPinWithToken($seller1->id, $plainPinToken, '618293');
            $this->fail('Expected single-use PIN reset token replay to be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('sudah pernah digunakan', strtolower($e->getMessage()));
        }

        // 3. Light Mode UI Smoke Checks across all Phase 5 pages
        auth()->logout();
        $this->get(route('login'))->assertOk()->assertSee('Lupa Password?');
        $this->get(route('password.request'))->assertOk()->assertSee('Pulihkan Kata Sandi Akun');
        $this->actingAs($buyer1)->get(route('buyer.wallet'))->assertOk()->assertSee('Top-Up Saldo Dompet');
        $this->actingAs($seller1)->get(route('seller.ad-credits'))->assertOk()->assertSee('Pembeli Unik Terverifikasi');
        $this->actingAs($seller1)->get(route('account.security'))->assertOk()->assertSee('Lupa PIN Dompet? Ajukan Reset Token');
        $this->actingAs($admin)->get(route('admin.disputes'))->assertOk()->assertSee('Split Settlement (Putusan Parsial)');
    }

    private function createCompletedOrderFixture(int $buyerId, int $sellerId, float $grossAmount): Order
    {
        $invoice = PaymentInvoice::create([
            'invoice_code' => 'INV-AG-'.strtoupper(Str::random(10)),
            'buyer_id' => $buyerId,
            'subtotal_amount' => number_format($grossAmount, 2, '.', ''),
            'platform_discount_total' => '0.00',
            'seller_discount_total' => '0.00',
            'wallet_used_amount' => number_format($grossAmount, 2, '.', ''),
            'gateway_fee' => '0.00',
            'grand_total' => '0.00',
            'payment_channel' => 'wallet_full',
            'status' => 'PAID',
            'paid_at' => now()->subMinutes(10),
            'expires_at' => now()->addHour(),
        ]);

        return Order::create([
            'order_code' => 'ORD-AG-'.strtoupper(Str::random(10)),
            'payment_invoice_id' => $invoice->id,
            'buyer_id' => $buyerId,
            'seller_id' => $sellerId,
            'delivery_method' => 'manual_trade',
            'gross_amount' => number_format($grossAmount, 2, '.', ''),
            'seller_discount_amount' => '0.00',
            'platform_discount_amount' => '0.00',
            'buyer_payable_amount' => number_format($grossAmount, 2, '.', ''),
            'platform_commission' => number_format(round($grossAmount * 0.05, 2), 2, '.', ''),
            'seller_escrow_amount' => number_format(round($grossAmount * 0.95, 2), 2, '.', ''),
            'status' => 'COMPLETED',
            'sla_target_minutes_snapshot' => 10,
            'delivered_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }
}
