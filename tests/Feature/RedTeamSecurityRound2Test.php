<?php

namespace Tests\Feature;

use App\Exceptions\PriceChangedException;
use App\Exceptions\SelfDealingException;
use App\Models\AdminAuditLog;
use App\Models\CartItem;
use App\Models\GameCategory;
use App\Models\LedgerAuditSnapshot;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReview;
use App\Models\OrderWarranty;
use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Models\ProductVault;
use App\Models\SellerKycVerification;
use App\Models\User;
use App\Models\UserBankAccount;
use App\Models\WalletMutation;
use App\Models\WalletPinResetToken;
use App\Models\WarrantyClaim;
use App\Models\WithdrawalRequest;
use App\Services\CheckoutSplitOrderService;
use App\Services\MidtransWebhookService;
use App\Services\OrderStateAndSlaService;
use App\Services\VaultEncryptionService;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class RedTeamSecurityRound2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_self_dealing_blocked_on_cart_checkout_service_and_inbox(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $ownProduct = Product::where('seller_id', $seller1->id)->firstOrFail();
        $balanceBefore = (float) $seller1->wallet->fresh()->available_balance;
        $orderCountBefore = Order::count();

        // 1. Adding own product to cart is blocked
        $this->actingAs($seller1)
            ->postJson(route('cart.add'), [
                'product_id' => $ownProduct->id,
                'quantity' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame(0, CartItem::where('user_id', $seller1->id)->count());

        // 2. Even if forced into cart_items directly, checkout controller blocks it
        CartItem::create([
            'user_id' => $seller1->id,
            'product_id' => $ownProduct->id,
            'quantity' => 1,
        ]);

        $this->actingAs($seller1)
            ->postJson(route('checkout.process'), [
                'payment_mode' => 'wallet_full',
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        // 3. Defense-in-depth Service Layer blocks it directly via SelfDealingException
        try {
            app(CheckoutSplitOrderService::class)->checkout(
                buyerId: $seller1->id,
                paymentMode: 'wallet_full',
                explicitItems: [['product_id' => $ownProduct->id, 'quantity' => 1]],
                clearCart: false,
            );
            $this->fail('Expected SelfDealingException on direct service checkout of own product.');
        } catch (SelfDealingException $e) {
            $this->assertStringContainsString('toko anda sendiri', strtolower($e->getMessage()));
        }

        $this->assertSame($orderCountBefore, Order::count());
        $this->assertEqualsWithDelta($balanceBefore, (float) $seller1->wallet->fresh()->available_balance, 0.01);

        // 4. Starting an inbox conversation with oneself is blocked
        $this->actingAs($seller1)
            ->postJson(route('inbox.start'), [
                'seller_id' => $seller1->id,
                'product_id' => $ownProduct->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_price_bait_and_switch_blocked_and_vault_immutability_plus_buyer_decrypt_status_guard(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $checkoutService = app(CheckoutSplitOrderService::class);
        $vaultService = app(VaultEncryptionService::class);

        // 1. Price Bait-and-Switch (TOCTOU) protection via expected_grand_total
        $cat = GameCategory::where('delivery_method', 'manual_trade')->firstOrFail();
        $product = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $cat->id,
            'sku_code' => 'TOCTOU-PRICE-01',
            'title' => 'Produk Uji Bait and Switch Harga',
            'slug' => 'produk-uji-bait-and-switch-harga',
            'description' => 'Produk uji validasi TOCTOU harga saat checkout.',
            'price' => '50000.00',
            'stock' => 10,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 10,
            'is_active' => true,
        ]);

        // Ensure category has no required buyer inputs by cloning cleanly if needed
        $inputs = [];
        foreach (($cat->required_inputs_schema ?? []) as $field) {
            $k = (string) ($field['key'] ?? '');
            if ($k !== '') {
                $inputs[$k] = ($field['type'] ?? 'text') === 'number' ? '12345678' : 'BuyerInputTest';
            }
        }

        // Seller silently doubles price from 50k -> 100k while buyer submits expected_grand_total = 50000
        $product->update(['price' => '100000.00']);

        try {
            $checkoutService->checkout(
                buyerId: $buyer1->id,
                paymentMode: 'wallet_full',
                explicitItems: [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'buyer_game_inputs' => $inputs,
                ]],
                clearCart: false,
                expectedGrandTotal: 50000.00,
            );
            $this->fail('Expected PriceChangedException when server price changed from expected_grand_total.');
        } catch (PriceChangedException $e) {
            $this->assertStringContainsString('harga produk atau promo berubah', strtolower($e->getMessage()));
        }

        // Matching expected_grand_total succeeds cleanly
        $validInvoice = $checkoutService->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            explicitItems: [[
                'product_id' => $product->id,
                'quantity' => 1,
                'buyer_game_inputs' => $inputs,
            ]],
            clearCart: false,
            expectedGrandTotal: 100000.00,
        );
        $this->assertSame('PAID', $validInvoice->status);

        // 2. SOLD product_vault row is immutable against edit & delete (both Service & ORM)
        $soldVault = ProductVault::where('status', 'sold')->firstOrFail();
        $vaultSellerId = (int) $soldVault->product->seller_id;

        try {
            $vaultService->updateAvailableVaultItem($soldVault->id, $vaultSellerId, 'TAMPERED-CODE-999');
            $this->fail('Expected SOLD vault update to be rejected by VaultEncryptionService.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('permanen', strtolower($e->getMessage()));
        }

        try {
            $vaultService->deleteAvailableVaultItem($soldVault->id, $vaultSellerId);
            $this->fail('Expected SOLD vault delete to be rejected by VaultEncryptionService.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('permanen', strtolower($e->getMessage()));
        }

        try {
            $soldVault->delete();
            $this->fail('Expected Eloquent delete on SOLD ProductVault to be rejected.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('sold', strtolower($e->getMessage()));
        }

        // 3. Buyer CANNOT decrypt vault payload when order status is REFUNDED / CANCELLED (HTTP 403)
        $deliveredVaultOrder = Order::with('items')
            ->where('buyer_id', $buyer1->id)
            ->where('delivery_method', 'instant_vault')
            ->where('status', 'DELIVERED')
            ->firstOrFail();
        $vaultOrderItem = $deliveredVaultOrder->items->firstOrFail();

        // Works while DELIVERED (200)
        $this->actingAs($buyer1)
            ->postJson(route('buyer.orders.decrypt', [$deliveredVaultOrder->order_code, $vaultOrderItem->id]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Blocked (403) once order status is REFUNDED
        DB::table('orders')->where('id', $deliveredVaultOrder->id)->update(['status' => 'REFUNDED']);
        $this->actingAs($buyer1)
            ->postJson(route('buyer.orders.decrypt', [$deliveredVaultOrder->order_code, $vaultOrderItem->id]))
            ->assertForbidden()
            ->assertJsonPath('ok', false);

        // Blocked (403) when status is CANCELLED
        DB::table('orders')->where('id', $deliveredVaultOrder->id)->update(['status' => 'CANCELLED']);
        $this->actingAs($buyer1)
            ->postJson(route('buyer.orders.decrypt', [$deliveredVaultOrder->order_code, $vaultOrderItem->id]))
            ->assertForbidden()
            ->assertJsonPath('ok', false);
    }

    public function test_late_payment_webhook_safe_refund_idempotent_and_ledger_balanced(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $vaultProduct = Product::where('seller_id', $seller1->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->where('stock', '>', 0)
            ->firstOrFail();

        $stockBefore = (int) $vaultProduct->stock;
        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $vaultProduct->id, 'quantity' => 1]],
            clearCart: false,
        );

        // Expire the UNPAID invoice via sweeper -> stock restored cleanly
        DB::table('payment_invoices')->where('id', $invoice->id)->update(['expires_at' => now()->subMinutes(10)]);
        app(OrderStateAndSlaService::class)->sweepExpiredInvoices();

        $invoice->refresh();
        $this->assertSame('EXPIRED', $invoice->status);
        $this->assertSame($stockBefore, (int) $vaultProduct->fresh()->stock);

        $availBefore = (float) $buyer1->wallet->fresh()->available_balance;
        $nonWdBefore = (float) $buyer1->wallet->fresh()->non_withdrawable_portion;
        $grossStr = number_format((float) $invoice->grand_total, 2, '.', '');
        $midtrans = app(MidtransWebhookService::class);
        $sig = $midtrans->generateSignature($invoice->invoice_code, '200', $grossStr);

        $payload = [
            'order_id' => $invoice->invoice_code,
            'status_code' => '200',
            'gross_amount' => $grossStr,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'MT-LATEPAY-001',
            'signature_key' => $sig,
        ];

        // 1st webhook -> safely refunds to buyer available_balance as LATE_PAYMENT_REFUND without re-locking stock!
        $this->postJson(route('api.webhooks.midtrans'), $payload)
            ->assertOk()
            ->assertJsonPath('invoice_status', 'PAID_LATE_REFUNDED')
            ->assertJsonPath('mutation_reference', "LATEPAY-{$invoice->invoice_code}");

        // 2nd identical webhook -> idempotent replay, ZERO duplicate credit!
        $this->postJson(route('api.webhooks.midtrans'), $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        // 3rd webhook with different status_code -> STILL idempotent, ZERO duplicate credit!
        $sig201 = $midtrans->generateSignature($invoice->invoice_code, '201', $grossStr);
        $this->postJson(route('api.webhooks.midtrans'), array_merge($payload, [
            'status_code' => '201',
            'signature_key' => $sig201,
        ]))
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $walletAfter = $buyer1->wallet->fresh();
        $this->assertEqualsWithDelta($availBefore + (float) $invoice->grand_total, (float) $walletAfter->available_balance, 0.01);
        $this->assertEqualsWithDelta($nonWdBefore, (float) $walletAfter->non_withdrawable_portion, 0.01);
        $this->assertSame($stockBefore, (int) $vaultProduct->fresh()->stock);

        $this->assertSame(1, WalletMutation::where('reference_code', "LATEPAY-{$invoice->invoice_code}")->count());
        $this->assertDatabaseHas('wallet_mutations', [
            'reference_code' => "LATEPAY-{$invoice->invoice_code}",
            'mutation_type' => 'LATE_PAYMENT_REFUND',
            'user_id' => $buyer1->id,
        ]);

        // Global ledger reconciliation MUST remain BALANCED (discrepancy Rp 0.00)!
        $this->artisan('nexatrade:audit-ledger', ['--triggered-by' => 'round2_latepay'])
            ->assertExitCode(0);
        $latest = LedgerAuditSnapshot::orderByDesc('id')->firstOrFail();
        $this->assertSame('BALANCED', $latest->status);
        $this->assertSame('0.00', (string) $latest->discrepancy_amount);
    }

    public function test_immutable_ledger_audit_orm_admin_conflict_of_interest_and_chaos_lab_lock(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();

        // 1. WalletMutation & AdminAuditLog are strictly append-only (updating/deleting throws LogicException)
        $mutation = WalletMutation::firstOrFail();
        try {
            $mutation->update(['description' => 'Tampered description']);
            $this->fail('Expected LogicException on WalletMutation::update.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }

        try {
            $mutation->delete();
            $this->fail('Expected LogicException on WalletMutation::delete.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }

        $auditLog = AdminAuditLog::firstOrFail();
        try {
            $auditLog->update(['notes' => 'Tampered audit log']);
            $this->fail('Expected LogicException on AdminAuditLog::update.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }

        try {
            $auditLog->delete();
            $this->fail('Expected LogicException on AdminAuditLog::delete.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
        }

        // 2. Admin Conflict of Interest Guards -> HTTP 403 across Withdraw, Dispute, KYC & Warranty!
        $adminWd = WithdrawalRequest::create([
            'withdraw_code' => 'WD-SELF-'.strtoupper(Str::random(6)),
            'user_id' => $admin->id,
            'bank_name' => 'BCA',
            'account_number' => '1122334455',
            'account_holder' => 'ADMIN SELF',
            'amount' => '50000.00',
            'admin_fee' => '2500.00',
            'net_payout' => '47500.00',
            'status' => 'PENDING',
        ]);
        $this->actingAs($admin)->post(route('admin.withdrawals.approve', $adminWd->id))->assertForbidden();

        $adminInvoice = PaymentInvoice::create([
            'invoice_code' => 'INV-COI-'.strtoupper(Str::random(6)),
            'buyer_id' => $admin->id,
            'subtotal_amount' => '50000.00',
            'seller_discount_total' => '0.00',
            'platform_discount_total' => '0.00',
            'gateway_fee' => '0.00',
            'wallet_used_amount' => '50000.00',
            'grand_total' => '0.00',
            'payment_channel' => 'wallet_full',
            'status' => 'PAID',
            'paid_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $adminDisputedOrder = Order::create([
            'order_code' => 'NXT-COI-'.strtoupper(Str::random(6)),
            'payment_invoice_id' => $adminInvoice->id,
            'buyer_id' => $admin->id,
            'seller_id' => $seller1->id,
            'gross_amount' => '50000.00',
            'seller_discount_amount' => '0.00',
            'platform_discount_amount' => '0.00',
            'buyer_payable_amount' => '50000.00',
            'platform_commission' => '2500.00',
            'seller_escrow_amount' => '47500.00',
            'delivery_method' => 'manual_trade',
            'status' => 'DISPUTED',
            'sla_target_minutes_snapshot' => 10,
        ]);
        $this->actingAs($admin)->post(route('admin.disputes.resolve', $adminDisputedOrder->order_code), [
            'verdict' => 'REFUND_TO_BUYER',
            'admin_notes' => 'Admin trying to rule in own favor.',
        ])->assertForbidden();

        $adminKyc = SellerKycVerification::create([
            'user_id' => $admin->id,
            'active_user_lock' => $admin->id,
            'proposed_store_name' => 'Admin Own Store',
            'proposed_store_slug' => 'admin-own-store',
            'full_legal_name' => 'ADMIN SELF',
            'nik_encrypted' => Crypt::encryptString('3171999999990001'),
            'nik_last4' => '0001',
            'nik_blind_hash' => hash('sha256', '3171999999990001'),
            'active_nik_lock_hash' => hash('sha256', '3171999999990001'),
            'id_card_private_path' => 'private/kyc/seeded/ktp-sample.png',
            'selfie_private_path' => 'private/kyc/seeded/selfie-sample.png',
            'status' => 'pending',
        ]);
        $this->actingAs($admin)->post(route('admin.kyc.approve', $adminKyc))->assertForbidden();

        $adminWarranty = OrderWarranty::create([
            'order_id' => $adminDisputedOrder->id,
            'buyer_id' => $admin->id,
            'seller_id' => $seller1->id,
            'warranty_days' => 30,
            'coverage_amount' => '50000.00',
            'starts_at' => now(),
            'expires_at' => now()->addDays(30),
            'status' => 'claimed',
        ]);
        $adminClaim = WarrantyClaim::create([
            'claim_code' => 'CLM-COI-'.strtoupper(Str::random(5)),
            'order_warranty_id' => $adminWarranty->id,
            'order_id' => $adminDisputedOrder->id,
            'buyer_id' => $admin->id,
            'seller_id' => $seller1->id,
            'reason_category' => 'account_reclaimed_hackback',
            'description' => 'Admin conflict of interest warranty test.',
            'status' => 'open',
        ]);
        $this->actingAs($admin)->post(route('admin.warranties.resolve', $adminClaim), [
            'decision' => 'APPROVE_REFUND',
            'admin_resolution_notes' => 'Self-approving own claim.',
        ])->assertForbidden();

        // 3. Chaos Lab returns 404 when demo_switcher_enabled=false, while production Admin Disputes & Withdrawals stay 200!
        config(['nexatrade.demo_switcher_enabled' => false]);
        $this->actingAs($admin)->get(route('admin.chaos-lab'))->assertNotFound();
        $this->actingAs($admin)->postJson(route('admin.chaos-lab.run', 1))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.disputes'))->assertOk();
        $this->actingAs($admin)->get(route('admin.withdrawals'))->assertOk();
    }

    public function test_model_hidden_attributes_and_http_security_headers(): void
    {
        $user = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $vault = ProductVault::firstOrFail();
        $bank = UserBankAccount::firstOrFail();
        $kyc = SellerKycVerification::firstOrFail();
        $orderItem = OrderItem::whereNotNull('encrypted_delivery_payload')->firstOrFail();

        $pinToken = WalletPinResetToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'secret-token-sample'),
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
        ]);

        $userArr = $user->toArray();
        $this->assertArrayNotHasKey('password', $userArr);
        $this->assertArrayNotHasKey('remember_token', $userArr);
        $this->assertArrayNotHasKey('wallet_pin_hash', $userArr);

        $vaultArr = $vault->toArray();
        $this->assertArrayNotHasKey('encrypted_payload', $vaultArr);
        $this->assertArrayNotHasKey('payload_blind_hash', $vaultArr);

        $bankArr = $bank->toArray();
        $this->assertArrayNotHasKey('account_blind_hash', $bankArr);
        $this->assertArrayNotHasKey('active_account_lock_hash', $bankArr);

        $kycArr = $kyc->toArray();
        $this->assertArrayNotHasKey('nik_encrypted', $kycArr);
        $this->assertArrayNotHasKey('nik_blind_hash', $kycArr);
        $this->assertArrayNotHasKey('active_nik_lock_hash', $kycArr);
        $this->assertArrayNotHasKey('id_card_private_path', $kycArr);
        $this->assertArrayNotHasKey('selfie_private_path', $kycArr);

        $this->assertArrayNotHasKey('token_hash', $pinToken->toArray());
        $this->assertArrayNotHasKey('encrypted_delivery_payload', $orderItem->toArray());

        // HTTP Security Headers & Anti-Cache on /account/security
        $resp = $this->actingAs($user)->get(route('account.security'));
        $resp->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Pragma', 'no-cache');

        $this->assertStringContainsString('no-store', strtolower((string) $resp->headers->get('Cache-Control')));
    }

    public function test_stored_xss_payloads_in_product_and_review_are_html_escaped(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $cat = GameCategory::firstOrFail();

        $xssProduct = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $cat->id,
            'sku_code' => 'XSS-TEST-01',
            'title' => "Skin Langka <script>alert('xss')</script> Edition",
            'slug' => 'skin-langka-xss-test-edition',
            'description' => "Deskripsi dengan payload <script>alert('desc-xss')</script> aman.",
            'price' => '55000.00',
            'stock' => 5,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 10,
            'is_active' => true,
        ]);

        $invoice = PaymentInvoice::create([
            'invoice_code' => 'INV-XSS-'.strtoupper(Str::random(6)),
            'buyer_id' => $buyer1->id,
            'subtotal_amount' => '55000.00',
            'seller_discount_total' => '0.00',
            'platform_discount_total' => '0.00',
            'gateway_fee' => '0.00',
            'wallet_used_amount' => '55000.00',
            'grand_total' => '0.00',
            'payment_channel' => 'wallet_full',
            'status' => 'PAID',
            'paid_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $order = Order::create([
            'order_code' => 'NXT-XSS-'.strtoupper(Str::random(6)),
            'payment_invoice_id' => $invoice->id,
            'buyer_id' => $buyer1->id,
            'seller_id' => $seller1->id,
            'gross_amount' => '55000.00',
            'seller_discount_amount' => '0.00',
            'platform_discount_amount' => '0.00',
            'buyer_payable_amount' => '55000.00',
            'platform_commission' => '2750.00',
            'seller_escrow_amount' => '52250.00',
            'delivery_method' => 'manual_trade',
            'status' => 'COMPLETED',
            'sla_target_minutes_snapshot' => 10,
            'completed_at' => now(),
        ]);

        OrderReview::create([
            'order_id' => $order->id,
            'product_id' => $xssProduct->id,
            'seller_id' => $seller1->id,
            'buyer_id' => $buyer1->id,
            'rating' => 5,
            'comment' => "Mantap <script>alert('review-xss')</script> banget!",
            'delivery_duration_minutes' => 3,
        ]);

        $html = $this->get(route('marketplace.show', $xssProduct->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString("<script>alert('xss')</script>", $html);
        $this->assertStringNotContainsString("<script>alert('desc-xss')</script>", $html);
        $this->assertStringNotContainsString("<script>alert('review-xss')</script>", $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&#039;review-xss&#039;)&lt;/script&gt;', $html);
    }
}
