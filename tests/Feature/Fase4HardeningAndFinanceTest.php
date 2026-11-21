<?php

namespace Tests\Feature;

use App\Exceptions\WalletPinLockedException;
use App\Models\AdCreditPackage;
use App\Models\LedgerAuditSnapshot;
use App\Models\Order;
use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\Wallet;
use App\Services\CheckoutSplitOrderService;
use App\Services\FinanceAndLedgerAuditService;
use App\Services\WalletPinService;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class Fase4HardeningAndFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_production_auth_register_login_logout_and_demo_switcher_404_guard(): void
    {
        // 1. Register new user -> auto-creates Wallet with 0.00 balances & logs in
        $regResponse = $this->post(route('register.submit'), [
            'name' => 'Rizky Production User',
            'email' => 'rizky.prod@nexatrade.id',
            'password' => 'StrongPass#2026',
            'password_confirmation' => 'StrongPass#2026',
        ]);
        $regResponse->assertRedirect(route('marketplace.index'));

        $newUser = User::where('email', 'rizky.prod@nexatrade.id')->firstOrFail();
        $this->assertAuthenticatedAs($newUser);
        $this->assertDatabaseHas('wallets', [
            'user_id' => $newUser->id,
            'pending_balance' => '0.00',
            'available_balance' => '0.00',
            'security_deposit_balance' => '0.00',
            'clawback_debt_balance' => '0.00',
        ]);

        // 2. Logout
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // 3. Login with real credentials
        $this->post(route('login.submit'), [
            'email' => 'rizky.prod@nexatrade.id',
            'password' => 'StrongPass#2026',
        ])->assertRedirect(route('marketplace.index'));
        $this->assertAuthenticatedAs($newUser);

        // 4. Production Demo Switcher Guard: when disabled, Demo routes return HTTP 404 & widget is hidden!
        config(['nexatrade.demo_switcher_enabled' => false]);

        $this->post(route('demo.switch-user'), ['email' => 'admin@nexatrade.id'])->assertNotFound();
        $this->post(route('demo.wallet-topup'), ['amount' => 100000])->assertNotFound();

        $this->get(route('marketplace.index'))
            ->assertOk()
            ->assertDontSee('Demo Account Switcher');
    }

    public function test_wallet_pin_6_digit_weak_pin_rejection_5x_lockout_and_withdrawal_enforcement(): void
    {
        $seller = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $bank = UserBankAccount::where('user_id', $seller->id)->firstOrFail();
        $pinService = app(WalletPinService::class);

        // 1. Weak PIN must be rejected
        try {
            $pinService->setPin($seller->id, '123456', 'password123');
            $this->fail('Expected weak PIN 123456 to be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('lemah', strtolower($e->getMessage()));
        }

        // 2. Set strong 6-digit PIN via Security route
        $this->actingAs($seller)->post(route('account.security.pin'), [
            'current_password' => 'password123',
            'pin' => '847291',
            'pin_confirmation' => '847291',
        ])->assertRedirect();

        $seller->refresh();
        $this->assertTrue($seller->hasWalletPin());
        $this->assertSame(0, $seller->wallet_pin_attempts);

        // 3. Withdrawal without PIN or with wrong PIN fails and increments wallet_pin_attempts; 5th attempt locks for 15 min
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($seller)->post(route('seller.wallet.withdraw'), [
                'user_bank_account_id' => $bank->id,
                'amount' => 15000,
                'wallet_pin' => $attempt === 1 ? '' : '999999',
            ])->assertRedirect()->assertSessionHas('error');

            $seller->refresh();
            $this->assertSame($attempt, $seller->wallet_pin_attempts);
        }

        $this->assertNotNull($seller->wallet_pin_locked_until);
        $this->assertTrue($seller->isWalletPinLocked());

        // 4. Even the CORRECT PIN is rejected while lockout is active
        try {
            $pinService->verifyPinOrFail($seller->id, '847291', true);
            $this->fail('Expected WalletPinLockedException while locked.');
        } catch (WalletPinLockedException $e) {
            $this->assertStringContainsString('terkunci', strtolower($e->getMessage()));
        }

        // 5. After lockout expires, withdrawal WITH correct PIN succeeds and resets attempts to 0
        DB::table('users')->where('id', $seller->id)->update([
            'wallet_pin_locked_until' => now()->subMinute(),
        ]);

        $beforeCount = DB::table('withdrawal_requests')->where('user_id', $seller->id)->count();
        $this->actingAs($seller)->post(route('seller.wallet.withdraw'), [
            'user_bank_account_id' => $bank->id,
            'amount' => 15000,
            'wallet_pin' => '847291',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame($beforeCount + 1, DB::table('withdrawal_requests')->where('user_id', $seller->id)->count());
        $this->assertSame(0, $seller->fresh()->wallet_pin_attempts);
    }

    public function test_rate_limiter_triggers_429_on_voucher_preview_and_nickname_lookup_caches(): void
    {
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();

        // 1. Rate Limiter on voucher-preview (limit = 20/min per user)
        RateLimiter::clear('voucher-preview|u:'.$buyer->id);
        $got429 = false;
        for ($i = 1; $i <= 22; $i++) {
            $resp = $this->actingAs($buyer)->postJson(route('checkout.voucher-preview'), [
                'code' => 'BRUTEFORCE'.$i,
            ]);
            if ($resp->status() === 429) {
                $got429 = true;
                break;
            }
        }
        $this->assertTrue($got429, 'Expected HTTP 429 Too Many Requests when brute-forcing voucher-preview.');

        // 2. GameNicknameService 15-minute Cache (1st miss = cached:false, 2nd hit = cached:true)
        Cache::flush();

        $first = $this->postJson(route('api.game.verify-nickname'), [
            'game_slug' => 'mobile-legends',
            'inputs' => ['user_id' => '123456789', 'zone_id' => '2041'],
        ]);
        $first->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('nickname', 'EVOS•KaguraID')
            ->assertJsonPath('cached', false);

        $second = $this->postJson(route('api.game.verify-nickname'), [
            'game_slug' => 'mobile-legends',
            'inputs' => ['user_id' => '123456789', 'zone_id' => '2041'],
        ]);
        $second->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('nickname', 'EVOS•KaguraID')
            ->assertJsonPath('cached', true);
    }

    public function test_h2h_reconciliation_worker_resolves_stale_processing_orders_preserving_ref_id(): void
    {
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $mlbbProduct = Product::where('sku_code', 'MLBB-DM-257')->firstOrFail();

        // Create 2 H2H orders stuck in PROCESSING: one resolves 'sukses', one resolves 'gagal'
        $invSuccess = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [[
                'product_id' => $mlbbProduct->id,
                'quantity' => 1,
                'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041'],
                'verified_game_nickname' => 'EVOS•KaguraID',
            ]],
            clearCart: false,
        );
        $ordSuccess = $invSuccess->orders()->firstOrFail();
        $origRefSuccess = $ordSuccess->h2h_reference_id;
        $this->assertNotNull($origRefSuccess);
        $this->assertSame('PROCESSING', $ordSuccess->status);

        $invFail = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [[
                'product_id' => $mlbbProduct->id,
                'quantity' => 1,
                'buyer_game_inputs' => ['user_id' => '999999999', 'zone_id' => '2041'],
                'verified_game_nickname' => 'FailCheckUser',
            ]],
            clearCart: false,
        );
        $ordFail = $invFail->orders()->firstOrFail();
        $origRefFail = $ordFail->h2h_reference_id;
        $buyerBeforeFailRefund = (float) $buyer->wallet->fresh()->available_balance;

        // Backdate both orders > 5 minutes so reconcile-h2h picks them up
        DB::table('orders')->whereIn('id', [$ordSuccess->id, $ordFail->id])->update([
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->artisan('nexatrade:reconcile-h2h', ['--minutes' => 5])
            ->assertExitCode(0);

        $ordSuccess->refresh();
        $ordFail->refresh();

        // Exact same h2h_reference_id preserved (no duplicate top-up ref_id!)
        $this->assertSame($origRefSuccess, $ordSuccess->h2h_reference_id);
        $this->assertSame('DELIVERED', $ordSuccess->status);
        $this->assertSame("SN-RECON-{$origRefSuccess}", $ordSuccess->seller_delivery_proof['serial_number'] ?? null);

        $this->assertSame($origRefFail, $ordFail->h2h_reference_id);
        $this->assertSame('REFUNDED', $ordFail->status);
        $this->assertEqualsWithDelta(
            $buyerBeforeFailRefund + (float) $ordFail->buyer_payable_amount,
            (float) $buyer->wallet->fresh()->available_balance,
            0.01,
        );
    }

    public function test_clawback_debt_repayment_and_auto_unsuspend_via_wallet_and_gateway(): void
    {
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        // Case 1: Seller 2 repays 100k debt from available_balance -> debt becomes 0 & store auto-unsuspends!
        DB::table('wallets')->where('user_id', $seller2->id)->update([
            'clawback_debt_balance' => '100000.00',
        ]);
        DB::table('seller_profiles')->where('user_id', $seller2->id)->update([
            'is_suspended' => true,
            'suspension_reason' => 'Tunggakan klaim garansi hackback Rp 100.000',
            'is_store_open' => false,
        ]);

        $availBefore = (float) $seller2->wallet->fresh()->available_balance;

        $this->actingAs($seller2)->post(route('seller.wallet.repay-debt'), [
            'amount' => 100000,
        ])->assertRedirect()->assertSessionHas('success');

        $w2 = $seller2->wallet->fresh();
        $p2 = $seller2->sellerProfile->fresh();
        $this->assertSame('0.00', (string) $w2->clawback_debt_balance);
        $this->assertEqualsWithDelta($availBefore - 100000.00, (float) $w2->available_balance, 0.01);
        $this->assertFalse((bool) $p2->is_suspended);
        $this->assertNull($p2->suspension_reason);
        $this->assertTrue((bool) $p2->is_store_open);

        $this->assertDatabaseHas('debt_repayments', [
            'seller_id' => $seller2->id,
            'amount' => '100000.00',
            'payment_source' => 'available_balance',
            'debt_after' => '0.00',
            'auto_unsuspended' => 1,
        ]);
        $this->assertDatabaseHas('wallet_mutations', [
            'user_id' => $seller2->id,
            'mutation_type' => 'SELLER_DEBT_RECOVERY',
            'direction' => 'DEBIT',
            'amount' => '100000.00',
        ]);

        // Case 2: Seller 1 repays 80k debt via direct gateway when wallet balance is untouched -> auto-unsuspends!
        DB::table('wallets')->where('user_id', $seller1->id)->update([
            'clawback_debt_balance' => '80000.00',
        ]);
        DB::table('seller_profiles')->where('user_id', $seller1->id)->update([
            'is_suspended' => true,
            'suspension_reason' => 'Tunggakan klaim garansi Rp 80.000',
            'is_store_open' => false,
        ]);

        $this->actingAs($seller1)->post(route('seller.wallet.repay-debt-gateway'), [
            'amount' => 80000,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('0.00', (string) $seller1->wallet->fresh()->clawback_debt_balance);
        $this->assertFalse((bool) $seller1->sellerProfile->fresh()->is_suspended);
        $this->assertTrue((bool) $seller1->sellerProfile->fresh()->is_store_open);
        $this->assertDatabaseHas('debt_repayments', [
            'seller_id' => $seller1->id,
            'payment_source' => 'gateway_direct',
            'auto_unsuspended' => 1,
        ]);
    }

    public function test_ad_credits_purchase_and_featured_boost_ledger_integrity(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $pkgPro = AdCreditPackage::where('code', 'PRO-30')->firstOrFail();
        $product = Product::where('seller_id', $seller1->id)->where('is_featured', false)->firstOrFail();

        $availBefore = (float) $seller1->wallet->fresh()->available_balance;
        $creditsBefore = (int) $seller1->sellerProfile->fresh()->ad_credits;

        $this->actingAs($seller1)->post(route('seller.ad-credits.purchase'), [
            'package_id' => $pkgPro->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertEqualsWithDelta($availBefore - 40000.00, (float) $seller1->wallet->fresh()->available_balance, 0.01);
        $this->assertSame($creditsBefore + 35, (int) $seller1->sellerProfile->fresh()->ad_credits);

        $this->assertDatabaseHas('ad_credit_purchases', [
            'seller_id' => $seller1->id,
            'package_id' => $pkgPro->id,
            'credits_granted' => 35,
            'amount_paid' => '40000.00',
            'status' => 'PAID',
        ]);
        $this->assertDatabaseHas('wallet_mutations', [
            'user_id' => $seller1->id,
            'mutation_type' => 'AD_CREDIT_PURCHASE',
            'direction' => 'DEBIT',
            'amount' => '40000.00',
        ]);

        // Boost product as Featured (costs 3 credits)
        $this->actingAs($seller1)->post(route('seller.ad-credits.boost', $product))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue((bool) $product->fresh()->is_featured);
        $this->assertSame($creditsBefore + 32, (int) $seller1->sellerProfile->fresh()->ad_credits);
    }

    public function test_pdf_invoice_order_receipt_and_csv_exports_with_strict_authorization(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        $invoice = PaymentInvoice::where('buyer_id', $buyer1->id)->firstOrFail();
        $warrantyOrder = Order::where('buyer_id', $buyer1->id)->whereHas('warranty')->firstOrFail();

        // Owner buyer gets real application/pdf
        $pdfResp = $this->actingAs($buyer1)->get(route('invoices.pdf', $invoice->invoice_code));
        $pdfResp->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfResp->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $pdfResp->getContent());

        // Unrelated buyer gets HTTP 403
        $this->actingAs($buyer2)->get(route('invoices.pdf', $invoice->invoice_code))->assertForbidden();

        // Admin gets HTTP 200 application/pdf
        $this->actingAs($admin)->get(route('invoices.pdf', $invoice->invoice_code))->assertOk();

        // Buyer Order Receipt & Warranty Certificate PDF
        $orderPdf = $this->actingAs($buyer1)->get(route('buyer.orders.pdf', $warrantyOrder->order_code));
        $orderPdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $orderPdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $orderPdf->getContent());

        // Seller Orders CSV & Mutations CSV
        $ordersCsv = $this->actingAs($seller1)->get(route('seller.exports.orders'));
        $ordersCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $ordersCsv->headers->get('Content-Type'));
        $ordersContent = $ordersCsv->streamedContent();
        $this->assertStringContainsString('Order Code', $ordersContent);

        $mutCsv = $this->actingAs($seller1)->get(route('seller.exports.mutations'));
        $mutCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $mutCsv->headers->get('Content-Type'));
        $mutContent = $mutCsv->streamedContent();
        $this->assertStringContainsString('Reference Code', $mutContent);
        $this->assertStringContainsString('AD_CREDIT_PURCHASE', $mutContent);
    }

    public function test_global_ledger_reconciliation_balanced_and_admin_finance_pnl_dashboard(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        // 1. Artisan Global Ledger Audit MUST return BALANCED with exact 0.00 discrepancy on seeded data!
        $this->artisan('nexatrade:audit-ledger', ['--triggered-by' => 'phpunit'])
            ->assertExitCode(0);

        $latest = LedgerAuditSnapshot::orderByDesc('id')->firstOrFail();
        $this->assertSame('BALANCED', $latest->status);
        $this->assertSame('0.00', (string) $latest->discrepancy_amount);
        $this->assertSame((string) $latest->total_custody_wallets, (string) $latest->total_ledger_net);

        // 2. Admin Finance P&L Dashboard & Action Buttons render cleanly (HTTP 200)
        $this->actingAs($admin)->get(route('admin.finance.index'))
            ->assertOk()
            ->assertSee('Keuangan Perusahaan')
            ->assertSee('BALANCED (Selisih Rp 0)')
            ->assertSee('Paket Starter Sundul');

        $this->actingAs($admin)->post(route('admin.finance.audit-ledger'))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.finance.reconcile-h2h'))->assertRedirect();

        // 3. Smoke check all other Phase 4 Light Mode pages
        $this->get(route('login'))->assertOk()->assertSee('Masuk ke Akun NexaTrade');
        $this->get(route('register'))->assertOk()->assertSee('Buat Akun NexaTrade');
        $this->actingAs($buyer1)->get(route('account.security'))->assertOk()->assertSee('PIN Dompet 6-Digit');
        $this->actingAs($seller1)->get(route('seller.ad-credits'))->assertOk()->assertSee('Paket Pro Juragan');

        // 4. Tamper Detection Proof: direct unlogged DB edit on wallet MUST be caught as DISCREPANCY!
        DB::table('wallets')->where('user_id', $buyer1->id)->increment('available_balance', 12345.00);
        $tamperAudit = app(FinanceAndLedgerAuditService::class)->runGlobalLedgerAudit('tamper_test');

        $this->assertSame('DISCREPANCY', $tamperAudit->status);
        $this->assertSame('12345.00', (string) $tamperAudit->discrepancy_amount);
        $this->assertNotEmpty($tamperAudit->discrepancy_details);
    }
}
