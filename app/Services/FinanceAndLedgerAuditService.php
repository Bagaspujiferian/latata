<?php

namespace App\Services;

use App\Models\AdCreditPurchase;
use App\Models\DebtRepayment;
use App\Models\LedgerAuditSnapshot;
use App\Models\Order;
use App\Models\PaymentInvoice;
use App\Models\WalletTopUp;
use App\Models\WarrantyClaim;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceAndLedgerAuditService
{
    /**
     * Reconcile every user's wallets buckets (pending, available, security_deposit)
     * against theoretical double-entry sums in wallet_mutations and persist a snapshot.
     */
    public function runGlobalLedgerAudit(string $triggeredBy = 'artisan'): LedgerAuditSnapshot
    {
        $wallets = DB::table('wallets')
            ->join('users', 'users.id', '=', 'wallets.user_id')
            ->select([
                'wallets.user_id',
                'users.name',
                'users.email',
                'wallets.pending_balance',
                'wallets.available_balance',
                'wallets.security_deposit_balance',
            ])
            ->orderBy('wallets.user_id', 'asc')
            ->get();

        $ledgerRows = DB::table('wallet_mutations')
            ->select([
                'user_id',
                'balance_bucket',
                DB::raw("SUM(CASE WHEN direction = 'CREDIT' THEN ROUND(amount * 100) ELSE -ROUND(amount * 100) END) AS net_cents"),
            ])
            ->groupBy('user_id', 'balance_bucket')
            ->get();

        $ledgerMap = [];
        foreach ($ledgerRows as $row) {
            $ledgerMap[(int) $row->user_id][(string) $row->balance_bucket] = (int) $row->net_cents;
        }

        $totalPendingCents = 0;
        $totalAvailableCents = 0;
        $totalDepositCents = 0;
        $totalLedgerNetCents = 0;
        $absDiscrepancyCents = 0;
        $discrepancyDetails = [];

        $bucketCols = [
            'pending' => 'pending_balance',
            'available' => 'available_balance',
            'security_deposit' => 'security_deposit_balance',
        ];

        foreach ($wallets as $wallet) {
            $uid = (int) $wallet->user_id;
            $pendingCents = (int) round((float) $wallet->pending_balance * 100);
            $availableCents = (int) round((float) $wallet->available_balance * 100);
            $depositCents = (int) round((float) $wallet->security_deposit_balance * 100);

            $totalPendingCents += $pendingCents;
            $totalAvailableCents += $availableCents;
            $totalDepositCents += $depositCents;

            $userDiscrepancies = [];
            foreach ($bucketCols as $bucket => $col) {
                $actualCents = (int) round((float) $wallet->{$col} * 100);
                $expectedCents = (int) ($ledgerMap[$uid][$bucket] ?? 0);
                $totalLedgerNetCents += $expectedCents;

                if ($actualCents !== $expectedCents) {
                    $diffCents = $actualCents - $expectedCents;
                    $absDiscrepancyCents += abs($diffCents);
                    $userDiscrepancies[$bucket] = [
                        'wallet_balance' => round($actualCents / 100, 2),
                        'ledger_theoretical' => round($expectedCents / 100, 2),
                        'difference' => round($diffCents / 100, 2),
                    ];
                }
            }

            if (! empty($userDiscrepancies)) {
                $discrepancyDetails[] = [
                    'user_id' => $uid,
                    'name' => $wallet->name,
                    'email' => $wallet->email,
                    'buckets' => $userDiscrepancies,
                ];
            }
        }

        $totalCustodyCents = $totalPendingCents + $totalAvailableCents + $totalDepositCents;
        $status = ($absDiscrepancyCents === 0 && empty($discrepancyDetails)) ? 'BALANCED' : 'DISCREPANCY';
        $auditCode = 'AUD-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));

        return LedgerAuditSnapshot::create([
            'audit_code' => $auditCode,
            'status' => $status,
            'total_wallets_pending' => number_format($totalPendingCents / 100, 2, '.', ''),
            'total_wallets_available' => number_format($totalAvailableCents / 100, 2, '.', ''),
            'total_wallets_deposit' => number_format($totalDepositCents / 100, 2, '.', ''),
            'total_custody_wallets' => number_format($totalCustodyCents / 100, 2, '.', ''),
            'total_ledger_net' => number_format($totalLedgerNetCents / 100, 2, '.', ''),
            'discrepancy_amount' => number_format($absDiscrepancyCents / 100, 2, '.', ''),
            'users_checked' => $wallets->count(),
            'discrepancy_details' => empty($discrepancyDetails) ? null : $discrepancyDetails,
            'triggered_by' => mb_substr($triggeredBy, 0, 60),
            'created_at' => now(),
        ]);
    }

    /**
     * Compute comprehensive real-time corporate Finance P&L & Custody Liability summary.
     *
     * @return array<string, mixed>
     */
    public function getFinancePnLSummary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $gmvQuery = Order::whereIn('status', ['PAID_ESCROW', 'PROCESSING', 'DELIVERED', 'COMPLETED']);
        $completedOrdersQuery = Order::where('status', 'COMPLETED');
        $adCreditsQuery = AdCreditPurchase::where('status', 'PAID');
        $withdrawalFeeQuery = WithdrawalRequest::where('status', 'APPROVED_PAID');
        $warrantyDebtQuery = WarrantyClaim::where('status', 'approved_refund');
        $debtRepayQuery = DebtRepayment::query();
        $paidInvoicesMdrQuery = PaymentInvoice::where('status', 'PAID');
        $paidTopupsMdrQuery = WalletTopUp::where('status', 'PAID');

        if ($from !== null) {
            $gmvQuery->where('created_at', '>=', $from);
            $completedOrdersQuery->where('completed_at', '>=', $from);
            $adCreditsQuery->where('created_at', '>=', $from);
            $withdrawalFeeQuery->where('processed_at', '>=', $from);
            $warrantyDebtQuery->where('resolved_at', '>=', $from);
            $debtRepayQuery->where('created_at', '>=', $from);
            $paidInvoicesMdrQuery->where('paid_at', '>=', $from);
            $paidTopupsMdrQuery->where('paid_at', '>=', $from);
        }

        if ($to !== null) {
            $gmvQuery->where('created_at', '<=', $to);
            $completedOrdersQuery->where('completed_at', '<=', $to);
            $adCreditsQuery->where('created_at', '<=', $to);
            $withdrawalFeeQuery->where('processed_at', '<=', $to);
            $warrantyDebtQuery->where('resolved_at', '<=', $to);
            $debtRepayQuery->where('created_at', '<=', $to);
            $paidInvoicesMdrQuery->where('paid_at', '<=', $to);
            $paidTopupsMdrQuery->where('paid_at', '<=', $to);
        }

        $gmv = round((float) $gmvQuery->sum('gross_amount'), 2);
        $grossCommission = round((float) (clone $completedOrdersQuery)->sum('platform_commission'), 2);
        $adCreditsRevenue = round((float) $adCreditsQuery->sum('amount_paid'), 2);
        $withdrawalFeeRevenue = round((float) $withdrawalFeeQuery->sum('admin_fee'), 2);
        $platformVoucherSubsidy = round((float) (clone $completedOrdersQuery)->sum('platform_discount_amount'), 2);

        $totalInvoiceMdrCost = round((float) $paidInvoicesMdrQuery->sum('gateway_mdr_fee'), 2);
        $totalTopupMdrCost = round((float) $paidTopupsMdrQuery->sum('gateway_mdr_fee'), 2);
        $totalGatewayMdrCost = round($totalInvoiceMdrCost + $totalTopupMdrCost, 2);

        $grossHackbackDebt = round((float) $warrantyDebtQuery->sum('recorded_seller_debt'), 2);
        $recoveredDebt = round((float) $debtRepayQuery->sum('amount'), 2);
        $netHackbackExposure = round(max(0.0, $grossHackbackDebt - $recoveredDebt), 2);

        $grossRevenueTotal = round($grossCommission + $adCreditsRevenue + $withdrawalFeeRevenue, 2);
        $totalExpenses = round($platformVoucherSubsidy + $netHackbackExposure, 2);
        $netPlatformRevenue = round($grossRevenueTotal - $totalExpenses, 2);
        $trueNetOperatingProfit = round($grossRevenueTotal - $platformVoucherSubsidy - $totalGatewayMdrCost, 2);

        $custodyRow = DB::table('wallets')
            ->selectRaw('COALESCE(SUM(pending_balance), 0) AS pending_total')
            ->selectRaw('COALESCE(SUM(available_balance), 0) AS available_total')
            ->selectRaw('COALESCE(SUM(security_deposit_balance), 0) AS deposit_total')
            ->selectRaw('COALESCE(SUM(clawback_debt_balance), 0) AS outstanding_debt_total')
            ->first();

        $custodyPending = round((float) ($custodyRow->pending_total ?? 0), 2);
        $custodyAvailable = round((float) ($custodyRow->available_total ?? 0), 2);
        $custodyDeposit = round((float) ($custodyRow->deposit_total ?? 0), 2);
        $custodyLiability = round($custodyPending + $custodyAvailable + $custodyDeposit, 2);
        $outstandingSellerDebt = round((float) ($custodyRow->outstanding_debt_total ?? 0), 2);

        $latestAudit = LedgerAuditSnapshot::orderByDesc('id')->first();

        return [
            'gmv' => $gmv,
            'gross_commission_revenue' => $grossCommission,
            'ad_credits_revenue' => $adCreditsRevenue,
            'withdrawal_fee_revenue' => $withdrawalFeeRevenue,
            'gross_revenue_total' => $grossRevenueTotal,
            'gross_platform_revenue' => $grossRevenueTotal,
            'platform_voucher_subsidy_expense' => $platformVoucherSubsidy,
            'total_voucher_subsidy' => $platformVoucherSubsidy,
            'total_invoice_mdr_cost' => $totalInvoiceMdrCost,
            'total_topup_mdr_cost' => $totalTopupMdrCost,
            'total_gateway_mdr_cost' => $totalGatewayMdrCost,
            'true_net_operating_profit' => $trueNetOperatingProfit,
            'gross_hackback_debt' => $grossHackbackDebt,
            'recovered_debt' => $recoveredDebt,
            'net_hackback_exposure' => $netHackbackExposure,
            'total_expenses' => $totalExpenses,
            'net_platform_revenue' => $netPlatformRevenue,
            'custody_pending' => $custodyPending,
            'custody_available' => $custodyAvailable,
            'custody_deposit' => $custodyDeposit,
            'custody_liability' => $custodyLiability,
            'outstanding_seller_debt' => $outstandingSellerDebt,
            'latest_audit' => $latestAudit,
        ];
    }
}
