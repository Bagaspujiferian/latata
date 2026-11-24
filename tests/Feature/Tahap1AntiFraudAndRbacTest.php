<?php

namespace Tests\Feature;

use App\Enums\AdminSubrole;
use App\Enums\VerificationChannel;
use App\Enums\WithdrawalApprovalStage;
use App\Exceptions\BankNameMismatchException;
use App\Exceptions\DuplicatePhoneNumberException;
use App\Exceptions\UnverifiedAccountPromoException;
use App\Models\FlashSaleItem;
use App\Models\Product;
use App\Models\SellerKycVerification;
use App\Models\User;
use App\Models\UserVerificationChallenge;
use App\Models\Voucher;
use App\Models\Wallet;
use App\Models\WithdrawalApproval;
use App\Models\WithdrawalRequest;
use App\Services\BankAccountService;
use App\Services\CheckoutSplitOrderService;
use App\Services\FinanceAndLedgerAuditService;
use App\Services\OrderStateAndSlaService;
use App\Services\UserVerificationService;
use App\Services\WithdrawalApprovalService;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Tahap1AntiFraudAndRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_otp_challenge_lifecycle_and_5_attempt_lockout(): void
    {
        $verificationService = app(UserVerificationService::class);

        $user = User::create([
            'name' => 'Unverified Challenge User',
            'email' => 'challenge.user@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $user->id]);

        $plainOtp = $verificationService->issueChallenge($user, VerificationChannel::EMAIL);
        $this->assertSame(6, strlen($plainOtp));

        // 5 wrong attempts increment attempts from 0 -> 5
        for ($i = 1; $i <= 5; $i++) {
            try {
                $verificationService->verifyChallenge($user, VerificationChannel::EMAIL, '000000');
                $this->fail('Expected DomainException on wrong OTP code.');
            } catch (DomainException) {
                $challenge = UserVerificationChallenge::where('user_id', $user->id)
                    ->where('channel', VerificationChannel::EMAIL->value)
                    ->orderByDesc('id')
                    ->firstOrFail();
                $this->assertSame($i, $challenge->attempts);
            }
        }

        // 6th attempt even with the CORRECT OTP must fail because max_attempts (5) is exhausted
        try {
            $verificationService->verifyChallenge($user, VerificationChannel::EMAIL, $plainOtp);
            $this->fail('Expected DomainException when challenge has exhausted 5 attempts.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('5', $e->getMessage());
        }

        $this->assertNull($user->refresh()->email_verified_at);

        // Issue a fresh OTP challenge -> invalidates old challenge and allows verification
        $newOtp = $verificationService->issueChallenge($user, VerificationChannel::EMAIL);
        $verificationService->verifyChallenge($user, VerificationChannel::EMAIL, $newOtp);

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);

        $latestChallenge = UserVerificationChallenge::where('user_id', $user->id)
            ->where('channel', VerificationChannel::EMAIL->value)
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertNotNull($latestChallenge->consumed_at);
    }

    public function test_unique_phone_blind_hash_blocks_duplicate_tuyul_across_formats(): void
    {
        $verificationService = app(UserVerificationService::class);

        $userA = User::create([
            'name' => 'Tuyul Hunter A',
            'email' => 'hunter.a@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);
        $userB = User::create([
            'name' => 'Tuyul Clone B',
            'email' => 'hunter.b@nexatrade.id',
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);

        // User A binds 0812-9988-7766 and verifies OTP
        $canonical = $verificationService->bindPhoneNumber($userA, '0812-9988-7766');
        $this->assertSame('6281299887766', $canonical);

        $phoneOtp = $verificationService->issueChallenge($userA, VerificationChannel::PHONE);
        $verificationService->verifyChallenge($userA, VerificationChannel::PHONE, $phoneOtp);
        $this->assertNotNull($userA->refresh()->phone_verified_at);

        // User B attempts to bind +62 812 9988 7766 -> MUST throw DuplicatePhoneNumberException
        $this->expectException(DuplicatePhoneNumberException::class);
        $verificationService->bindPhoneNumber($userB, '+62 812 9988 7766');
    }

    public function test_unverified_account_blocked_from_platform_voucher_and_flash_sale_but_allowed_regular_purchase(): void
    {
        $checkoutService = app(CheckoutSplitOrderService::class);

        $unverifiedBuyer = User::create([
            'name' => 'Unverified Promo Buyer',
            'email' => 'unverified.promo@nexatrade.id',
            'email_verified_at' => now(),
            'phone_verified_at' => null, // Phone NOT verified yet!
            'password' => Hash::make('password123'),
            'role' => 'buyer',
            'is_active' => true,
        ]);
        Wallet::create([
            'user_id' => $unverifiedBuyer->id,
            'available_balance' => '500000.00',
            'pending_balance' => '0.00',
        ]);

        $flashProductIds = FlashSaleItem::pluck('product_id')->all();
        $regularVaultProduct = Product::whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->whereNotIn('id', $flashProductIds)
            ->where('stock', '>', 0)
            ->firstOrFail();

        // 1. Regular purchase without Platform Voucher or Flash Sale SUCCEEDS for unverified account
        $regularInvoice = $checkoutService->checkout(
            buyerId: $unverifiedBuyer->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $regularVaultProduct->id, 'quantity' => 1]],
            clearCart: false,
        );
        $this->assertSame('UNPAID', $regularInvoice->status);

        // 2. Attempting checkout with Platform Voucher THROWS UnverifiedAccountPromoException
        $platformVoucher = Voucher::where('issuer_type', 'platform')->where('is_active', true)->firstOrFail();
        try {
            $checkoutService->checkout(
                buyerId: $unverifiedBuyer->id,
                paymentMode: 'gateway_only',
                paymentChannel: 'qris',
                explicitItems: [['product_id' => $regularVaultProduct->id, 'quantity' => 1]],
                clearCart: false,
                platformVoucherCode: $platformVoucher->code,
            );
            $this->fail('Expected UnverifiedAccountPromoException when using Platform Voucher without verified phone.');
        } catch (UnverifiedAccountPromoException) {
            $this->assertTrue(true);
        }

        // 3. Attempting checkout on an active Flash Sale item THROWS UnverifiedAccountPromoException
        $flashItem = FlashSaleItem::with('product')->firstOrFail();
        try {
            $checkoutService->checkout(
                buyerId: $unverifiedBuyer->id,
                paymentMode: 'gateway_only',
                paymentChannel: 'qris',
                explicitItems: [[
                    'product_id' => $flashItem->product_id,
                    'quantity' => 1,
                    'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041'],
                    'verified_game_nickname' => 'UnverifiedFlashBuyer',
                ]],
                clearCart: false,
            );
            $this->fail('Expected UnverifiedAccountPromoException when purchasing Flash Sale item without verified phone.');
        } catch (UnverifiedAccountPromoException) {
            $this->assertTrue(true);
        }

        // 4. Once phone OTP is verified, BOTH Platform Voucher and Flash Sale checkouts SUCCEED!
        $verificationService = app(UserVerificationService::class);
        $verificationService->bindPhoneNumber($unverifiedBuyer, '081377889900');
        $otp = $verificationService->issueChallenge($unverifiedBuyer, VerificationChannel::PHONE);
        $verificationService->verifyChallenge($unverifiedBuyer, VerificationChannel::PHONE, $otp);
        $this->assertTrue($unverifiedBuyer->refresh()->isFullyVerifiedForPromo());

        $promoInvoice = $checkoutService->checkout(
            buyerId: $unverifiedBuyer->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [
                ['product_id' => $regularVaultProduct->id, 'quantity' => 1],
                [
                    'product_id' => $flashItem->product_id,
                    'quantity' => 1,
                    'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041'],
                    'verified_game_nickname' => 'VerifiedPromoBuyer',
                ],
            ],
            clearCart: false,
            platformVoucherCode: $platformVoucher->code,
        );
        $this->assertSame('PAID', $promoInvoice->status);
        $this->assertGreaterThan(0.0, (float) $promoInvoice->platform_discount_total);
    }

    public function test_granular_admin_rbac_blocks_cross_domain_actions_with_403(): void
    {
        $kycAdmin = User::create([
            'name' => 'KYC Officer Admin',
            'email' => 'kyc.officer@nexatrade.id',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'admin_subrole' => AdminSubrole::KYC_OFFICER,
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $kycAdmin->id]);

        $financeAdmin = User::create([
            'name' => 'Finance Officer Admin',
            'email' => 'finance.officer@nexatrade.id',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'admin_subrole' => AdminSubrole::FINANCE,
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $financeAdmin->id]);

        $csAdmin = User::create([
            'name' => 'CS Arbitrator Admin',
            'email' => 'cs.arbitrator@nexatrade.id',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'admin_subrole' => AdminSubrole::CS_ARBITRATOR,
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $csAdmin->id]);

        $pendingWd = WithdrawalRequest::where('status', 'PENDING')->firstOrFail();

        // KYC Officer can access /admin/kyc (200), but gets 403 on Withdrawals, Disputes, Finance, CMS, and Chaos Lab
        $this->actingAs($kycAdmin)->get(route('admin.kyc.index'))->assertOk();
        $this->actingAs($kycAdmin)->get(route('admin.withdrawals'))->assertForbidden();
        $this->actingAs($kycAdmin)->post(route('admin.withdrawals.approve', $pendingWd->id))->assertForbidden();
        $this->actingAs($kycAdmin)->get(route('admin.disputes'))->assertForbidden();
        $this->actingAs($kycAdmin)->get(route('admin.finance.index'))->assertForbidden();
        $this->actingAs($kycAdmin)->get(route('admin.cms.catalog'))->assertForbidden();
        $this->actingAs($kycAdmin)->get(route('admin.chaos-lab'))->assertForbidden();

        // Finance Officer can access /admin/finance & /admin/withdrawals (200), but gets 403 on KYC, Disputes, CMS, and Chaos Lab
        $this->actingAs($financeAdmin)->get(route('admin.finance.index'))->assertOk();
        $this->actingAs($financeAdmin)->get(route('admin.withdrawals'))->assertOk();
        $this->actingAs($financeAdmin)->get(route('admin.kyc.index'))->assertForbidden();
        $this->actingAs($financeAdmin)->get(route('admin.disputes'))->assertForbidden();
        $this->actingAs($financeAdmin)->get(route('admin.cms.catalog'))->assertForbidden();
        $this->actingAs($financeAdmin)->get(route('admin.chaos-lab'))->assertForbidden();

        // CS Arbitrator can access /admin/disputes & /admin/warranties (200), but gets 403 on Withdrawals, Finance, KYC, CMS, and Chaos Lab
        $this->actingAs($csAdmin)->get(route('admin.disputes'))->assertOk();
        $this->actingAs($csAdmin)->get(route('admin.warranties.index'))->assertOk();
        $this->actingAs($csAdmin)->get(route('admin.reports.index'))->assertOk();
        $this->actingAs($csAdmin)->get(route('admin.withdrawals'))->assertForbidden();
        $this->actingAs($csAdmin)->get(route('admin.finance.index'))->assertForbidden();
        $this->actingAs($csAdmin)->get(route('admin.kyc.index'))->assertForbidden();
        $this->actingAs($csAdmin)->get(route('admin.chaos-lab'))->assertForbidden();
    }

    public function test_single_step_withdrawal_below_threshold_approves_immediately(): void
    {
        $approvalService = app(WithdrawalApprovalService::class);
        $superAdmin = User::where('email', 'admin@nexatrade.id')->firstOrFail();

        // Seeded pending withdrawal is Rp 500.000 (< Rp 5.000.000 threshold)
        $pendingWd = WithdrawalRequest::where('status', 'PENDING')->firstOrFail();
        $this->assertLessThan(5000000.00, (float) $pendingWd->amount);

        $approved = $approvalService->approve($pendingWd->id, $superAdmin->id);

        $this->assertSame('APPROVED_PAID', $approved->status);
        $this->assertSame($superAdmin->id, (int) $approved->processed_by);
        $this->assertNotNull($approved->processed_at);

        $this->assertDatabaseHas('withdrawal_approvals', [
            'withdrawal_id' => $pendingWd->id,
            'admin_id' => $superAdmin->id,
            'stage' => WithdrawalApprovalStage::MAKER->value,
            'decision' => 'approved',
        ]);
        $this->assertSame(1, WithdrawalApproval::where('withdrawal_id', $pendingWd->id)->count());
    }

    public function test_dual_approval_maker_checker_enforces_two_distinct_finance_admins_for_large_withdrawals(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $superAdmin = User::where('email', 'admin@nexatrade.id')->firstOrFail();

        $financeAdmin1 = User::create([
            'name' => 'Finance Maker Admin',
            'email' => 'finance.maker@nexatrade.id',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'admin_subrole' => AdminSubrole::FINANCE,
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $financeAdmin1->id]);

        $largeWd = WithdrawalRequest::create([
            'withdraw_code' => 'WD-LARGE-6M-TEST',
            'user_id' => $seller1->id,
            'bank_name' => 'BCA',
            'account_number' => '8830192841',
            'account_holder' => 'RAKA PRATAMA',
            'amount' => '6000000.00',
            'admin_fee' => '2500.00',
            'net_payout' => '5997500.00',
            'status' => 'PENDING',
        ]);

        // Stage 1 (Maker): Finance Admin 1 approves -> transitions PENDING to MAKER_APPROVED
        $this->actingAs($financeAdmin1)
            ->post(route('admin.withdrawals.approve', $largeWd->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $largeWd->refresh();
        $this->assertSame('MAKER_APPROVED', $largeWd->status);
        $this->assertDatabaseHas('withdrawal_approvals', [
            'withdrawal_id' => $largeWd->id,
            'admin_id' => $financeAdmin1->id,
            'stage' => 'maker',
            'decision' => 'approved',
        ]);

        // Same admin (Finance Admin 1) attempting Stage 2 Checker approval -> MUST be blocked with HTTP 403!
        $this->actingAs($financeAdmin1)
            ->post(route('admin.withdrawals.approve', $largeWd->id))
            ->assertForbidden();

        $this->assertSame('MAKER_APPROVED', $largeWd->refresh()->status);
        $this->assertSame(1, WithdrawalApproval::where('withdrawal_id', $largeWd->id)->count());

        // Stage 2 (Checker): Different admin (Super Admin) approves -> transitions MAKER_APPROVED to APPROVED_PAID
        $this->actingAs($superAdmin)
            ->post(route('admin.withdrawals.approve', $largeWd->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $largeWd->refresh();
        $this->assertSame('APPROVED_PAID', $largeWd->status);
        $this->assertNotNull($largeWd->processed_at);
        $this->assertSame(2, WithdrawalApproval::where('withdrawal_id', $largeWd->id)->count());
        $this->assertDatabaseHas('withdrawal_approvals', [
            'withdrawal_id' => $largeWd->id,
            'admin_id' => $superAdmin->id,
            'stage' => 'checker',
            'decision' => 'approved',
        ]);
    }

    public function test_maker_approved_withdrawal_can_be_rejected_by_checker_and_refunds_wallet_atomically(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $superAdmin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $orderStateService = app(OrderStateAndSlaService::class);
        $approvalService = app(WithdrawalApprovalService::class);

        $financeAdmin1 = User::create([
            'name' => 'Finance Maker 2',
            'email' => 'finance.maker2@nexatrade.id',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'admin_subrole' => AdminSubrole::FINANCE,
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $financeAdmin1->id]);

        // Lower threshold to Rp 100.000 for this test so a real ledger-backed withdrawal triggers Maker-Checker
        config(['nexatrade.withdrawal_maker_checker_threshold' => 100000.00]);

        $balanceBeforeRequest = (float) $seller1->wallet->refresh()->available_balance;
        $realLargeWd = $orderStateService->requestWithdrawal(
            userId: $seller1->id,
            amount: 150000.00,
            bankName: 'BCA',
            accountNumber: '8830192841',
            accountHolder: 'RAKA PRATAMA',
        );

        $this->assertEqualsWithDelta(
            $balanceBeforeRequest - 150000.00,
            (float) $seller1->wallet->refresh()->available_balance,
            0.01,
        );

        // Stage 1 Maker approves -> MAKER_APPROVED
        $afterMaker = $approvalService->approve($realLargeWd->id, $financeAdmin1->id);
        $this->assertSame('MAKER_APPROVED', $afterMaker->status);

        // Stage 2 Checker rejects -> REJECTED_REFUNDED and refunds Rp 150.000 atomically
        $afterReject = $approvalService->reject($realLargeWd->id, $superAdmin->id, 'Audit rekening tahap 2 menemukan ketidaksesuaian');
        $this->assertSame('REJECTED_REFUNDED', $afterReject->status);

        $this->assertEqualsWithDelta(
            $balanceBeforeRequest,
            (float) $seller1->wallet->refresh()->available_balance,
            0.01,
        );

        // Second approval attempt after rejection must throw DomainException
        try {
            $approvalService->approve($realLargeWd->id, $superAdmin->id);
            $this->fail('Expected DomainException when approving an already rejected withdrawal.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        // Global Ledger Audit MUST remain 100% BALANCED
        $snapshot = app(FinanceAndLedgerAuditService::class)->runGlobalLedgerAudit('tahap1_maker_checker_reject');
        $this->assertSame('BALANCED', $snapshot->status);
        $this->assertSame('0.00', (string) $snapshot->discrepancy_amount);
    }

    public function test_kyc_approved_user_cannot_add_bank_account_with_mismatched_holder_name(): void
    {
        $bankAccountService = app(BankAccountService::class);

        $user = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi.kyc@nexatrade.id',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'password' => Hash::make('password123'),
            'role' => 'seller',
            'is_active' => true,
        ]);
        Wallet::create(['user_id' => $user->id]);

        SellerKycVerification::create([
            'user_id' => $user->id,
            'proposed_store_name' => 'Budi Gaming Store',
            'proposed_store_slug' => 'budi-gaming-store',
            'full_legal_name' => 'Budi Santoso, S.Kom.',
            'nik_encrypted' => Crypt::encryptString('3273011205990001'),
            'nik_last4' => '0001',
            'nik_blind_hash' => hash_hmac('sha256', '3273011205990001', (string) config('app.key')),
            'id_card_private_path' => 'kyc/dummy-ktp.jpg',
            'selfie_private_path' => 'kyc/dummy-selfie.jpg',
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        // 1. Mismatched holder name ("Andi Wijaya") MUST throw BankNameMismatchException
        try {
            $bankAccountService->addBankAccount($user->id, [
                'bank_code' => 'BCA',
                'account_number' => '7720193847',
                'account_holder_name' => 'Andi Wijaya',
            ]);
            $this->fail('Expected BankNameMismatchException when bank holder name does not match approved KYC legal name.');
        } catch (BankNameMismatchException) {
            $this->assertTrue(true);
        }

        // 2. Canonical match ("BUDI SANTOSO" vs "Budi Santoso, S.Kom.") MUST succeed
        $account = $bankAccountService->addBankAccount($user->id, [
            'bank_code' => 'BCA',
            'account_number' => '7720193847',
            'account_holder_name' => 'BUDI SANTOSO',
        ]);

        $this->assertSame('BUDI SANTOSO', $account->account_holder_name);
        $this->assertSame($user->id, (int) $account->user_id);
    }
}
