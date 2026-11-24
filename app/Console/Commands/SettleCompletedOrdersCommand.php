<?php

namespace App\Console\Commands;

use App\Services\OrderStateAndSlaService;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;

class SettleCompletedOrdersCommand extends Command
{
    protected $signature = 'nexatrade:settle-completed';

    protected $description = 'Auto-complete DELIVERED orders past auto_complete_at and release escrow to seller available balances.';

    public function handle(
        OrderStateAndSlaService $service,
        SchedulerHeartbeatService $heartbeatService,
    ): int {
        $summary = $heartbeatService->track(
            commandName: 'nexatrade:settle-completed',
            displayName: '24h Escrow Auto-Release & Settlement Worker',
            expectedIntervalMinutes: 1,
            callback: function () use ($service): string {
                $result = $service->settleCompletedOrders();

                return "Auto-settled {$result['settled_count']} delivered order(s).";
            },
        );

        $this->info($summary);

        return self::SUCCESS;
    }
}
