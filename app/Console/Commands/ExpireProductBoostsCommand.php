<?php

namespace App\Console\Commands;

use App\Services\AdCreditAndStoreLevelService;
use Illuminate\Console\Command;

class ExpireProductBoostsCommand extends Command
{
    protected $signature = 'nexatrade:expire-boosts';

    protected $description = 'Expire finished time-boxed product boosts and unset is_featured when no active boost remains';

    public function handle(AdCreditAndStoreLevelService $adCreditService): int
    {
        $expiredCount = $adCreditService->sweepExpiredBoosts();
        $this->info("Product boosts sweep complete: expired={$expiredCount}");

        return self::SUCCESS;
    }
}
