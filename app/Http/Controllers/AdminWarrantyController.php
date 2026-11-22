<?php

namespace App\Http\Controllers;

use App\Models\WarrantyClaim;
use App\Services\AccountWarrantyService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminWarrantyController extends Controller
{
    public function __construct(
        private readonly AccountWarrantyService $warrantyService,
    ) {}

    public function index(Request $request): View
    {
        $statusFilter = $request->string('status', 'open')->toString();
        if (! in_array($statusFilter, ['open', 'approved_refund', 'rejected', 'all'], true)) {
            $statusFilter = 'open';
        }

        $query = WarrantyClaim::with([
            'warranty',
            'order.items',
            'buyer.wallet',
            'seller.wallet',
            'sellerProfile',
            'resolver',
        ])->orderByDesc('id');

        if ($statusFilter === 'open') {
            $query->whereIn('status', ['open', 'investigating']);
        } elseif ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $claims = $query->get();
        $openCount = WarrantyClaim::whereIn('status', ['open', 'investigating'])->count();

        return view('admin.warranties', compact('claims', 'statusFilter', 'openCount'));
    }

    public function streamEvidence(Request $request, WarrantyClaim $claim, int $index): StreamedResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isAdmin() && (int) $user->id !== (int) $claim->buyer_id)) {
            abort(403, 'Anda tidak berwenang mengakses bukti klaim garansi privat ini.');
        }

        $paths = is_array($claim->evidence_paths) ? $claim->evidence_paths : [];
        if (! isset($paths[$index])) {
            abort(404);
        }

        $privatePath = (string) $paths[$index];
        if (
            ! str_starts_with($privatePath, 'private/warranty-claims/')
            || str_contains($privatePath, '..')
            || ! Storage::disk('local')->exists($privatePath)
        ) {
            abort(404, 'File bukti tidak ditemukan pada penyimpanan privat.');
        }

        return Storage::disk('local')->response($privatePath, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function resolve(Request $request, WarrantyClaim $claim): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:APPROVE_REFUND,REJECT'],
            'admin_resolution_notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $resolved = $this->warrantyService->resolveWarrantyClaim(
                claimId: $claim->id,
                adminId: $request->user()->id,
                decision: $validated['decision'],
                adminNotes: $validated['admin_resolution_notes'],
            );

            $summary = $resolved->status === 'approved_refund'
                ? "Klaim {$resolved->claim_code} DISETUJUI! Pembeli dikreditkan 100%, clawback deposit Rp ".number_format((float) $resolved->clawback_from_deposit, 0, ',', '.').', saldo aktif Rp '.number_format((float) $resolved->clawback_from_available, 0, ',', '.').', hutang tercatat Rp '.number_format((float) $resolved->recorded_seller_debt, 0, ',', '.').'.'
                : "Klaim {$resolved->claim_code} DITOLAK dan garansi dipulihkan sesuai masa berlaku.";

            return back()->with('success', $summary);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
