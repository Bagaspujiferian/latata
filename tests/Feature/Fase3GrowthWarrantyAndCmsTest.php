<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\CategoryAttribute;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Game;
use App\Models\GameCategory;
use App\Models\ModerationReport;
use App\Models\Order;
use App\Models\OrderWarranty;
use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Models\Wallet;
use App\Models\WalletMutation;
use App\Models\WarrantyClaim;
use App\Services\AccountWarrantyService;
use App\Services\CheckoutSplitOrderService;
use App\Services\EscrowLedgerService;
use App\Services\OrderStateAndSlaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Fase3GrowthWarrantyAndCmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_platform_voucher_subsidy_accounting_and_anti_farming_refund(): void
    {
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $manualCat = GameCategory::where('delivery_method', 'manual_trade')->firstOrFail();
        $manualCat->update(['platform_fee_percent' => '5.00']);

        $product = Product::create([
            'seller_id' => $seller->id,
            'game_category_id' => $manualCat->id,
            'sku_code' => 'TEST-PLAT-100K',
            'title' => 'Paket Uji Voucher Platform 100K',
            'slug' => 'paket-uji-voucher-platform-100k',
            'description' => 'Produk uji subsidi platform voucher.',
            'price' => '100000.00',
            'stock' => 10,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 10,
            'is_active' => true,
        ]);

        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();

        $voucher = Voucher::create([
            'code' => 'TESTPLAT20',
            'title' => 'Subsidi Platform Rp 20.000',
            'issuer_type' => 'platform',
            'seller_id' => null,
            'created_by' => $admin->id,
            'discount_type' => 'fixed',
            'discount_value' => '20000.00',
            'min_purchase_amount' => '50000.00',
            'quota_total' => 20,
            'quota_used' => 0,
            'max_uses_per_user' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
            'is_active' => true,
        ]);

        $buyerBefore = (float) $buyer->wallet->fresh()->available_balance;
        $sellerPendingBefore = (float) $seller->wallet->fresh()->pending_balance;

        $inputs = [];
        foreach ($manualCat->required_inputs_schema ?? [] as $field) {
            $inputs[$field['key']] = '123456';
        }

        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [[
                'product_id' => $product->id,
                'quantity' => 1,
                'buyer_game_inputs' => $inputs,
            ]],
            clearCart: false,
            platformVoucherCode: 'TESTPLAT20',
        );

        $order = $invoice->orders()->firstOrFail();

        // Exact Platform Subsidy Accounting:
        // Buyer pays 80k, Seller still receives 95k in escrow (100k - 5k platform fee).
        $this->assertSame('100000.00', (string) $order->gross_amount);
        $this->assertSame('0.00', (string) $order->seller_discount_amount);
        $this->assertSame('20000.00', (string) $order->platform_discount_amount);
        $this->assertSame('80000.00', (string) $order->buyer_payable_amount);
        $this->assertSame('5000.00', (string) $order->platform_commission);
        $this->assertSame('95000.00', (string) $order->seller_escrow_amount);

        $buyerAfterCheckout = (float) $buyer->wallet->fresh()->available_balance;
        $sellerPendingAfterCheckout = (float) $seller->wallet->fresh()->pending_balance;

        $this->assertEqualsWithDelta($buyerBefore - 80000.00, $buyerAfterCheckout, 0.01);
        $this->assertEqualsWithDelta($sellerPendingBefore + 95000.00, $sellerPendingAfterCheckout, 0.01);

        $this->assertDatabaseHas('voucher_usages', [
            'voucher_id' => $voucher->id,
            'order_id' => $order->id,
            'status' => 'applied',
            'subsidy_bearer' => 'platform',
            'discount_amount' => '20000.00',
        ]);

        // ANTI-FARMING SUBSIDY REFUND LAW: Force SLA breach and claim refund!
        DB::table('orders')->where('id', $order->id)->update([
            'sla_deadline_at' => now()->subMinutes(10),
        ]);

        app(OrderStateAndSlaService::class)->buyerClaimSlaBreachRefund($order->id, $buyer->id);

        // Seller pending debited 95k, buyer credited ONLY 80k (NEVER 100k)!
        $buyerAfterRefund = (float) $buyer->wallet->fresh()->available_balance;
        $sellerPendingAfterRefund = (float) $seller->wallet->fresh()->pending_balance;

        $this->assertEqualsWithDelta($buyerBefore, $buyerAfterRefund, 0.01, 'Buyer MUST only be refunded buyer_payable_amount (80k), never platform subsidy!');
        $this->assertEqualsWithDelta($sellerPendingBefore, $sellerPendingAfterRefund, 0.01);
        $this->assertGlobalDoubleEntryAndNonNegativeInvariants();
    }

    public function test_seller_voucher_reduces_net_basis_and_platform_commission(): void
    {
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $cat = GameCategory::where('delivery_method', 'api_h2h')->firstOrFail();
        $cat->update(['platform_fee_percent' => '5.00']);

        $product = Product::create([
            'seller_id' => $seller->id,
            'game_category_id' => $cat->id,
            'sku_code' => 'TEST-SELL-100K',
            'title' => 'Paket Uji Voucher Penjual 100K',
            'slug' => 'paket-uji-voucher-penjual-100k',
            'description' => 'Produk uji voucher penjual.',
            'price' => '100000.00',
            'stock' => 20,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 5,
            'h2h_provider_sku' => 'TESTSKU',
            'is_active' => true,
        ]);

        Voucher::create([
            'code' => 'TESTSELL10',
            'title' => 'Potongan Toko Rp 10.000',
            'issuer_type' => 'seller',
            'seller_id' => $seller->id,
            'created_by' => $seller->id,
            'discount_type' => 'fixed',
            'discount_value' => '10000.00',
            'min_purchase_amount' => '50000.00',
            'quota_total' => 20,
            'quota_used' => 0,
            'max_uses_per_user' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
            'is_active' => true,
        ]);

        $inputs = [];
        foreach ($cat->required_inputs_schema ?? [] as $field) {
            $inputs[$field['key']] = '123456';
        }

        $buyerBefore = (float) $buyer->wallet->fresh()->available_balance;
        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [[
                'product_id' => $product->id,
                'quantity' => 1,
                'buyer_game_inputs' => $inputs,
            ]],
            clearCart: false,
            sellerVoucherCodes: [$seller->id => 'TESTSELL10'],
        );

        $order = $invoice->orders()->firstOrFail();

        // net_basis = 100k - 10k = 90k -> 5% fee = 4.500 -> seller_escrow = 85.500 -> buyer pays 90.000
        $this->assertSame('100000.00', (string) $order->gross_amount);
        $this->assertSame('10000.00', (string) $order->seller_discount_amount);
        $this->assertSame('0.00', (string) $order->platform_discount_amount);
        $this->assertSame('90000.00', (string) $order->buyer_payable_amount);
        $this->assertSame('4500.00', (string) $order->platform_commission);
        $this->assertSame('85500.00', (string) $order->seller_escrow_amount);

        $this->assertEqualsWithDelta($buyerBefore - 90000.00, (float) $buyer->wallet->fresh()->available_balance, 0.01);
        $this->assertGlobalDoubleEntryAndNonNegativeInvariants();
    }

    public function test_pro_rata_split_order_platform_voucher_exact_allocation(): void
    {
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $cat = GameCategory::where('delivery_method', 'manual_trade')->firstOrFail();

        $prod1 = Product::create([
            'seller_id' => $seller1->id,
            'game_category_id' => $cat->id,
            'sku_code' => 'PRORATA-S1-60K',
            'title' => 'Item Seller 1 60K',
            'slug' => 'item-seller-1-60k',
            'description' => 'Item split order 60k.',
            'price' => '60000.00',
            'stock' => 10,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 10,
            'is_active' => true,
        ]);

        $prod2 = Product::create([
            'seller_id' => $seller2->id,
            'game_category_id' => $cat->id,
            'sku_code' => 'PRORATA-S2-40K',
            'title' => 'Item Seller 2 40K',
            'slug' => 'item-seller-2-40k',
            'description' => 'Item split order 40k.',
            'price' => '40000.00',
            'stock' => 10,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 10,
            'is_active' => true,
        ]);

        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();

        Voucher::create([
            'code' => 'PRORATA20',
            'title' => 'Pro-Rata Platform 20K',
            'issuer_type' => 'platform',
            'created_by' => $admin->id,
            'discount_type' => 'fixed',
            'discount_value' => '20000.00',
            'min_purchase_amount' => '80000.00',
            'quota_total' => 20,
            'quota_used' => 0,
            'max_uses_per_user' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
            'is_active' => true,
        ]);

        $inputs = [];
        foreach ($cat->required_inputs_schema ?? [] as $field) {
            $inputs[$field['key']] = '999999';
        }

        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [
                ['product_id' => $prod1->id, 'quantity' => 1, 'buyer_game_inputs' => $inputs],
                ['product_id' => $prod2->id, 'quantity' => 1, 'buyer_game_inputs' => $inputs],
            ],
            clearCart: false,
            platformVoucherCode: 'PRORATA20',
        );

        $this->assertSame('100000.00', (string) $invoice->subtotal_amount);
        $this->assertSame('20000.00', (string) $invoice->platform_discount_total);
        $this->assertSame('80000.00', (string) $invoice->wallet_used_amount);

        $ordS1 = $invoice->orders()->where('seller_id', $seller1->id)->firstOrFail();
        $ordS2 = $invoice->orders()->where('seller_id', $seller2->id)->firstOrFail();

        // 60k / 100k * 20k = 12k; 40k / 100k * 20k = 8k; exact sum = 20k
        $this->assertSame('12000.00', (string) $ordS1->platform_discount_amount);
        $this->assertSame('48000.00', (string) $ordS1->buyer_payable_amount);
        $this->assertSame('8000.00', (string) $ordS2->platform_discount_amount);
        $this->assertSame('32000.00', (string) $ordS2->buyer_payable_amount);
        $this->assertGlobalDoubleEntryAndNonNegativeInvariants();
    }

    public function test_flash_sale_quota_lock_per_user_limit_and_expiry_rollback_with_voucher_release(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $seller = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $cat = GameCategory::where('delivery_method', 'manual_trade')->firstOrFail();

        $prod = Product::create([
            'seller_id' => $seller->id,
            'game_category_id' => $cat->id,
            'sku_code' => 'FLASH-TEST-100K',
            'title' => 'Item Flash Sale Uji Kuota',
            'slug' => 'item-flash-sale-uji-kuota',
            'description' => 'Produk uji flash sale & rollback.',
            'price' => '100000.00',
            'stock' => 20,
            'min_order' => 1,
            'max_order' => 5,
            'sla_target_minutes' => 10,
            'is_active' => true,
        ]);

        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();

        $sale = FlashSale::create([
            'title' => 'Flash Sale Uji Kuota',
            'slug' => 'flash-sale-uji-kuota',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $flashItem = FlashSaleItem::create([
            'flash_sale_id' => $sale->id,
            'product_id' => $prod->id,
            'flash_price' => '75000.00',
            'flash_quota' => 2,
            'flash_sold' => 1,
            'max_qty_per_user' => 1,
            'sort_order' => 1,
        ]);

        $voucher = Voucher::create([
            'code' => 'EXPVOUCH10',
            'title' => 'Voucher Uji Rollback Expired',
            'issuer_type' => 'platform',
            'created_by' => $admin->id,
            'discount_type' => 'fixed',
            'discount_value' => '10000.00',
            'min_purchase_amount' => '50000.00',
            'quota_total' => 5,
            'quota_used' => 0,
            'max_uses_per_user' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
            'is_active' => true,
        ]);

        $inputs = [];
        foreach ($cat->required_inputs_schema ?? [] as $field) {
            $inputs[$field['key']] = '555555';
        }

        // Buyer 1 reserves the last Flash Sale unit (1 -> 2) + Voucher EXPVOUCH10 (0 -> 1) on UNPAID invoice
        $inv1 = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $prod->id, 'quantity' => 1, 'buyer_game_inputs' => $inputs]],
            clearCart: false,
            platformVoucherCode: 'EXPVOUCH10',
        );

        $item1 = $inv1->orders()->firstOrFail()->items()->firstOrFail();
        $this->assertSame($flashItem->id, (int) $item1->flash_sale_item_id);
        $this->assertSame('75000.00', (string) $item1->unit_price_snapshot);
        $this->assertSame('100000.00', (string) $item1->original_unit_price);
        $this->assertSame(2, $flashItem->fresh()->flash_sold);
        $this->assertSame(1, $voucher->fresh()->quota_used);
        $this->assertDatabaseHas('voucher_usages', [
            'voucher_id' => $voucher->id,
            'payment_invoice_id' => $inv1->id,
            'status' => 'reserved',
        ]);

        // While Flash Sale quota is full (2/2), Buyer 2 gracefully falls back to regular price 100.000
        $inv2 = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer2->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $prod->id, 'quantity' => 1, 'buyer_game_inputs' => $inputs]],
            clearCart: false,
        );
        $item2 = $inv2->orders()->firstOrFail()->items()->firstOrFail();
        $this->assertNull($item2->flash_sale_item_id);
        $this->assertSame('100000.00', (string) $item2->unit_price_snapshot);

        // Expire Buyer 1's UNPAID invoice -> sweepExpiredInvoices() MUST release BOTH flash_sold AND voucher quota!
        DB::table('payment_invoices')->where('id', $inv1->id)->update([
            'expires_at' => now()->subMinutes(5),
        ]);

        app(OrderStateAndSlaService::class)->sweepExpiredInvoices();

        $this->assertSame('EXPIRED', $inv1->fresh()->status);
        $this->assertSame(1, $flashItem->fresh()->flash_sold, 'Flash sale quota must roll back on expired invoice!');
        $this->assertSame(0, $voucher->fresh()->quota_used, 'Voucher quota_used must roll back on expired invoice!');
        $this->assertSame('released', VoucherUsage::where('payment_invoice_id', $inv1->id)->value('status'));
        $this->assertGlobalDoubleEntryAndNonNegativeInvariants();
    }

    public function test_anti_hackback_warranty_clawback_sufficient_deposit_and_non_negative_debt_auto_suspend(): void
    {
        Storage::fake('local');

        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $warrantyService = app(AccountWarrantyService::class);

        // === CASE A: SUFFICIENT SECURITY DEPOSIT (150k claim against 200k deposit) ===
        DB::table('wallets')->where('user_id', $seller1->id)->update([
            'security_deposit_balance' => '200000.00',
            'available_balance' => '50000.00',
            'clawback_debt_balance' => '0.00',
        ]);

        $invA = PaymentInvoice::create([
            'invoice_code' => 'INV-WAR-CASE-A',
            'buyer_id' => $buyer->id,
            'subtotal_amount' => '150000.00',
            'wallet_used_amount' => '150000.00',
            'gateway_fee' => '0.00',
            'grand_total' => '0.00',
            'payment_mode' => 'wallet_full',
            'payment_channel' => 'wallet_full',
            'status' => 'PAID',
            'paid_at' => now()->subDay(),
            'expires_at' => now()->subHours(23),
        ]);

        $orderA = Order::create([
            'payment_invoice_id' => $invA->id,
            'order_code' => 'ORD-WAR-CASE-A',
            'buyer_id' => $buyer->id,
            'seller_id' => $seller1->id,
            'delivery_method' => 'instant_vault',
            'gross_amount' => '150000.00',
            'buyer_payable_amount' => '150000.00',
            'platform_commission' => '7500.00',
            'seller_escrow_amount' => '142500.00',
            'sla_target_minutes_snapshot' => 5,
            'status' => 'COMPLETED',
            'completed_at' => now()->subDay(),
        ]);

        $warrantyA = OrderWarranty::create([
            'order_id' => $orderA->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller1->id,
            'warranty_days' => 30,
            'coverage_amount' => '150000.00',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
            'status' => 'active',
        ]);

        $buyerBalBeforeA = (float) $buyer->wallet->fresh()->available_balance;

        $claimA = $warrantyService->fileWarrantyClaim(
            orderId: $orderA->id,
            buyerId: $buyer->id,
            data: [
                'reason_category' => 'account_reclaimed_hackback',
                'description' => 'Akun ditarik balik pemilik lama dengan bukti email recovery.',
            ],
            evidenceFiles: [UploadedFile::fake()->image('proof-a.png', 200, 200)],
        );

        $this->assertNotEmpty($claimA->evidence_paths);
        Storage::disk('local')->assertExists($claimA->evidence_paths[0]);

        $resolvedA = $warrantyService->resolveWarrantyClaim($claimA->id, $admin->id, 'APPROVE_REFUND', 'Bukti hackback valid.');

        $this->assertSame('approved_refund', $resolvedA->status);
        $this->assertSame('150000.00', (string) $resolvedA->clawback_from_deposit);
        $this->assertSame('0.00', (string) $resolvedA->clawback_from_available);
        $this->assertSame('0.00', (string) $resolvedA->recorded_seller_debt);

        $wSeller1 = $seller1->wallet->fresh();
        $this->assertSame('50000.00', (string) $wSeller1->security_deposit_balance);
        $this->assertSame('50000.00', (string) $wSeller1->available_balance);
        $this->assertSame('0.00', (string) $wSeller1->clawback_debt_balance);
        $this->assertFalse((bool) $seller1->sellerProfile->fresh()->is_suspended);
        $this->assertEqualsWithDelta($buyerBalBeforeA + 150000.00, (float) $buyer->wallet->fresh()->available_balance, 0.01);
        $this->assertSame('refunded', $warrantyA->fresh()->status);

        // === CASE B: INSUFFICIENT FUNDS (300k claim against 50k deposit + 30k available -> 220k debt + Auto-Suspend!) ===
        DB::table('wallets')->where('user_id', $seller2->id)->update([
            'security_deposit_balance' => '50000.00',
            'available_balance' => '30000.00',
            'clawback_debt_balance' => '0.00',
        ]);

        $invB = PaymentInvoice::create([
            'invoice_code' => 'INV-WAR-CASE-B',
            'buyer_id' => $buyer->id,
            'subtotal_amount' => '300000.00',
            'wallet_used_amount' => '300000.00',
            'gateway_fee' => '0.00',
            'grand_total' => '0.00',
            'payment_mode' => 'wallet_full',
            'payment_channel' => 'wallet_full',
            'status' => 'PAID',
            'paid_at' => now()->subDay(),
            'expires_at' => now()->subHours(23),
        ]);

        $orderB = Order::create([
            'payment_invoice_id' => $invB->id,
            'order_code' => 'ORD-WAR-CASE-B',
            'buyer_id' => $buyer->id,
            'seller_id' => $seller2->id,
            'delivery_method' => 'instant_vault',
            'gross_amount' => '300000.00',
            'buyer_payable_amount' => '300000.00',
            'platform_commission' => '15000.00',
            'seller_escrow_amount' => '285000.00',
            'sla_target_minutes_snapshot' => 5,
            'status' => 'COMPLETED',
            'completed_at' => now()->subDay(),
        ]);

        OrderWarranty::create([
            'order_id' => $orderB->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller2->id,
            'warranty_days' => 30,
            'coverage_amount' => '300000.00',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
            'status' => 'active',
        ]);

        $buyerBalBeforeB = (float) $buyer->wallet->fresh()->available_balance;

        $claimB = $warrantyService->fileWarrantyClaim(
            orderId: $orderB->id,
            buyerId: $buyer->id,
            data: [
                'reason_category' => 'password_email_changed',
                'description' => 'Email recovery diubah paksa tanpa izin pembeli.',
            ],
            evidenceFiles: [],
        );

        $resolvedB = $warrantyService->resolveWarrantyClaim($claimB->id, $admin->id, 'APPROVE_REFUND', 'Penjual terbukti hackback, dana kurang -> auto suspend.');

        $this->assertSame('approved_refund', $resolvedB->status);
        $this->assertSame('50000.00', (string) $resolvedB->clawback_from_deposit);
        $this->assertSame('30000.00', (string) $resolvedB->clawback_from_available);
        $this->assertSame('220000.00', (string) $resolvedB->recorded_seller_debt);

        $wSeller2 = $seller2->wallet->fresh();
        $this->assertSame('0.00', (string) $wSeller2->security_deposit_balance, 'Deposit MUST NEVER go negative!');
        $this->assertSame('0.00', (string) $wSeller2->available_balance, 'Available balance MUST NEVER go negative!');
        $this->assertSame('220000.00', (string) $wSeller2->clawback_debt_balance);

        $profile2 = $seller2->sellerProfile->fresh();
        $this->assertTrue((bool) $profile2->is_suspended);
        $this->assertFalse((bool) $profile2->is_store_open);
        $this->assertEqualsWithDelta($buyerBalBeforeB + 300000.00, (float) $buyer->wallet->fresh()->available_balance, 0.01, 'Buyer MUST still receive 100% coverage!');

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'WARRANTY_CLAIM_APPROVED_CLAWBACK',
            'entity_type' => 'warranty_claim',
            'entity_id' => $claimB->id,
        ]);

        $this->assertGlobalDoubleEntryAndNonNegativeInvariants();
    }

    public function test_admin_cms_master_catalog_and_immutable_audit_trail(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();

        // 1. Create Game via CMS
        $this->actingAs($admin)->post(route('admin.cms.games.store'), [
            'name' => 'Honkai: Star Rail',
            'publisher' => 'HoYoverse',
            'thumbnail_url' => '/images/games/genshin-impact.svg',
            'is_popular' => 1,
            'requires_nickname_check' => 1,
        ])->assertRedirect();

        $game = Game::where('slug', 'honkai-star-rail')->firstOrFail();
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'CMS_GAME_CREATED',
            'entity_type' => 'game',
            'entity_id' => $game->id,
        ]);

        // 2. Create Category with 48h Escrow Hold, 14d Warranty & Dynamic Checkout Schema JSON
        $schemaJson = json_encode([
            ['key' => 'uid', 'label' => 'UID Trailblazer', 'type' => 'number', 'required' => true, 'placeholder' => '800123456'],
        ]);

        $this->actingAs($admin)->post(route('admin.cms.categories.store'), [
            'game_id' => $game->id,
            'name' => 'Akun Trailblazer High-End',
            'delivery_method' => 'instant_vault',
            'platform_fee_percent' => 6.0,
            'escrow_hold_hours' => 48,
            'warranty_days' => 14,
            'required_inputs_schema' => $schemaJson,
        ])->assertRedirect();

        $category = GameCategory::where('game_id', $game->id)->firstOrFail();
        $this->assertSame(48, (int) $category->escrow_hold_hours);
        $this->assertSame(14, (int) $category->warranty_days);
        $this->assertCount(1, $category->required_inputs_schema ?? []);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'CMS_CATEGORY_CREATED',
            'entity_type' => 'game_category',
            'entity_id' => $category->id,
        ]);

        // 3. Create Category Attribute + Controlled Options
        $this->actingAs($admin)->post(route('admin.cms.attributes.store'), [
            'game_category_id' => $category->id,
            'name' => 'Trailblaze Level',
            'input_type' => 'select',
            'options_csv' => 'TL 70, TL 65, TL 60',
            'is_filterable' => 1,
        ])->assertRedirect();

        $attr = CategoryAttribute::where('game_category_id', $category->id)->firstOrFail();
        $this->assertSame(3, $attr->options()->count());
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'CMS_ATTRIBUTE_CREATED',
            'entity_type' => 'category_attribute',
            'entity_id' => $attr->id,
        ]);
    }

    public function test_moderation_report_takedown_private_evidence_auth_and_phase3_ui_smoke(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $targetProduct = Product::where('sku_code', 'MLBB-JOKI-MYTHIC')->firstOrFail();

        // 1. Submit Moderation Report & Admin 1-Click Takedown
        $this->actingAs($buyer1)->post(route('reports.store'), [
            'target_type' => 'product',
            'target_id' => $targetProduct->id,
            'reason_category' => 'fraud_scam',
            'details' => 'Produk terindikasi deskripsi tidak sesuai dan mencurigakan untuk pengujian moderasi.',
        ])->assertRedirect();

        $report = ModerationReport::orderByDesc('id')->firstOrFail();
        $this->assertSame('open', $report->status);

        $this->actingAs($admin)->post(route('admin.reports.resolve', $report), [
            'action_type' => 'takedown_product',
            'resolution_notes' => 'Produk dinonaktifkan sementara menunggu klarifikasi penjual.',
        ])->assertRedirect();

        $this->assertFalse((bool) $targetProduct->fresh()->is_active);
        $this->assertSame('action_taken', $report->fresh()->status);
        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'MODERATION_TAKEDOWN_PRODUCT',
            'entity_type' => 'moderation_report',
            'entity_id' => $report->id,
        ]);

        // 2. Private Warranty Claim Evidence Stream Authorization (Admin = 200, Owner Buyer 2 = 200, Unrelated = 403!)
        $seededClaim = WarrantyClaim::where('claim_code', 'CLM-SEED-202601')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.warranties.evidence', [$seededClaim, 0]))->assertOk();
        $this->actingAs($buyer2)->get(route('admin.warranties.evidence', [$seededClaim, 0]))->assertOk();
        $this->actingAs($buyer1)->get(route('admin.warranties.evidence', [$seededClaim, 0]))->assertForbidden();
        $this->actingAs($seller1)->get(route('admin.warranties.evidence', [$seededClaim, 0]))->assertForbidden();

        // 3. AJAX Checkout Voucher Preview Endpoint (Buyer 1 has items in seeded cart)
        $this->actingAs($buyer1)
            ->postJson(route('checkout.voucher-preview'), ['code' => 'NEXAHEMAT20'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preview.code', 'NEXAHEMAT20')
            ->assertJsonPath('preview.discount_amount', 20000);

        $this->actingAs($buyer1)
            ->postJson(route('checkout.voucher-preview'), ['code' => 'KODETIDAKADA'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        // 4. HTTP 200 Smoke Check Across All Phase 3 Light Mode UI Pages
        $flashProduct = Product::where('sku_code', 'MLBB-WDP-PASS')->firstOrFail();
        $buyer1ValOrder = Order::where('buyer_id', $buyer1->id)->whereHas('warranty')->firstOrFail();

        $this->actingAs($buyer1)->get(route('marketplace.index'))->assertOk()->assertSee('Flash Sale Gaming Akhir Pekan');
        $this->actingAs($buyer1)->get(route('marketplace.show', $flashProduct->slug))->assertOk()->assertSee('Flash Sale Aktif');
        $this->actingAs($buyer1)->get(route('cart.index'))->assertOk()->assertSee('Makin Hemat Pakai Promo');
        $this->actingAs($buyer1)->get(route('buyer.orders.show', $buyer1ValOrder->order_code))->assertOk()->assertSee('Sertifikat Garansi Anti-Hackback');
        $this->actingAs($buyer1)->get(route('buyer.warranties'))->assertOk()->assertSee('Sertifikat Garansi Anti-Hackback');

        $this->actingAs($seller1)->get(route('seller.vouchers'))->assertOk()->assertSee('JURAGAN5K');
        $this->actingAs($seller1)->get(route('seller.wallet'))->assertOk()->assertSee('Deposit Jaminan Anti-Hackback');

        $this->actingAs($admin)->get(route('admin.cms.catalog'))->assertOk()->assertSee('Master Katalog CMS');
        $this->actingAs($admin)->get(route('admin.cms.promos'))->assertOk()->assertSee('NEXAHEMAT20');
        $this->actingAs($admin)->get(route('admin.warranties.index'))->assertOk()->assertSee('CLM-SEED-202601');
        $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk()->assertSee('Antrean Laporan Moderasi');
        $this->actingAs($admin)->get(route('admin.audit-logs'))->assertOk()->assertSee('Immutable Admin Audit Trail');

        // Role Guard: Buyer cannot access Admin CMS
        $this->actingAs($buyer1)->get(route('admin.cms.catalog'))->assertRedirect();
        $this->actingAs($buyer1)->get(route('admin.warranties.index'))->assertRedirect();
    }

    private function assertGlobalDoubleEntryAndNonNegativeInvariants(): void
    {
        $negativeWallets = Wallet::where('pending_balance', '<', 0)
            ->orWhere('available_balance', '<', 0)
            ->orWhere('security_deposit_balance', '<', 0)
            ->orWhere('clawback_debt_balance', '<', 0)
            ->count();
        $this->assertSame(0, $negativeWallets, 'No wallet balance bucket may ever be negative!');

        foreach (WalletMutation::all() as $m) {
            $before = (int) round((float) $m->balance_before * 100);
            $amount = (int) round((float) $m->amount * 100);
            $after = (int) round((float) $m->balance_after * 100);

            if ($m->direction === 'CREDIT') {
                $this->assertSame($before + $amount, $after, "Ledger mismatch on {$m->reference_code}");
            } else {
                $this->assertGreaterThanOrEqual($amount, $before, "Overdraft on {$m->reference_code}");
                $this->assertSame($before - $amount, $after, "Ledger mismatch on {$m->reference_code}");
            }
        }
    }
}
