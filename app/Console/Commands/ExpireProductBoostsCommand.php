<?php

namespace App\Console\Commands;

use App\Services\AdCreditAndStoreLevelService;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;

class ExpireProductBoostsCommand extends Command
{
    protected $signature = 'nexatrade:expire-boosts';

    protected $description = 'Expire finished time-boxed product boosts and unset is_featured when no active boost remains';

    public function handle(
        AdCreditAndStoreLevelService $adCreditService,
        SchedulerHeartbeatService $heartbeatService,
    ): int {
        $summary = $heartbeatService->track(
            commandName: 'nexatrade:expire-boosts',
            displayName: 'Time-Boxed Featured Product Boost Sweeper',
            expectedIntervalMinutes: 15,
            callback: function () use ($adCreditService): string {
                $expiredCount = $adCreditService->sweepExpiredBoosts();

                return "Product boosts sweep complete: expired={$expiredCount}";
            },
        );

        $this->info($summary);

        return self::SUCCESS;
    }
}
