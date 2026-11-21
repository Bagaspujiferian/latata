<?php

namespace App\Console\Commands;

use App\Services\FinanceAndLedgerAuditService;
use Illuminate\Console\Command;

class AuditGlobalLedgerCommand extends Command
{
    protected $signature = 'nexatrade:audit-ledger {--triggered-by=artisan : Actor label saved on snapshot}';

    protected $description = 'Audit every user wallet bucket against immutable double-entry wallet_mutations and record a snapshot';

    public function handle(FinanceAndLedgerAuditService $auditService): int
    {
        $triggeredBy = (string) ($this->option('triggered-by') ?: 'artisan');
        $snapshot = $auditService->runGlobalLedgerAudit($triggeredBy);

        $summary = sprintf(
            '[%s] Audit %s | Users=%d | Custody Wallets=Rp %s | Ledger Net=Rp %s | Discrepancy=Rp %s',
            $snapshot->status,
            $snapshot->audit_code,
            $snapshot->users_checked,
            number_format((float) $snapshot->total_custody_wallets, 2, ',', '.'),
            number_format((float) $snapshot->total_ledger_net, 2, ',', '.'),
            number_format((float) $snapshot->discrepancy_amount, 2, ',', '.'),
        );

        if ($snapshot->status === 'BALANCED') {
            $this->info($summary);

            return self::SUCCESS;
        }

        $this->error($summary);

        return self::FAILURE;
    }
}
