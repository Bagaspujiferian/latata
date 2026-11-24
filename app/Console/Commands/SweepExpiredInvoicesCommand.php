<?php

namespace App\Console\Commands;

use App\Services\OrderStateAndSlaService;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;

class SweepExpiredInvoicesCommand extends Command
{
    protected $signature = 'nexatrade:sweep-expired';

    protected $description = 'Sweep UNPAID expired payment invoices, release vault reservations, and refund hybrid wallet holds.';

    public function handle(
        OrderStateAndSlaService $service,
        SchedulerHeartbeatService $heartbeatService,
    ): int {
        $summary = $heartbeatService->track(
            commandName: 'nexatrade:sweep-expired',
            displayName: 'Expired Invoice & Vault Reservation Sweeper',
            expectedIntervalMinutes: 1,
            callback: function () use ($service): string {
                $result = $service->sweepExpiredInvoices();

                return "Swept {$result['expired_count']} expired invoice(s).";
            },
        );

        $this->info($summary);

        return self::SUCCESS;
    }
}
