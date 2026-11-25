<?php

namespace Tests\Feature;

use App\Console\Commands\RunConcurrencyStressTest;
use App\Exceptions\InsufficientVaultStockException;
use App\Exceptions\PriceChangedException;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutSplitOrderService;
use App\Services\FinanceAndLedgerAuditService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcurrencyStressCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_stress_test_command_rejects_production_environment_and_invalid_scenario(): void
    {
        $originalEnv = app()->environment();

        try {
            app()->detectEnvironment(static fn (): string => 'production');

            $this->artisan('nexatrade:stress-test')
                ->expectsOutputToContain('DITOLAK: Command nexatrade:stress-test hanya diizinkan pada environment [local] atau [testing].')
                ->assertExitCode(1);

            $this->assertSame(0, User::where('email', 'like', 'stress_%@latata.test')->count());
        } finally {
            app()->detectEnvironment(static fn (): string => (string) $originalEnv);
        }

        $this->artisan('nexatrade:stress-test', ['--scenario' => 'invalid_mode'])
            ->expectsOutputToContain('Skenario tidak valid: [invalid_mode].')
            ->assertExitCode(1);
    }

    public function test_stress_test_scenarios_vault_idempotency_flashsale_invariants_and_cleanup_remain_balanced(): void
    {
        /** @var RunConcurrencyStressTest $command */
        $command = app(RunConcurrencyStressTest::class);
        /** @var CheckoutSplitOrderService $checkoutService */
        $checkoutService = app(CheckoutSplitOrderService::class);
        /** @var FinanceAndLedgerAuditService $auditService */
        $auditService = app(FinanceAndLedgerAuditService::class);

        // 1. Scenario A: Instant Vault Contention (5 vault items vs 10 buyers)
        $vaultFixture = $command->prepareVaultContentionScenario(buyerCount: 10, vaultStock: 5);
        $vaultProduct = $vaultFixture['product'];
        $vaultSuccess = 0;
        $vaultRejected = 0;

        foreach ($vaultFixture['buyers'] as $buyer) {
            try {
                $checkoutService->checkout(
                    buyerId: $buyer->id,
                    paymentMode: 'wallet_full',
                    paymentChannel: 'wallet_full',
                );
                $vaultSuccess++;
            } catch (InsufficientVaultStockException) {
                $vaultRejected++;
            }
        }

        $this->assertSame(5, $vaultSuccess);
        $this->assertSame(5, $vaultRejected);

        $vaultEval = $command->evaluateVaultScenario($vaultProduct->id, 5);
        $this->assertTrue($vaultEval['passed']);
        $this->assertSame(5, $vaultEval['paid_orders_count']);
        $this->assertSame(5, $vaultEval['unique_vault_ids_count']);
        $this->assertSame(5, $vaultEval['sold_vaults_count']);
        $this->assertSame(0, $vaultEval['available_vaults_count']);
        $this->assertSame(0, $vaultEval['remaining_stock']);

        // 2. Scenario B: HTTP Idempotency Race (same buyer, same Idempotency-Key across repeated POST /checkout)
        $idempFixture = $command->prepareIdempotencyRaceScenario();
        $idempBuyer = $idempFixture['buyer'];
        $idempKey = $idempFixture['idempotency_key'];

        $mintedSession = $command->mintAuthenticatedBuyerSession($idempBuyer);
        $this->assertNotEmpty($mintedSession['cookie_header']);
        $this->assertSame(40, strlen($mintedSession['csrf_token']));
        $this->assertSame(40, strlen($mintedSession['session_id']));

        $histogramString = $command->formatStatusHistogram([
            200 => 0,
            302 => 5,
            409 => 0,
            419 => 0,
            422 => 25,
            429 => 0,
            500 => 0,
            0 => 0,
        ]);
        $this->assertSame('200:0, 302:5, 419:0, 422:25, 429:0, 0:0', $histogramString);

        $firstResponse = $this->actingAs($idempBuyer)
            ->withHeaders([
                'Accept' => 'application/json',
                'Idempotency-Key' => $idempKey,
            ])
            ->post('/checkout', [
                'payment_mode' => 'wallet_full',
                'payment_channel' => 'wallet_full',
            ]);

        $this->assertTrue(in_array($firstResponse->getStatusCode(), [200, 302], true));

        $replayedResponse = $this->actingAs($idempBuyer)
            ->withHeaders([
                'Accept' => 'application/json',
                'Idempotency-Key' => $idempKey,
            ])
            ->post('/checkout', [
                'payment_mode' => 'wallet_full',
                'payment_channel' => 'wallet_full',
            ]);

        $this->assertSame('true', $replayedResponse->headers->get('X-Idempotency-Replayed'));

        $idempEval = $command->evaluateIdempotencyScenario(
            buyerId: $idempBuyer->id,
            initialBalance: $idempFixture['initial_balance'],
            unitPrice: $idempFixture['unit_price'],
        );
        $this->assertTrue($idempEval['passed']);
        $this->assertSame(1, $idempEval['paid_invoices_count']);
        $this->assertSame(1, $idempEval['wallet_debits_count']);
        $this->assertEqualsWithDelta(475000.00, $idempEval['actual_balance'], 0.01);

        // 3. Scenario C: Flash Sale Quota Contention (3 promo quota units vs 8 verified buyers)
        $flashFixture = $command->prepareFlashSaleContentionScenario(buyerCount: 8, flashQuota: 3);
        $flashItem = $flashFixture['flash_sale_item'];
        $flashPrice = $flashFixture['flash_price'];
        $flashSuccess = 0;
        $flashRejected = 0;

        foreach ($flashFixture['buyers'] as $buyer) {
            try {
                $checkoutService->checkout(
                    buyerId: $buyer->id,
                    paymentMode: 'wallet_full',
                    paymentChannel: 'wallet_full',
                    expectedGrandTotal: $flashPrice,
                );
                $flashSuccess++;
            } catch (PriceChangedException) {
                $flashRejected++;
            }
        }

        $this->assertSame(3, $flashSuccess);
        $this->assertSame(5, $flashRejected);

        $flashEval = $command->evaluateFlashSaleScenario($flashItem->id, 3);
        $this->assertTrue($flashEval['passed']);
        $this->assertSame(3, $flashEval['flash_sold']);
        $this->assertSame(3, $flashEval['promo_order_items_count']);

        // 4. Global Ledger Reconciliation while all stress-test records & mutations exist
        $preCleanupAudit = $auditService->runGlobalLedgerAudit('stress-test-feature');
        $this->assertSame('BALANCED', $preCleanupAudit->status);
        $this->assertSame('0.00', (string) $preCleanupAudit->discrepancy_amount);

        // 5. Cleanup & post-cleanup Ledger Reconciliation
        $command->cleanupStressTestData();

        $this->assertSame(0, User::where('email', 'like', 'stress_%@latata.test')->count());
        $this->assertSame(0, Product::withTrashed()->where('sku_code', 'like', 'STRESS-SKU-%')->count());

        $postCleanupAudit = $auditService->runGlobalLedgerAudit('stress-test-post-cleanup');
        $this->assertSame('BALANCED', $postCleanupAudit->status);
        $this->assertSame('0.00', (string) $postCleanupAudit->discrepancy_amount);
    }
}
