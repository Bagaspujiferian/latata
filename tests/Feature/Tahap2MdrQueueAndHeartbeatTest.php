<?php

namespace Tests\Feature;

use App\Enums\HeartbeatRunStatus;
use App\Enums\WorkerHealthStatus;
use App\Jobs\DispatchH2HOrderJob;
use App\Jobs\SendUserNotificationJob;
use App\Models\Product;
use App\Models\SchedulerHeartbeat;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\CheckoutSplitOrderService;
use App\Services\DigiflazzH2HService;
use App\Services\FinanceAndLedgerAuditService;
use App\Services\MidtransWebhookService;
use App\Services\NotificationService;
use App\Services\SchedulerHeartbeatService;
use App\Services\WalletTopUpService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class Tahap2MdrQueueAndHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_invoice_and_topup_settlement_records_gateway_mdr_fee_updates_true_net_profit_and_keeps_ledger_balanced(): void
    {
        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $product = Product::where('sku_code', 'STM-IDR-120K')->firstOrFail();

        $financeService = app(FinanceAndLedgerAuditService::class);
        $webhookService = app(MidtransWebhookService::class);
        $topupService = app(WalletTopUpService::class);

        $beforeSummary = $financeService->getFinancePnLSummary();
        $invoiceMdrBefore = (float) $beforeSummary['total_invoice_mdr_cost'];
        $topupMdrBefore = (float) $beforeSummary['total_topup_mdr_cost'];

        // 1. Create UNPAID QRIS Invoice and settle via MidtransWebhookService with payment_type = 'qris' (0.7% MDR)
        $invoice = app(CheckoutSplitOrderService::class)->checkout(
            buyerId: $buyer1->id,
            paymentMode: 'gateway_only',
            paymentChannel: 'qris',
            explicitItems: [['product_id' => $product->id, 'quantity' => 1]],
            clearCart: false,
        );

        $grossStr = number_format((float) $invoice->grand_total, 2, '.', '');
        $expectedInvoiceMdr = round((float) $invoice->grand_total * 0.007, 2);

        $invResult = $webhookService->handleNotification([
            'order_id' => $invoice->invoice_code,
            'status_code' => '200',
            'gross_amount' => $grossStr,
            'payment_type' => 'qris',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'MT-TAHAP2-INV-001',
            'signature_key' => $webhookService->generateSignature($invoice->invoice_code, '200', $grossStr),
        ]);

        $this->assertSame(200, $invResult['http_status']);
        $invoice->refresh();
        $this->assertSame('PAID', $invoice->status);
        $this->assertSame('qris', $invoice->gateway_payment_type);
        $this->assertEqualsWithDelta($expectedInvoiceMdr, (float) $invoice->gateway_mdr_fee, 0.01);

        // 2. Create UNPAID Wallet Top-Up of Rp 200.000 and settle via MidtransWebhookService with payment_type = 'bank_transfer' (Rp 4.000 flat MDR)
        $topup = $topupService->createTopUp($buyer1->id, 200000.00, 'bca_va');
        $topupGrossStr = '200000.00';

        $topupResult = $webhookService->handleNotification([
            'order_id' => $topup->topup_code,
            'status_code' => '200',
            'gross_amount' => $topupGrossStr,
            'payment_type' => 'bank_transfer',
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'MT-TAHAP2-TOPUP-001',
            'signature_key' => $webhookService->generateSignature($topup->topup_code, '200', $topupGrossStr),
        ]);

        $this->assertSame(200, $topupResult['http_status']);
        $topup->refresh();
        $this->assertSame('PAID', $topup->status);
        $this->assertSame('bank_transfer', $topup->gateway_payment_type);
        $this->assertSame('4000.00', (string) $topup->gateway_mdr_fee);

        // 3. Verify FinanceAndLedgerAuditService P&L accurately reflects MDR costs & True Net Operating Profit
        $afterSummary = $financeService->getFinancePnLSummary();
        $this->assertEqualsWithDelta($invoiceMdrBefore + $expectedInvoiceMdr, (float) $afterSummary['total_invoice_mdr_cost'], 0.01);
        $this->assertEqualsWithDelta($topupMdrBefore + 4000.00, (float) $afterSummary['total_topup_mdr_cost'], 0.01);
        $this->assertEqualsWithDelta(
            (float) $afterSummary['total_invoice_mdr_cost'] + (float) $afterSummary['total_topup_mdr_cost'],
            (float) $afterSummary['total_gateway_mdr_cost'],
            0.01,
        );
        $this->assertEqualsWithDelta(
            (float) $afterSummary['gross_platform_revenue'] - (float) $afterSummary['total_voucher_subsidy'] - (float) $afterSummary['total_gateway_mdr_cost'],
            (float) $afterSummary['true_net_operating_profit'],
            0.01,
        );

        // 4. Admin Finance page renders MDR and True Net Operating Profit cleanly
        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertSee('Biaya MDR Payment Gateway')
            ->assertSee('True Net Operating Profit');

        // 5. CRITICAL LAW: MDR must never touch user custody wallets or wallet_mutations! Ledger audit MUST stay BALANCED (Rp 0.00)!
        $auditSnapshot = $financeService->runGlobalLedgerAudit('tahap2_mdr_verification');
        $this->assertSame('BALANCED', $auditSnapshot->status);
        $this->assertSame('0.00', (string) $auditSnapshot->discrepancy_amount);
    }

    public function test_dispatch_h2h_order_job_is_queued_after_commit_not_dispatched_on_rollback_and_idempotent_on_retry(): void
    {
        Queue::fake();

        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $mlbbProduct = Product::where('sku_code', 'MLBB-DM-257')->firstOrFail();
        $checkoutService = app(CheckoutSplitOrderService::class);
        $h2hService = app(DigiflazzH2HService::class);

        // 1. If a transaction rolls back after dispatching DispatchH2HOrderJob::afterCommit(), the job MUST NOT be pushed!
        try {
            DB::transaction(function () use ($checkoutService, $buyer1, $mlbbProduct): void {
                $checkoutService->checkout(
                    buyerId: $buyer1->id,
                    paymentMode: 'wallet_full',
                    paymentChannel: 'wallet_full',
                    explicitItems: [[
                        'product_id' => $mlbbProduct->id,
                        'quantity' => 1,
                        'buyer_game_inputs' => ['user_id' => '123456789', 'zone_id' => '2041'],
                        'verified_game_nickname' => 'RollbackH2HUser',
                    ]],
                    clearCart: false,
                );

                throw new RuntimeException('Simulated transaction failure before commit');
            });
        } catch (RuntimeException) {
            // Expected rollback
        }

        Queue::assertNotPushed(DispatchH2HOrderJob::class);

        // 2. Committed H2H checkout dispatches DispatchH2HOrderJob afterCommit
        $invoice = $checkoutService->checkout(
            buyerId: $buyer1->id,
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

        $order = $invoice->orders()->firstOrFail();
        $this->assertSame('PROCESSING', $order->status);
        $this->assertNull($order->h2h_reference_id);

        $capturedJob = null;
        Queue::assertPushed(DispatchH2HOrderJob::class, function (DispatchH2HOrderJob $job) use ($order, &$capturedJob): bool {
            $capturedJob = $job;

            return $job->orderId === (int) $order->id && $job->afterCommit === true;
        });

        $this->assertInstanceOf(DispatchH2HOrderJob::class, $capturedJob);

        // 3. First execution of handle() dispatches top-up to Digiflazz and populates h2h_reference_id & h2h_response_log
        $capturedJob->handle($h2hService);
        $order->refresh();

        $this->assertSame($order->order_code, $order->h2h_reference_id);
        $this->assertNotEmpty($order->h2h_response_log);

        // 4. Second execution of handle() is strictly idempotent (returns early without overwriting log)
        $sentinelLog = ['already_dispatched_sentinel' => true];
        DB::table('orders')->where('id', $order->id)->update([
            'h2h_response_log' => json_encode($sentinelLog),
        ]);

        $capturedJob->handle($h2hService);
        $order->refresh();

        $this->assertSame($sentinelLog, $order->h2h_response_log);
    }

    public function test_send_user_notification_job_dispatches_after_commit_and_persists_notification(): void
    {
        Queue::fake();

        $buyer1 = User::where('email', 'bagas@nexatrade.id')->firstOrFail();
        $notificationService = app(NotificationService::class);

        $notificationService->notifyAfterCommit(
            userId: $buyer1->id,
            category: 'escrow',
            title: 'Uji Notifikasi Async Queue',
            body: 'Notifikasi ini dikirim melalui SendUserNotificationJob setelah transaksi commit.',
            actionUrl: '/buyer/wallet',
            refType: 'test_queue',
            refId: 999,
        );

        $capturedJob = null;
        Queue::assertPushed(SendUserNotificationJob::class, function (SendUserNotificationJob $job) use ($buyer1, &$capturedJob): bool {
            $capturedJob = $job;

            return $job->userId === (int) $buyer1->id
                && $job->title === 'Uji Notifikasi Async Queue'
                && $job->afterCommit === true;
        });

        $this->assertInstanceOf(SendUserNotificationJob::class, $capturedJob);
        $capturedJob->handle($notificationService);

        $this->assertTrue(
            UserNotification::where('user_id', $buyer1->id)
                ->where('title', 'Uji Notifikasi Async Queue')
                ->exists()
        );
    }

    public function test_artisan_commands_record_scheduler_heartbeats_and_detect_stale_and_failed_workers_on_finance_dashboard(): void
    {
        $admin = User::where('email', 'admin@nexatrade.id')->firstOrFail();
        $heartbeatService = app(SchedulerHeartbeatService::class);

        // 1. Running scheduled Artisan commands updates scheduler_heartbeats to OK with duration >= 0
        $this->artisan('nexatrade:audit-ledger', ['--triggered-by' => 'heartbeat_test'])
            ->assertExitCode(0);
        $this->artisan('nexatrade:expire-boosts')
            ->assertExitCode(0);

        $auditHb = SchedulerHeartbeat::where('command_name', 'nexatrade:audit-ledger')->firstOrFail();
        $this->assertSame(HeartbeatRunStatus::OK, $auditHb->last_status);
        $this->assertNotNull($auditHb->last_finished_at);
        $this->assertGreaterThanOrEqual(0, (int) $auditHb->last_duration_ms);
        $this->assertSame(0, $auditHb->consecutive_failures);
        $this->assertStringContainsString('[BALANCED]', (string) $auditHb->last_summary);

        // 2. Backdating last_finished_at to 3 days ago marks worker as STALE in getDashboardHealthSummary()
        $boostHb = SchedulerHeartbeat::where('command_name', 'nexatrade:expire-boosts')->firstOrFail();
        $boostHb->update([
            'last_finished_at' => now()->subDays(3),
        ]);

        $dashboard = $heartbeatService->getDashboardHealthSummary();
        $this->assertGreaterThanOrEqual(1, $dashboard['stale_count']);
        $this->assertFalse($dashboard['overall_healthy']);

        $boostEntry = collect($dashboard['workers'])->firstWhere('command_name', 'nexatrade:expire-boosts');
        $this->assertNotNull($boostEntry);
        $this->assertSame(WorkerHealthStatus::STALE, $boostEntry['health_status']);

        // 3. Failing worker increments consecutive_failures, records last_error, and rethrows exception
        try {
            $heartbeatService->track(
                commandName: 'nexatrade:reconcile-h2h',
                displayName: 'Digiflazz H2H Stale Order Reconciler',
                expectedIntervalMinutes: 5,
                callback: static function (): never {
                    throw new RuntimeException('Digiflazz upstream gateway unreachable');
                },
            );
            $this->fail('Expected RuntimeException to be rethrown by SchedulerHeartbeatService::track.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unreachable', $e->getMessage());
        }

        $failedHb = SchedulerHeartbeat::where('command_name', 'nexatrade:reconcile-h2h')->firstOrFail();
        $this->assertSame(HeartbeatRunStatus::FAILED, $failedHb->last_status);
        $this->assertSame(1, $failedHb->consecutive_failures);
        $this->assertStringContainsString('unreachable', (string) $failedHb->last_error);

        // 4. Admin Finance page renders System Worker Health table with STALE (OVERDUE) & FAILED badges and zero N+1 errors
        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertSee('System Worker Health (Scheduler Heartbeat)')
            ->assertSee('STALE (OVERDUE)')
            ->assertSee('Digiflazz upstream gateway unreachable');
    }
}
