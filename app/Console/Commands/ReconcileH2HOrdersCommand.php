<?php

namespace App\Console\Commands;

use App\Services\H2HReconciliationService;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;

class ReconcileH2HOrdersCommand extends Command
{
    protected $signature = 'nexatrade:reconcile-h2h {--minutes=5 : Minimum age in minutes for stale PROCESSING H2H orders} {--dry-run : Inspect stale orders without mutating state}';

    protected $description = 'Reconcile stale api_h2h orders stuck in PROCESSING using their exact h2h_reference_id';

    public function handle(
        H2HReconciliationService $reconciliationService,
        SchedulerHeartbeatService $heartbeatService,
    ): int {
        $minutes = max(0, (int) $this->option('minutes'));
        $dryRun = (bool) $this->option('dry-run');

        $summary = $heartbeatService->track(
            commandName: 'nexatrade:reconcile-h2h',
            displayName: 'Digiflazz H2H Stale Order Reconciler',
            expectedIntervalMinutes: 5,
            callback: function () use ($reconciliationService, $minutes, $dryRun): string {
                $report = $reconciliationService->reconcileStaleOrders($minutes, $dryRun);

                return sprintf(
                    'H2H Reconciliation complete: checked=%d, delivered=%d, refunded=%d, pending=%d, escalated=%d',
                    $report['checked'],
                    $report['delivered'],
                    $report['refunded'],
                    $report['still_pending'],
                    $report['escalated'],
                );
            },
        );

        $this->info($summary);

        return self::SUCCESS;
    }
}
