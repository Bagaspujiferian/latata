<?php

namespace App\Console\Commands;

use App\Services\FinanceAndLedgerAuditService;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Console\Command;

class AuditGlobalLedgerCommand extends Command
{
    protected $signature = 'nexatrade:audit-ledger {--triggered-by=artisan : Actor label saved on snapshot}';

    protected $description = 'Audit every user wallet bucket against immutable double-entry wallet_mutations and record a snapshot';

    public function handle(
        FinanceAndLedgerAuditService $auditService,
        SchedulerHeartbeatService $heartbeatService,
    ): int {
        $triggeredBy = (string) ($this->option('triggered-by') ?: 'artisan');

        /** @var array{status: string, summary: string} $outcome */
        $outcome = $heartbeatService->track(
            commandName: 'nexatrade:audit-ledger',
            displayName: 'Global Double-Entry Custody Ledger Auditor',
            expectedIntervalMinutes: 60,
            callback: function () use ($auditService, $triggeredBy): array {
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

                return [
                    'status' => (string) $snapshot->status,
                    'summary' => $summary,
                ];
            },
        );

        if ($outcome['status'] === 'BALANCED') {
            $this->info($outcome['summary']);

            return self::SUCCESS;
        }

        $this->error($outcome['summary']);

        return self::FAILURE;
    }
}
