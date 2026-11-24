<?php

namespace Tests\Feature;

use App\Exceptions\StoreOnVacationException;
use App\Models\Product;
use App\Models\ProductVault;
use App\Models\SellerProfile;
use App\Models\StoreOperatingHour;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Wallet;
use App\Services\CheckoutSplitOrderService;
use App\Services\FinanceAndLedgerAuditService;
use App\Services\ManualOrderCancellationService;
use App\Services\VaultBulkImportService;
use App\Services\VaultEncryptionService;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Tahap3SellerOpsAndFulltextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_vacation_mode_blocks_manual_trade_allows_instant_vault_and_operating_hours_shifts_manual_sla_deadline(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $checkoutService = app(CheckoutSplitOrderService::class);

        $manualProduct = Product::where('sku_code', 'MLBB-JOKI-MYTHIC')->firstOrFail();
        $instantProduct = Product::where('sku_code', 'VAL-ACC-IMMORTAL')->firstOrFail();

        // 1. Activate Vacation Mode on Seller 2
        SellerProfile::where('user_id', $seller2->id)->update([
            'is_on_vacation' => true,
            'vacation_note' => 'Libur nasional hingga besok pagi pukul 08:00 WIB.',
        ]);

        // Manual trade MUST be rejected with StoreOnVacationException
        try {
            $checkoutService->checkout(
                buyerId: $buyer1->id,
                paymentMode: 'wallet_full',
                paymentChannel: 'wallet_full',
                explicitItems: [[
                    'product_id' => $manualProduct->id,
                    'quantity' => 1,
                    'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041', 'target_rank' => 'Mythic'],
                    'verified_game_nickname' => 'BagasPro',
                ]],
                clearCart: false,
            );
            $this->fail('Expected StoreOnVacationException for manual trade when store is on vacation.');
        } catch (StoreOnVacationException) {
            $this->assertTrue(true);
        }

        // Instant Vault from the exact same vacationing store MUST succeed 24/7!
        $instantInvoice = $checkoutService->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [['product_id' => $instantProduct->id, 'quantity' => 1]],
            clearCart: false,
        );
        $this->assertSame('PAID', $instantInvoice->status);

        // 2. Disable Vacation Mode & enable SLA-Aware operating_hours_enabled with today marked closed and tomorrow open 09:00-21:00
        SellerProfile::where('user_id', $seller2->id)->update([
            'is_on_vacation' => false,
            'vacation_note' => null,
            'operating_hours_enabled' => true,
            'operating_timezone' => 'Asia/Jakarta',
        ]);

        $nowJakarta = now()->timezone('Asia/Jakarta');
        $todayDow = (int) $nowJakarta->dayOfWeek;
        $tomorrowDow = ($todayDow + 1) % 7;

        StoreOperatingHour::updateOrCreate(
            ['seller_id' => $seller2->id, 'day_of_week' => $todayDow],
            ['open_time' => '08:00:00', 'close_time' => '22:00:00', 'is_closed' => true],
        );
        StoreOperatingHour::updateOrCreate(
            ['seller_id' => $seller2->id, 'day_of_week' => $tomorrowDow],
            ['open_time' => '09:00:00', 'close_time' => '21:00:00', 'is_closed' => false],
        );

        $manualInvoice = $checkoutService->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [[
                'product_id' => $manualProduct->id,
                'quantity' => 1,
                'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041', 'target_rank' => 'Mythic'],
                'verified_game_nickname' => 'BagasPro',
            ]],
            clearCart: false,
        );

        $manualOrder = $manualInvoice->orders()->firstOrFail();
        $this->assertSame('PROCESSING', $manualOrder->status);
        $this->assertNotNull($manualOrder->sla_deadline_at);

        $expectedTomorrowOpenPlusSla = $nowJakarta->copy()
            ->addDay()
            ->setTime(9, 0, 0)
            ->addMinutes((int) $manualProduct->sla_target_minutes);

        $this->assertSame(
            $expectedTomorrowOpenPlusSla->format('Y-m-d H:i'),
            $manualOrder->sla_deadline_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
        );
    }

    public function test_bulk_vault_import_deduplicates_batch_and_existing_db_hashes_and_syncs_stock(): void
    {
        $seller1 = User::where('email', 'juragan@nexatrade.id')->firstOrFail();
        $vaultProduct = Product::where('sku_code', 'STM-IDR-120K')->firstOrFail();
        $vaultEncryptionService = app(VaultEncryptionService::class);
        $bulkImportService = app(VaultBulkImportService::class);

        // Seed 1 known code into DB first
        $vaultEncryptionService->bulkIngestVaultItems($vaultProduct->id, $seller1->id, [
            'TAHAP3-EXISTING-DB-CODE-001',
        ]);

        $stockBefore = (int) $vaultProduct->refresh()->stock;

        // 10 lines total: 2 empty, 1 already in DB, 5 unique new codes, 2 internal batch duplicates
        $tenLines = [
            "\xEF\xBB\xBFTAHAP3-NEW-CODE-001",
            'TAHAP3-NEW-CODE-002',
            '   ',
            'TAHAP3-NEW-CODE-001', // batch duplicate #1
            'TAHAP3-EXISTING-DB-CODE-001', // DB duplicate #1
            'TAHAP3-NEW-CODE-003',
            '',
            'TAHAP3-NEW-CODE-004',
            'tahap3-new-code-002', // batch duplicate #2 (case-insensitive blind hash)
            'TAHAP3-NEW-CODE-005',
        ];

        $result = $bulkImportService->importLines($seller1->id, $vaultProduct->id, $tenLines);

        $this->assertSame(10, $result->totalLinesProcessed);
        $this->assertSame(5, $result->insertedCount);
        $this->assertSame(2, $result->skippedDuplicateInBatchCount);
        $this->assertSame(1, $result->skippedDuplicateInDbCount);
        $this->assertSame(2, $result->skippedEmptyCount);

        $vaultProduct->refresh();
        $actualAvailable = ProductVault::where('product_id', $vaultProduct->id)
            ->where('status', 'available')
            ->count();

        $this->assertSame($stockBefore + 5, (int) $vaultProduct->stock);
        $this->assertSame($actualAvailable, (int) $vaultProduct->stock);
    }

    public function test_buyer_can_cancel_unprocessed_manual_order_with_anti_farming_refund_and_balanced_ledger(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $manualProduct = Product::where('sku_code', 'MLBB-JOKI-MYTHIC')->firstOrFail();
        $platformVoucher = Voucher::where('issuer_type', 'platform')->where('is_active', true)->firstOrFail();

        $stockBeforeCheckout = (int) $manualProduct->stock;

        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [[
                'product_id' => $manualProduct->id,
                'quantity' => 1,
                'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041', 'target_rank' => 'Mythic'],
                'verified_game_nickname' => 'BagasCancelTest',
            ]],
            clearCart: false,
            platformVoucherCode: $platformVoucher->code,
        );

        $order = $invoice->orders()->firstOrFail();
        $this->assertSame('PROCESSING', $order->status);
        $this->assertNull($order->seller_accepted_at);
        $this->assertLessThan((float) $order->gross_amount, (float) $order->buyer_payable_amount);

        $buyerBalanceBeforeCancel = (float) Wallet::where('user_id', $buyer1->id)->value('available_balance');
        $sellerPendingBeforeCancel = (float) Wallet::where('user_id', $order->seller_id)->value('pending_balance');

        // Buyer cancels via HTTP endpoint before seller accepts
        $this->actingAs($buyer1)
            ->post(route('buyer.orders.cancel', $order->order_code), [
                'reason' => 'Ingin mengganti target rank pesanan joki.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('REFUNDED', $order->status);
        $this->assertSame('buyer', $order->cancelled_by_role);
        $this->assertSame('Ingin mengganti target rank pesanan joki.', $order->cancellation_reason);

        // Product stock restored
        $this->assertSame($stockBeforeCheckout, (int) $manualProduct->refresh()->stock);

        // Anti-farming: buyer receives exact buyer_payable_amount (not gross_amount), seller pending debited by seller_escrow_amount
        $this->assertEqualsWithDelta(
            $buyerBalanceBeforeCancel + (float) $order->buyer_payable_amount,
            (float) Wallet::where('user_id', $buyer1->id)->value('available_balance'),
            0.01,
        );
        $this->assertEqualsWithDelta(
            $sellerPendingBeforeCancel - (float) $order->seller_escrow_amount,
            (float) Wallet::where('user_id', $order->seller_id)->value('pending_balance'),
            0.01,
        );

        // Global ledger audit MUST remain BALANCED (Discrepancy = Rp 0.00)
        $snapshot = app(FinanceAndLedgerAuditService::class)->runGlobalLedgerAudit('tahap3_buyer_cancel');
        $this->assertSame('BALANCED', $snapshot->status);
        $this->assertSame('0.00', (string) $snapshot->discrepancy_amount);
    }

    public function test_seller_accepting_manual_order_locks_out_unilateral_buyer_cancellation(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $seller2 = User::where('email', 'sultan@nexatrade.id')->firstOrFail();
        $manualProduct = Product::where('sku_code', 'MLBB-JOKI-MYTHIC')->firstOrFail();
        $cancelService = app(ManualOrderCancellationService::class);

        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'wallet_full',
            paymentChannel: 'wallet_full',
            explicitItems: [[
                'product_id' => $manualProduct->id,
                'quantity' => 1,
                'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041', 'target_rank' => 'Mythic'],
                'verified_game_nickname' => 'BagasLockTest',
            ]],
            clearCart: false,
        );

        $order = $invoice->orders()->firstOrFail();
        $this->assertNull($order->seller_accepted_at);

        // Seller clicks "Mulai Proses Pesanan"
        $this->actingAs($seller2)
            ->post(route('seller.orders.accept', $order->order_code))
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertNotNull($order->seller_accepted_at);

        // Buyer attempting unilateral cancel afterwards MUST be rejected
        $this->expectException(DomainException::class);
        $cancelService->buyerCancelUnprocessedOrder($buyer1->id, $order->id, 'Coba batalkan saat penjoki sudah mulai main');
    }

    public function test_fulltext_and_fallback_catalog_search_handles_long_short_and_special_character_queries_without_errors(): void
    {
        // 1. Long multi-word FULLTEXT Boolean Mode search
        $respFulltext = $this->get(route('marketplace.index', ['q' => 'Steam Wallet IDR']));
        $respFulltext->assertOk();
        $this->assertGreaterThanOrEqual(1, $respFulltext->viewData('products')->count());

        // 2. Short 2-letter search ('ML') falls back to escaped LIKE without error
        $respShort = $this->get(route('marketplace.index', ['q' => 'ML']));
        $respShort->assertOk();
        $this->assertGreaterThanOrEqual(1, $respShort->viewData('products')->count());

        // 3. Malicious / special operator characters ('+-*()"@<>~') do not trigger SQL syntax errors or LazyLoadingViolationException
        $respSpecial = $this->get(route('marketplace.index', ['q' => '+-*()"@<>~']));
        $respSpecial->assertOk();
        $this->assertSame(0, $respSpecial->viewData('products')->count());
    }
}
