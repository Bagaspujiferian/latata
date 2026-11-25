<?php

namespace App\Console\Commands;

use App\Enums\WorkerHealthStatus;
use App\Services\OpsAlertDispatcherService;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MonitorOperationalHealthCommand extends Command
{
    protected $signature = 'nexatrade:monitor-ops';

    protected $description = 'Inspect scheduler_heartbeats for STALE/FAILED workers and failed_jobs queue threshold, dispatching proactive operational alerts.';

    public function handle(
        SchedulerHeartbeatService $heartbeatService,
        OpsAlertDispatcherService $alertDispatcher,
    ): int {
        /** @var array{anomalies_count: int, alerts_sent: int, summary: string} $outcome */
        $outcome = $heartbeatService->track(
            commandName: 'nexatrade:monitor-ops',
            displayName: 'Proactive Operational Health & Alert Monitor',
            expectedIntervalMinutes: 5,
            callback: function () use ($heartbeatService, $alertDispatcher): array {
                $dashboard = $heartbeatService->getDashboardHealthSummary();
                $anomaliesCount = 0;
                $alertsSent = 0;

                foreach ($dashboard['workers'] as $worker) {
                    if ($worker['command_name'] === 'nexatrade:monitor-ops') {
                        continue;
                    }

                    $healthStatus = $worker['health_status'];
                    if (! in_array($healthStatus, [WorkerHealthStatus::STALE, WorkerHealthStatus::FAILED], true)) {
                        continue;
                    }

                    $anomaliesCount++;
                    $statusLabel = strtoupper($healthStatus->value);
                    $sent = $alertDispatcher->dispatch(
                        severity: OpsAlertDispatcherService::SEVERITY_CRITICAL,
                        title: "Scheduler Worker {$statusLabel}: {$worker['command_name']}",
                        message: "Worker terjadwal [{$worker['display_name']}] ({$worker['command_name']}) terdeteksi berstatus [{$statusLabel}].",
                        dedupKey: "heartbeat:{$worker['command_name']}",
                        context: [
                            'command_name' => $worker['command_name'],
                            'display_name' => $worker['display_name'],
                            'health_status' => $statusLabel,
                            'consecutive_failures' => $worker['consecutive_failures'],
                            'last_error' => $worker['last_error'] ?? '-',
                        ],
                    );

                    if ($sent) {
                        $alertsSent++;
                    }
                }

                $failedJobsCount = Schema::hasTable('failed_jobs')
                    ? (int) DB::table('failed_jobs')->count()
                    : 0;
                $threshold = max(1, (int) config('nexatrade.alerts.failed_jobs_threshold', 5));

                if ($failedJobsCount >= $threshold) {
                    $anomaliesCount++;
                    $sent = $alertDispatcher->dispatch(
                        severity: OpsAlertDispatcherService::SEVERITY_WARNING,
                        title: "Failed Jobs Queue Threshold Exceeded ({$failedJobsCount} >= {$threshold})",
                        message: "Terdapat {$failedJobsCount} job gagal di tabel failed_jobs (ambang batas peringatan: {$threshold}).",
                        dedupKey: 'failed_jobs_threshold',
                        context: [
                            'failed_jobs_count' => $failedJobsCount,
                            'threshold' => $threshold,
                        ],
                    );

                    if ($sent) {
                        $alertsSent++;
                    }
                }

                $summary = sprintf(
                    '[OPS-MONITOR] Checked=%d workers | FailedJobs=%d (Threshold=%d) | Anomalies=%d | AlertsDispatched=%d',
                    count($dashboard['workers']),
                    $failedJobsCount,
                    $threshold,
                    $anomaliesCount,
                    $alertsSent,
                );

                return [
                    'anomalies_count' => $anomaliesCount,
                    'alerts_sent' => $alertsSent,
                    'summary' => $summary,
                ];
            },
        );

        if ($outcome['anomalies_count'] > 0) {
            $this->warn($outcome['summary']);
        } else {
            $this->info($outcome['summary']);
        }

        return self::SUCCESS;
    }
}
