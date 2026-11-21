<?php

namespace App\Http\Controllers;

use App\Models\AdCreditPurchase;
use App\Models\DebtRepayment;
use App\Models\LedgerAuditSnapshot;
use App\Models\Order;
use App\Services\AdminCmsAndAuditService;
use App\Services\FinanceAndLedgerAuditService;
use App\Services\H2HReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AdminFinanceController extends Controller
{
    public function __construct(
        private readonly FinanceAndLedgerAuditService $financeService,
        private readonly H2HReconciliationService $h2hReconciliationService,
        private readonly AdminCmsAndAuditService $auditService,
    ) {}

    public function index(Request $request): View
    {
        $fromInput = $request->string('from')->toString();
        $toInput = $request->string('to')->toString();

        $from = $fromInput !== '' ? Carbon::parse($fromInput)->startOfDay() : null;
        $to = $toInput !== '' ? Carbon::parse($toInput)->endOfDay() : null;

        $summary = $this->financeService->getFinancePnLSummary($from, $to);

        $auditSnapshots = LedgerAuditSnapshot::orderByDesc('id')->limit(15)->get();
        $adCreditPurchases = AdCreditPurchase::with(['sellerProfile', 'package'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        $debtRepayments = DebtRepayment::with(['sellerProfile'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $pendingH2hCount = Order::where('delivery_method', 'api_h2h')
            ->where('status', 'PROCESSING')
            ->count();

        return view('admin.finance', compact(
            'summary',
            'auditSnapshots',
            'adCreditPurchases',
            'debtRepayments',
            'pendingH2hCount',
            'fromInput',
            'toInput',
        ));
    }

    public function runLedgerAudit(Request $request): RedirectResponse|JsonResponse
    {
        $admin = $request->user();
        $snapshot = $this->financeService->runGlobalLedgerAudit('admin:'.$admin->email);

        $this->auditService->recordAudit(
            adminId: $admin->id,
            action: 'FINANCE_LEDGER_AUDIT_RUN',
            entityType: 'ledger_audit_snapshot',
            entityId: (int) $snapshot->id,
            before: null,
            after: [
                'audit_code' => $snapshot->audit_code,
                'status' => $snapshot->status,
                'discrepancy_amount' => (string) $snapshot->discrepancy_amount,
            ],
            summary: "Audit Ledger Global {$snapshot->audit_code}: {$snapshot->status}",
        );

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'snapshot' => $snapshot]);
        }

        $flashType = $snapshot->status === 'BALANCED' ? 'success' : 'error';

        return back()->with(
            $flashType,
            "Audit Ledger Global ({$snapshot->audit_code}): {$snapshot->status} | Total Custody Rp ".number_format((float) $snapshot->total_custody_wallets, 0, ',', '.').' | Selisih Rp '.number_format((float) $snapshot->discrepancy_amount, 0, ',', '.').'.'
        );
    }

    public function runH2HReconcile(Request $request): RedirectResponse|JsonResponse
    {
        $minutes = max(0, (int) $request->input('minutes', 0));
        $report = $this->h2hReconciliationService->reconcileStaleOrders($minutes);

        $this->auditService->recordAudit(
            adminId: $request->user()->id,
            action: 'FINANCE_H2H_RECONCILE_RUN',
            entityType: 'h2h_reconciliation',
            entityId: null,
            before: null,
            after: $report,
            summary: "Rekonsiliasi H2H manual: checked={$report['checked']}, delivered={$report['delivered']}, refunded={$report['refunded']}",
        );

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'report' => $report]);
        }

        return back()->with(
            'success',
            "Rekonsiliasi H2H Selesai: {$report['checked']} dicek, {$report['delivered']} sukses (DELIVERED), {$report['refunded']} gagal (REFUNDED), {$report['still_pending']} masih pending."
        );
    }
}
