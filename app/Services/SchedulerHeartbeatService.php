<?php

namespace App\Services;

use App\Enums\HeartbeatRunStatus;
use App\Enums\WorkerHealthStatus;
use App\Models\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SchedulerHeartbeatService
{
    /**
     * Registry of all scheduled background workers from routes/console.php.
     *
     * @var array<string, array{display_name: string, expected_interval_minutes: int}>
     */
    public const REGISTERED_WORKERS = [
        'nexatrade:sweep-expired' => [
            'display_name' => 'Expired Invoice & Vault Reservation Sweeper',
            'expected_interval_minutes' => 1,
        ],
        'nexatrade:settle-completed' => [
            'display_name' => '24h Escrow Auto-Release & Settlement Worker',
            'expected_interval_minutes' => 1,
        ],
        'nexatrade:reconcile-h2h' => [
            'display_name' => 'Digiflazz H2H Stale Order Reconciler',
            'expected_interval_minutes' => 5,
        ],
        'nexatrade:expire-boosts' => [
            'display_name' => 'Time-Boxed Featured Product Boost Sweeper',
            'expected_interval_minutes' => 15,
        ],
        'nexatrade:audit-ledger' => [
            'display_name' => 'Global Double-Entry Custody Ledger Auditor',
            'expected_interval_minutes' => 60,
        ],
        'nexatrade:monitor-ops' => [
            'display_name' => 'Proactive Operational Health & Alert Monitor',
            'expected_interval_minutes' => 5,
        ],
    ];

    /**
     * Centralized DRY wrapper to track Artisan command execution start, duration, status, and errors.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function track(
        string $commandName,
        string $displayName,
        int $expectedIntervalMinutes,
        callable $callback,
    ): mixed {
        if (! Schema::hasTable('scheduler_heartbeats')) {
            return $callback();
        }

        $startedAt = now();
        $heartbeat = SchedulerHeartbeat::firstOrNew(['command_name' => $commandName]);
        $previousFailures = (int) ($heartbeat->consecutive_failures ?? 0);

        $heartbeat->fill([
            'display_name' => $displayName,
            'expected_interval_minutes' => max(1, $expectedIntervalMinutes),
            'last_started_at' => $startedAt,
            'last_status' => HeartbeatRunStatus::RUNNING,
        ]);
        $heartbeat->save();

        $startNs = hrtime(true);

        try {
            $result = $callback();
            $durationMs = max(0, (int) round((hrtime(true) - $startNs) / 1_000_000));

            $summary = $heartbeat->last_summary;
            if (is_string($result) && trim($result) !== '') {
                $summary = mb_substr(trim($result), 0, 255);
            } elseif (is_array($result) && isset($result['summary']) && is_string($result['summary'])) {
                $summary = mb_substr(trim($result['summary']), 0, 255);
            }

            $heartbeat->update([
                'last_finished_at' => now(),
                'last_status' => HeartbeatRunStatus::OK,
                'last_duration_ms' => $durationMs,
                'last_summary' => $summary,
                'last_error' => null,
                'consecutive_failures' => 0,
            ]);

            return $result;
        } catch (Throwable $e) {
            $durationMs = max(0, (int) round((hrtime(true) - $startNs) / 1_000_000));

            $heartbeat->update([
                'last_finished_at' => now(),
                'last_status' => HeartbeatRunStatus::FAILED,
                'last_duration_ms' => $durationMs,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'consecutive_failures' => $previousFailures + 1,
            ]);

            throw $e;
        }
    }

    /**
     * Evaluate current health status of a scheduled worker heartbeat.
     */
    public function evaluateHealth(SchedulerHeartbeat $heartbeat, ?CarbonImmutable $now = null): WorkerHealthStatus
    {
        $referenceNow = $now ?? CarbonImmutable::now();
        $expectedInterval = max(1, (int) $heartbeat->expected_interval_minutes);

        if ($heartbeat->last_status === HeartbeatRunStatus::FAILED) {
            return WorkerHealthStatus::FAILED;
        }

        if ($heartbeat->last_status === HeartbeatRunStatus::RUNNING) {
            if ($heartbeat->last_started_at === null) {
                return WorkerHealthStatus::RUNNING;
            }

            $runningMinutes = $heartbeat->last_started_at->diffInMinutes($referenceNow);

            return $runningMinutes > max(15, $expectedInterval)
                ? WorkerHealthStatus::STALE
                : WorkerHealthStatus::RUNNING;
        }

        if ($heartbeat->last_finished_at === null) {
            return WorkerHealthStatus::NEVER_RUN;
        }

        $elapsedMinutes = $heartbeat->last_finished_at->diffInMinutes($referenceNow);
        if ($elapsedMinutes > ($expectedInterval * 2)) {
            return WorkerHealthStatus::STALE;
        }

        return WorkerHealthStatus::HEALTHY;
    }

    /**
     * Build merged dashboard summary combining DB rows with REGISTERED_WORKERS registry.
     *
     * @return array{
     *     total: int,
     *     healthy_count: int,
     *     stale_count: int,
     *     failed_count: int,
     *     running_count: int,
     *     never_run_count: int,
     *     overall_healthy: bool,
     *     workers: list<array{
     *         command_name: string,
     *         display_name: string,
     *         expected_interval_minutes: int,
     *         last_started_at: ?Carbon,
     *         last_finished_at: ?Carbon,
     *         last_duration_ms: ?int,
     *         last_summary: ?string,
     *         last_error: ?string,
     *         consecutive_failures: int,
     *         health_status: WorkerHealthStatus,
     *         health_label: string
     *     }>
     * }
     */
    public function getDashboardHealthSummary(?CarbonImmutable $now = null): array
    {
        $referenceNow = $now ?? CarbonImmutable::now();
        $dbRows = Schema::hasTable('scheduler_heartbeats')
            ? SchedulerHeartbeat::orderBy('id', 'asc')->get()->keyBy('command_name')
            : collect();

        $mergedCommands = array_keys(self::REGISTERED_WORKERS);
        foreach ($dbRows->keys() as $dbCmd) {
            $cmdStr = (string) $dbCmd;
            if (! in_array($cmdStr, $mergedCommands, true)) {
                $mergedCommands[] = $cmdStr;
            }
        }

        $workers = [];
        $healthyCount = 0;
        $staleCount = 0;
        $failedCount = 0;
        $runningCount = 0;
        $neverRunCount = 0;

        foreach ($mergedCommands as $cmd) {
            $meta = self::REGISTERED_WORKERS[$cmd] ?? [
                'display_name' => $cmd,
                'expected_interval_minutes' => 15,
            ];

            /** @var SchedulerHeartbeat|null $row */
            $row = $dbRows->get($cmd);
            if (! $row) {
                $row = new SchedulerHeartbeat([
                    'command_name' => $cmd,
                    'display_name' => $meta['display_name'],
                    'expected_interval_minutes' => $meta['expected_interval_minutes'],
                    'last_status' => HeartbeatRunStatus::OK,
                    'last_started_at' => null,
                    'last_finished_at' => null,
                    'last_duration_ms' => null,
                    'last_summary' => null,
                    'last_error' => null,
                    'consecutive_failures' => 0,
                ]);
            }

            $health = $this->evaluateHealth($row, $referenceNow);

            match ($health) {
                WorkerHealthStatus::HEALTHY => $healthyCount++,
                WorkerHealthStatus::STALE => $staleCount++,
                WorkerHealthStatus::FAILED => $failedCount++,
                WorkerHealthStatus::RUNNING => $runningCount++,
                WorkerHealthStatus::NEVER_RUN => $neverRunCount++,
            };

            $workers[] = [
                'command_name' => (string) $row->command_name,
                'display_name' => (string) $row->display_name,
                'expected_interval_minutes' => (int) $row->expected_interval_minutes,
                'last_started_at' => $row->last_started_at,
                'last_finished_at' => $row->last_finished_at,
                'last_duration_ms' => $row->last_duration_ms !== null ? (int) $row->last_duration_ms : null,
                'last_summary' => $row->last_summary,
                'last_error' => $row->last_error,
                'consecutive_failures' => (int) $row->consecutive_failures,
                'health_status' => $health,
                'health_label' => $health->label(),
            ];
        }

        return [
            'total' => count($workers),
            'healthy_count' => $healthyCount,
            'stale_count' => $staleCount,
            'failed_count' => $failedCount,
            'running_count' => $runningCount,
            'never_run_count' => $neverRunCount,
            'overall_healthy' => $staleCount === 0 && $failedCount === 0 && $neverRunCount === 0,
            'workers' => $workers,
        ];
    }
}
