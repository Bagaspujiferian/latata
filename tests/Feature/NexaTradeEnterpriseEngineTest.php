<?php

namespace Tests\Feature;

use App\Exceptions\InvalidInvoiceStateTransitionException;
use App\Exceptions\InvalidOrderStateTransitionException;
use App\Exceptions\SelfDealingException;
use App\Exceptions\StoreClosedException;
use App\Models\Order;
use App\Models\PaymentInvoice;
use App\Models\Product;
use App\Models\ProductVault;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletMutation;
use App\Models\WithdrawalRequest;
use App\Services\ChatModerationService;
use App\Services\CheckoutSplitOrderService;
use App\Services\OrderStateAndSlaService;
use App\Services\SellerCatalogService;
use App\Support\StateTransitionGuard;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NexaTradeEnterpriseEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_all_eight_chaos_lab_scenarios_pass_on_real_mysql_innodb(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();

        for ($scenario = 1; $scenario <= 8; $scenario++) {
            $response = $this->actingAs($admin)
                ->postJson(route('admin.chaos-lab.run', $scenario));

            $response->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('result.scenario', $scenario)
                ->assertJsonPath('result.passed', true);
        }

        $this->assertGlobalFinancialAndVaultInvariants();
    }

    public function test_self_dealing_guard_blocks_seller_purchasing_own_product(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $ownProduct = Product::where('seller_id', $seller1->id)->firstOrFail();

        $this->expectException(SelfDealingException::class);

        app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $seller1->id,
            paymentMode: 'wallet_full',
            explicitItems: [['product_id' => $ownProduct->id, 'quantity' => 1]],
            clearCart: false,
        );
    }

    public function test_store_open_guard_blocks_manual_trade_but_allows_instant_vault_24_7(): void
    {
        $buyer = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();

        app(SellerCatalogService::class)->toggleStoreOpen($seller2->id);

        $manualProduct = Product::with('gameCategory')
            ->where('seller_id', $seller2->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'manual_trade'))
            ->firstOrFail();

        $vaultProduct = Product::with('gameCategory')
            ->where('seller_id', $seller2->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->where('stock', '>', 0)
            ->firstOrFail();

        // Instant vault MUST remain purchasable 24/7 even while store is closed!
        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $vaultProduct->id, 'quantity' => 1]],
            clearCart: false,
        );
        $this->assertSame('UNPAID', $invoice->status);

        // Manual trade MUST be blocked while store is closed!
        $this->expectException(StoreClosedException::class);

        app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [[
                'product_id' => $manualProduct->id,
                'quantity' => 1,
                'buyer_game_inputs' => ['grow_id' => 'TestGT', 'world_name' => 'DROP99'],
            ]],
            clearCart: false,
        );
    }

    public function test_strict_state_transition_matrix_rejects_illegal_jumps(): void
    {
        try {
            StateTransitionGuard::assertOrderTransition('WAITING_PAYMENT', 'COMPLETED');
            $this->fail('Expected InvalidOrderStateTransitionException.');
        } catch (InvalidOrderStateTransitionException) {
            $this->assertTrue(true);
        }

        try {
            StateTransitionGuard::assertInvoiceTransition('UNPAID', 'PAID_LATE_REFUNDED');
            $this->fail('Expected InvalidInvoiceStateTransitionException.');
        } catch (InvalidInvoiceStateTransitionException) {
            $this->assertTrue(true);
        }
    }

    public function test_vault_driven_stock_cannot_be_forged_manually(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $vaultProduct = Product::where('seller_id', $seller1->id)
            ->whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))
            ->firstOrFail();

        $expectedAvailable = ProductVault::where('product_id', $vaultProduct->id)
            ->where('status', 'available')
            ->count();

        $updated = app(SellerCatalogService::class)->updateProduct($vaultProduct->id, $seller1->id, [
            'title' => $vaultProduct->title,
            'price' => $vaultProduct->price,
            'stock' => 9999,
            'sla_target_minutes' => 1,
        ]);

        $this->assertSame($expectedAvailable, (int) $updated->stock);
    }

    public function test_unauthorized_buyer_cannot_decrypt_another_buyers_aes256_vault(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $buyer2 = User::where('email', 'proplayer@nexatrade.id')->firstOrFail();

        $deliveredVaultOrder = Order::with('items')
            ->where('buyer_id', $buyer1->id)
            ->where('delivery_method', 'instant_vault')
            ->where('status', 'DELIVERED')
            ->firstOrFail();

        $item = $deliveredVaultOrder->items->firstOrFail();

        // Buyer 2 MUST receive HTTP 403!
        $this->actingAs($buyer2)
            ->postJson(route('buyer.orders.decrypt', [$deliveredVaultOrder->order_code, $item->id]))
            ->assertStatus(403)
            ->assertJsonPath('ok', false);

        // Authorized Buyer 1 MUST decrypt cleanly!
        $this->actingAs($buyer1)
            ->postJson(route('buyer.orders.decrypt', [$deliveredVaultOrder->order_code, $item->id]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['plaintext_payload', 'blind_hash_prefix']);
    }

    public function test_anti_bypass_chat_moderation_censors_phone_bank_url_and_offplatform_payments(): void
    {
        $result = app(ChatModerationService::class)->moderate(
            'WA saya di 081234567890 atau buka https://wa.me/6281234567890 transfer langsung ke BCA 883019284122 via DANA'
        );

        $this->assertTrue($result['is_flagged_bypass']);
        $this->assertStringContainsString('[SENSOR ANTI-BYPASS NEXATRADE]', $result['sanitized_message']);
        $this->assertStringNotContainsString('081234567890', $result['sanitized_message']);
        $this->assertStringNotContainsString('wa.me', $result['sanitized_message']);
        $this->assertStringNotContainsString('883019284122', $result['sanitized_message']);
    }

    public function test_withdrawal_rejection_refunds_seller_and_blocks_double_processing(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $pendingWd = WithdrawalRequest::where('status', 'PENDING')->firstOrFail();
        $sellerId = $pendingWd->user_id;

        $balanceBefore = (float) Wallet::where('user_id', $sellerId)->value('available_balance');

        $rejected = app(OrderStateAndSlaService::class)->rejectWithdrawal($pendingWd->id, $admin->id, 'Nomor rekening invalid');
        $this->assertSame('REJECTED_REFUNDED', $rejected->status);

        $balanceAfter = (float) Wallet::where('user_id', $sellerId)->value('available_balance');
        $this->assertEqualsWithDelta($balanceBefore + (float) $pendingWd->amount, $balanceAfter, 0.01);

        // Second attempt MUST fail (anti double-refund/double-payout guard)!
        $this->expectException(DomainException::class);
        app(OrderStateAndSlaService::class)->approveWithdrawal($pendingWd->id, $admin->id);
    }

    public function test_admin_dispute_verdict_and_nickname_verification_api(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $disputed = Order::where('status', 'DISPUTED')->firstOrFail();

        $buyerBefore = (float) Wallet::where('user_id', $disputed->buyer_id)->value('available_balance');
        $sellerPendingBefore = (float) Wallet::where('user_id', $disputed->seller_id)->value('pending_balance');

        $resolved = app(OrderStateAndSlaService::class)->adminResolveDispute(
            orderId: $disputed->id,
            adminId: $admin->id,
            verdict: 'REFUND_TO_BUYER',
            adminNotes: 'Terbukti pelanggaran anti-bypass oleh penjual.',
        );

        $this->assertSame('REFUNDED', $resolved->status);
        $this->assertEqualsWithDelta(
            $buyerBefore + (float) $disputed->gross_amount,
            (float) Wallet::where('user_id', $disputed->buyer_id)->value('available_balance'),
            0.01,
        );
        $this->assertEqualsWithDelta(
            $sellerPendingBefore - (float) $disputed->seller_escrow_amount,
            (float) Wallet::where('user_id', $disputed->seller_id)->value('pending_balance'),
            0.01,
        );

        // Test Nickname API valid & invalid cases.
        $this->postJson(route('api.game.verify-nickname'), [
            'game_slug' => 'mobile-legends',
            'inputs' => ['user_id' => '123456789', 'zone_id' => '2041'],
        ])->assertOk()->assertJsonPath('valid', true)->assertJsonPath('nickname', 'EVOS•KaguraID');

        $this->postJson(route('api.game.verify-nickname'), [
            'game_slug' => 'mobile-legends',
            'inputs' => ['user_id' => '12', 'zone_id' => '9'],
        ])->assertStatus(422)->assertJsonPath('valid', false);
    }

    public function test_all_blade_pages_render_cleanly_across_buyer_seller_and_admin_roles(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();

        $product = Product::firstOrFail();
        $buyerOrder = Order::where('buyer_id', $buyer1->id)->firstOrFail();
        $invoice = PaymentInvoice::where('buyer_id', $buyer1->id)->firstOrFail();

        // Buyer pages
        $this->actingAs($buyer1)->get(route('marketplace.index'))->assertOk();
        $this->actingAs($buyer1)->get(route('marketplace.show', $product->slug))->assertOk();
        $this->actingAs($buyer1)->get(route('cart.index'))->assertOk();
        $this->actingAs($buyer1)->get(route('buyer.orders.index'))->assertOk();
        $this->actingAs($buyer1)->get(route('buyer.orders.show', $buyerOrder->order_code))->assertOk();
        $this->actingAs($buyer1)->get(route('buyer.wallet'))->assertOk();
        $this->actingAs($buyer1)->get(route('invoices.show', $invoice->invoice_code))->assertOk();

        // Role enforcement: Buyer cannot access admin or seller studio
        $this->actingAs($buyer1)->get(route('admin.chaos-lab'))->assertRedirect();
        $this->actingAs($buyer1)->get(route('seller.dashboard'))->assertRedirect();

        // Seller pages
        $this->actingAs($seller1)->get(route('seller.dashboard'))->assertOk();
        $this->actingAs($seller1)->get(route('seller.products'))->assertOk();
        $this->actingAs($seller1)->get(route('seller.orders'))->assertOk();
        $this->actingAs($seller1)->get(route('seller.wallet'))->assertOk();

        // Admin pages
        $this->actingAs($admin)->get(route('admin.disputes'))->assertOk();
        $this->actingAs($admin)->get(route('admin.withdrawals'))->assertOk();
        $this->actingAs($admin)->get(route('admin.chaos-lab'))->assertOk();
    }

    private function assertGlobalFinancialAndVaultInvariants(): void
    {
        // Zero negative balances allowed anywhere in wallets.
        $negativeWallets = Wallet::where('pending_balance', '<', 0)
            ->orWhere('available_balance', '<', 0)
            ->count();
        $this->assertSame(0, $negativeWallets);

        // Every wallet_mutations row must satisfy exact double-entry math.
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

        // Vault-Driven Stock Integrity: products.stock === available vault count for every instant_vault product.
        $vaultProducts = Product::whereHas('gameCategory', fn ($q) => $q->where('delivery_method', 'instant_vault'))->get();
        foreach ($vaultProducts as $vp) {
            $availableCount = DB::table('product_vaults')
                ->where('product_id', $vp->id)
                ->where('status', 'available')
                ->count();
            $this->assertSame($availableCount, (int) $vp->stock, "Stock drift detected on product #{$vp->id}");
        }
    }
}
