<?php

namespace Tests\Feature;

use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CheckoutSplitOrderService;
use App\Services\FinanceAndLedgerAuditService;
use App\Services\WalletTopUpService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MidtransSnapSandboxIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_request_and_reuse_invoice_snap_token_via_http_fake(): void
    {
        config([
            'nexatrade.midtrans.payment_driver' => 'midtrans_sandbox',
            'nexatrade.midtrans.server_key' => 'SB-Mid-server-TestKey123',
            'nexatrade.midtrans.client_key' => 'SB-Mid-client-TestKey123',
            'nexatrade.midtrans.snap_api_url' => 'https://app.sandbox.midtrans.com/snap/v1/transactions',
        ]);

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-invoice-998877',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-invoice-998877',
            ], 201),
        ]);

        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();
        $product = Product::where('sku_code', 'STM-IDR-120K')->firstOrFail();

        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $product->id, 'quantity' => 1]],
            clearCart: false,
        );

        // 1st request calls Midtrans Snap API and persists snap_token + snap_redirect_url
        $resp1 = $this->actingAs($buyer1)
            ->postJson(route('invoices.snap-token', $invoice->invoice_code));

        $resp1->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('snap_token', 'snap-token-invoice-998877')
            ->assertJsonPath('redirect_url', 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-invoice-998877');

        Http::assertSentCount(1);

        $invoice->refresh();
        $this->assertSame('snap-token-invoice-998877', $invoice->snap_token);
        $this->assertSame('https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-invoice-998877', $invoice->snap_redirect_url);

        // 2nd request reuses the stored Snap token without making another HTTP call
        $resp2 = $this->actingAs($buyer1)
            ->postJson(route('invoices.snap-token', $invoice->invoice_code));

        $resp2->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('snap_token', 'snap-token-invoice-998877');

        Http::assertSentCount(1);

        // Unrelated buyer must receive HTTP 403
        $this->actingAs($buyer2)
            ->postJson(route('invoices.snap-token', $invoice->invoice_code))
            ->assertForbidden();
    }

    public function test_request_and_reuse_wallet_topup_snap_token_and_sync_status(): void
    {
        config([
            'nexatrade.midtrans.payment_driver' => 'midtrans_sandbox',
            'nexatrade.midtrans.server_key' => 'SB-Mid-server-TestKey123',
            'nexatrade.midtrans.snap_api_url' => 'https://app.sandbox.midtrans.com/snap/v1/transactions',
            'nexatrade.midtrans.core_api_url' => 'https://api.sandbox.midtrans.com',
        ]);

        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $topup = app(WalletTopUpService::class)->createTopUp($buyer1->id, 150000.00, 'midtrans_snap');

        Http::fake([
            'https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response([
                'token' => 'snap-token-topup-150k',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-topup-150k',
            ], 201),
            "https://api.sandbox.midtrans.com/v2/{$topup->topup_code}/status" => Http::response([
                'status_code' => '200',
                'transaction_id' => 'mt-core-topup-001',
                'order_id' => $topup->topup_code,
                'gross_amount' => '150000.00',
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
            ], 200),
        ]);

        $this->actingAs($buyer1)
            ->postJson(route('buyer.wallet.topup.snap-token', $topup->topup_code))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('snap_token', 'snap-token-topup-150k');

        // Reusing topup snap token does not call Snap API again
        $this->actingAs($buyer1)
            ->postJson(route('buyer.wallet.topup.snap-token', $topup->topup_code))
            ->assertOk()
            ->assertJsonPath('snap_token', 'snap-token-topup-150k');

        $availBefore = (float) Wallet::where('user_id', $buyer1->id)->value('available_balance');
        $nonWithdrawableBefore = (float) Wallet::where('user_id', $buyer1->id)->value('non_withdrawable_portion');

        // Active status sync settles top-up and credits Anti-Gestun wallet balance
        $this->actingAs($buyer1)
            ->postJson(route('buyer.wallet.topup.sync-status', $topup->topup_code))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('topup_status', 'PAID');

        $this->assertSame('PAID', $topup->refresh()->status);
        $this->assertEqualsWithDelta(
            $availBefore + 150000.00,
            (float) Wallet::where('user_id', $buyer1->id)->value('available_balance'),
            0.01,
        );
        $this->assertEqualsWithDelta(
            $nonWithdrawableBefore + 150000.00,
            (float) Wallet::where('user_id', $buyer1->id)->value('non_withdrawable_portion'),
            0.01,
        );
    }

    public function test_active_status_sync_via_core_api_settles_invoice_and_dispatches_orders_idempotently(): void
    {
        config([
            'nexatrade.midtrans.payment_driver' => 'midtrans_sandbox',
            'nexatrade.midtrans.server_key' => 'SB-Mid-server-TestKey123',
            'nexatrade.midtrans.core_api_url' => 'https://api.sandbox.midtrans.com',
        ]);

        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $product = Product::where('sku_code', 'STM-IDR-120K')->firstOrFail();
        $sellerId = (int) $product->seller_id;

        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $product->id, 'quantity' => 1]],
            clearCart: false,
        );

        $grossStr = number_format((float) $invoice->grand_total, 2, '.', '');

        Http::fake([
            "https://api.sandbox.midtrans.com/v2/{$invoice->invoice_code}/status" => Http::response([
                'status_code' => '200',
                'transaction_id' => 'mt-core-inv-777',
                'order_id' => $invoice->invoice_code,
                'gross_amount' => $grossStr,
                'transaction_status' => 'settlement',
                'fraud_status' => 'accept',
            ], 200),
        ]);

        $sellerPendingBefore = (float) Wallet::where('user_id', $sellerId)->value('pending_balance');

        // 1st Sync Status -> settles invoice, holds escrow, and dispatches instant_vault order to DELIVERED
        $this->actingAs($buyer1)
            ->postJson(route('invoices.sync-status', $invoice->invoice_code))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('invoice_status', 'PAID');

        $invoice->refresh();
        $this->assertSame('PAID', $invoice->status);
        $childOrder = $invoice->orders()->firstOrFail();
        $this->assertSame('DELIVERED', $childOrder->status);

        $expectedEscrow = (float) $childOrder->seller_escrow_amount;
        $this->assertEqualsWithDelta(
            $sellerPendingBefore + $expectedEscrow,
            (float) Wallet::where('user_id', $sellerId)->value('pending_balance'),
            0.01,
        );

        // 2nd Sync Status -> strictly idempotent (zero duplicate escrow hold!)
        $this->actingAs($buyer1)
            ->postJson(route('invoices.sync-status', $invoice->invoice_code))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('invoice_status', 'PAID')
            ->assertJsonPath('result.idempotent_replay', true);

        $this->assertEqualsWithDelta(
            $sellerPendingBefore + $expectedEscrow,
            (float) Wallet::where('user_id', $sellerId)->value('pending_balance'),
            0.01,
        );

        $audit = app(FinanceAndLedgerAuditService::class)->runGlobalLedgerAudit('midtrans_snap_sync_test');
        $this->assertSame('BALANCED', $audit->status);
        $this->assertSame('0.00', (string) $audit->discrepancy_amount);
    }

    public function test_invoice_and_topup_views_render_snap_button_in_sandbox_mode_and_hide_simulation_when_disabled(): void
    {
        config([
            'nexatrade.midtrans.payment_driver' => 'midtrans_sandbox',
            'nexatrade.demo_switcher_enabled' => false,
        ]);

        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $unpaidInvoice = PaymentInvoice::where('buyer_id', $buyer1->id)
            ->where('status', 'UNPAID')
            ->firstOrFail();
        $topup = app(WalletTopUpService::class)->createTopUp($buyer1->id, 50000.00, 'midtrans_snap');

        $this->actingAs($buyer1)
            ->get(route('invoices.show', $unpaidInvoice->invoice_code))
            ->assertOk()
            ->assertSee('Bayar Sekarang via Midtrans Snap')
            ->assertSee('Sinkronkan Status Pembayaran')
            ->assertDontSee('Simulasi Bayar Lunas Midtrans');

        $this->actingAs($buyer1)
            ->get(route('buyer.wallet.topup.show', $topup->topup_code))
            ->assertOk()
            ->assertSee('Bayar Sekarang via Midtrans Snap')
            ->assertSee('Sinkronkan Status Pembayaran')
            ->assertDontSee('Simulasi Bayar Lunas Top-Up');

        // Simulation endpoints return HTTP 404 when demo_switcher_enabled is false
        $this->actingAs($buyer1)
            ->post(route('invoices.simulate-pay', $unpaidInvoice->invoice_code))
            ->assertNotFound();
    }
}
