<?php

namespace App\Console\Commands;

use App\Services\OrderStateAndSlaService;
use Illuminate\Console\Command;

class SettleCompletedOrdersCommand extends Command
{
    protected $signature = 'nexatrade:settle-completed';

    protected $description = 'Auto-complete DELIVERED orders past auto_complete_at and release escrow to seller available balances.';

    public function handle(OrderStateAndSlaService $service): int
    {
        $result = $service->settleCompletedOrders();
        $this->info("Auto-settled {$result['settled_count']} delivered order(s).");

        return self::SUCCESS;
    }
}
