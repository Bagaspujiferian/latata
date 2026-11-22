<?php

namespace App\Http\Controllers;

use App\Models\ModerationReport;
use App\Models\Order;
use App\Models\OrderWarranty;
use App\Models\WarrantyClaim;
use App\Services\AccountWarrantyService;
use App\Services\AdminCmsAndAuditService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WarrantyController extends Controller
{
    public function __construct(
        private readonly AccountWarrantyService $warrantyService,
        private readonly AdminCmsAndAuditService $cmsService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $warranties = OrderWarranty::with([
            'order.items.product.gameCategory.game',
            'sellerProfile',
            'claims.resolver',
        ])
            ->where('buyer_id', $user->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 WHEN status = 'claimed' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->get();

        $reasonLabels = WarrantyClaim::REASON_LABELS;

        return view('buyer.warranties', compact('user', 'warranties', 'reasonLabels'));
    }

    public function fileClaim(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'reason_category' => ['required', Rule::in(array_keys(WarrantyClaim::REASON_LABELS))],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:4'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:3072'],
        ]);

        try {
            $evidenceFiles = $request->file('evidence') ?? [];
            if (! is_array($evidenceFiles)) {
                $evidenceFiles = [$evidenceFiles];
            }

            $claim = $this->warrantyService->fileWarrantyClaim(
                orderId: $order->id,
                buyerId: $request->user()->id,
                data: $validated,
                evidenceFiles: array_values(array_filter($evidenceFiles)),
            );

            return redirect()->route('buyer.orders.show', $order->order_code)
                ->with('success', "Klaim Garansi Anti-Hackback {$claim->claim_code} berhasil diajukan! Bukti tersimpan aman di disk privat dan sedang ditinjau Admin Risiko.");
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function submitReport(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'target_type' => ['required', Rule::in(['product', 'seller', 'review'])],
            'target_id' => ['required', 'integer', 'min:1'],
            'reason_category' => ['required', Rule::in(array_keys(ModerationReport::REASON_LABELS))],
            'details' => ['required', 'string', 'min:10', 'max:1500'],
        ]);

        $report = $this->cmsService->submitReport($request->user()->id, $validated);

        return back()->with('success', "Laporan moderasi #{$report->id} diterima oleh Tim Keamanan NexaTrade. Terima kasih telah menjaga ekosistem aman.");
    }
}
