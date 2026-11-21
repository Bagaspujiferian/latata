<?php

namespace App\Console\Commands;

use App\Services\OrderStateAndSlaService;
use Illuminate\Console\Command;

class SweepExpiredInvoicesCommand extends Command
{
    protected $signature = 'nexatrade:sweep-expired';

    protected $description = 'Sweep UNPAID expired payment invoices, release vault reservations, and refund hybrid wallet holds.';

    public function handle(OrderStateAndSlaService $service): int
    {
        $result = $service->sweepExpiredInvoices();
        $this->info("Swept {$result['expired_count']} expired invoice(s).");

        return self::SUCCESS;
    }
}
