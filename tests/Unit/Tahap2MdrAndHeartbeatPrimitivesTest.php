<?php

namespace Tests\Unit;

use App\Enums\HeartbeatRunStatus;
use App\Enums\WorkerHealthStatus;
use App\Models\SchedulerHeartbeat;
use App\Services\PaymentMdrCalculatorService;
use App\Services\SchedulerHeartbeatService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class Tahap2MdrAndHeartbeatPrimitivesTest extends TestCase
{
    public function test_payment_mdr_calculator_computes_exact_rates_for_all_channels_and_fallbacks(): void
    {
        $calculator = new PaymentMdrCalculatorService;

        // 1. QRIS: 0.7%
        $qris = $calculator->calculate('qris', 100000.00);
        $this->assertSame('qris', $qris->normalizedChannel);
        $this->assertSame(100000.00, $qris->grossAmount);
        $this->assertSame(700.00, $qris->mdrFee);
        $this->assertSame(99300.00, $qris->netSettlementAmount);

        // 2. Bank Transfer / Virtual Account: Flat Rp 4.000
        $va = $calculator->calculate('bank_transfer', 200000.00);
        $this->assertSame('bank_transfer', $va->normalizedChannel);
        $this->assertSame(4000.00, $va->mdrFee);
        $this->assertSame(196000.00, $va->netSettlementAmount);

        // 3. GoPay / E-Wallet: 2.0%
        $gopay = $calculator->calculate('GoPay', 150000.00);
        $this->assertSame('gopay', $gopay->normalizedChannel);
        $this->assertSame(3000.00, $gopay->mdrFee);
        $this->assertSame(147000.00, $gopay->netSettlementAmount);

        // 4. Credit Card: 2.9% + Rp 2.000
        $cc = $calculator->calculate('credit_card', 100000.00);
        $this->assertSame('credit_card', $cc->normalizedChannel);
        $this->assertSame(4900.00, $cc->mdrFee);
        $this->assertSame(95100.00, $cc->netSettlementAmount);

        // 5. Wallet / Simulation: Rp 0
        $wallet = $calculator->calculate('wallet', 250000.00);
        $this->assertSame('wallet', $wallet->normalizedChannel);
        $this->assertSame(0.00, $wallet->mdrFee);
        $this->assertSame(250000.00, $wallet->netSettlementAmount);

        $sim = $calculator->calculate('simulation', 100000.00);
        $this->assertSame('simulation', $sim->normalizedChannel);
        $this->assertSame(0.00, $sim->mdrFee);

        // 6. Hybrid channel alias normalization ('hybrid_wallet_qris' -> 'qris')
        $hybridQris = $calculator->calculate('hybrid_wallet_qris', 50000.00);
        $this->assertSame('qris', $hybridQris->normalizedChannel);
        $this->assertSame(350.00, $hybridQris->mdrFee);

        // 7. Unknown channel falls back to 'midtrans_snap' (Rp 4.000 flat)
        $fallback = $calculator->calculate('unknown_channel_xyz', 100000.00);
        $this->assertSame('midtrans_snap', $fallback->normalizedChannel);
        $this->assertSame(4000.00, $fallback->mdrFee);
        $this->assertSame(96000.00, $fallback->netSettlementAmount);

        // 8. Zero gross amount returns 0.00 MDR fee even for flat channels
        $zero = $calculator->calculate('bank_transfer', 0.00);
        $this->assertSame(0.00, $zero->mdrFee);
        $this->assertSame(0.00, $zero->netSettlementAmount);
    }

    public function test_scheduler_heartbeat_evaluate_health_covers_all_five_worker_states(): void
    {
        $service = new SchedulerHeartbeatService;
        $now = CarbonImmutable::parse('2026-11-24 12:00:00');

        // 1. HEALTHY: finished 10 minutes ago on a 15-minute interval (<= 30m threshold)
        $healthyHb = new SchedulerHeartbeat([
            'command_name' => 'nexatrade:expire-boosts',
            'display_name' => 'Boost Sweeper',
            'expected_interval_minutes' => 15,
            'last_status' => HeartbeatRunStatus::OK,
            'last_finished_at' => $now->subMinutes(10),
        ]);
        $this->assertSame(WorkerHealthStatus::HEALTHY, $service->evaluateHealth($healthyHb, $now));

        // 2. STALE: finished 35 minutes ago on a 15-minute interval (> 2x15 = 30m threshold)
        $staleHb = new SchedulerHeartbeat([
            'command_name' => 'nexatrade:expire-boosts',
            'display_name' => 'Boost Sweeper',
            'expected_interval_minutes' => 15,
            'last_status' => HeartbeatRunStatus::OK,
            'last_finished_at' => $now->subMinutes(35),
        ]);
        $this->assertSame(WorkerHealthStatus::STALE, $service->evaluateHealth($staleHb, $now));

        // 3. FAILED: last_status is FAILED regardless of finish timestamp
        $failedHb = new SchedulerHeartbeat([
            'command_name' => 'nexatrade:reconcile-h2h',
            'display_name' => 'H2H Reconciler',
            'expected_interval_minutes' => 5,
            'last_status' => HeartbeatRunStatus::FAILED,
            'last_finished_at' => $now->subMinute(),
        ]);
        $this->assertSame(WorkerHealthStatus::FAILED, $service->evaluateHealth($failedHb, $now));

        // 4. RUNNING: started 2 minutes ago (not stuck yet)
        $runningHb = new SchedulerHeartbeat([
            'command_name' => 'nexatrade:audit-ledger',
            'display_name' => 'Ledger Auditor',
            'expected_interval_minutes' => 60,
            'last_status' => HeartbeatRunStatus::RUNNING,
            'last_started_at' => $now->subMinutes(2),
            'last_finished_at' => null,
        ]);
        $this->assertSame(WorkerHealthStatus::RUNNING, $service->evaluateHealth($runningHb, $now));

        // 5. NEVER_RUN: status OK but last_finished_at is null
        $neverRunHb = new SchedulerHeartbeat([
            'command_name' => 'nexatrade:sweep-expired',
            'display_name' => 'Invoice Sweeper',
            'expected_interval_minutes' => 1,
            'last_status' => HeartbeatRunStatus::OK,
            'last_finished_at' => null,
        ]);
        $this->assertSame(WorkerHealthStatus::NEVER_RUN, $service->evaluateHealth($neverRunHb, $now));
    }
}
