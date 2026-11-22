<?php

namespace App\Http\Controllers;

use App\Models\ConversationMessage;
use App\Models\SellerKycVerification;
use App\Services\SellerKycService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminKycController extends Controller
{
    public function __construct(
        private readonly SellerKycService $kycService,
    ) {}

    public function index(Request $request): View
    {
        $tab = $request->string('tab', 'pending')->toString();
        if (! in_array($tab, ['pending', 'approved', 'rejected', 'flagged_chat'], true)) {
            $tab = 'pending';
        }

        $pendingCount = SellerKycVerification::where('status', 'pending')->count();
        $approvedCount = SellerKycVerification::where('status', 'approved')->count();
        $rejectedCount = SellerKycVerification::where('status', 'rejected')->count();
        $flaggedChatCount = ConversationMessage::where('is_flagged_bypass', true)->count();

        $kycList = collect();
        $flaggedMessages = collect();

        if ($tab === 'flagged_chat') {
            $flaggedMessages = ConversationMessage::with([
                'sender',
                'conversation.buyer',
                'conversation.sellerProfile',
                'attachedProduct',
            ])
                ->where('is_flagged_bypass', true)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();
        } else {
            $kycList = SellerKycVerification::with(['user.sellerProfile', 'reviewer'])
                ->where('status', $tab)
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->get();
        }

        return view('admin.kyc', compact(
            'tab',
            'pendingCount',
            'approvedCount',
            'rejectedCount',
            'flaggedChatCount',
            'kycList',
            'flaggedMessages',
        ));
    }

    public function streamDocument(Request $request, SellerKycVerification $kyc, string $type): StreamedResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isAdmin() && (int) $user->id !== (int) $kyc->user_id)) {
            abort(403, 'Anda tidak berwenang mengakses berkas KYC privat ini.');
        }

        if (! in_array($type, ['id_card', 'selfie'], true)) {
            abort(404);
        }

        $privatePath = $type === 'id_card' ? $kyc->id_card_private_path : $kyc->selfie_private_path;
        if (
            ! $privatePath
            || ! str_starts_with($privatePath, 'private/kyc/')
            || str_contains($privatePath, '..')
            || ! Storage::disk('local')->exists($privatePath)
        ) {
            abort(404, 'Berkas KYC tidak ditemukan di penyimpanan privat.');
        }

        return Storage::disk('local')->response($privatePath, null, [
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, SellerKycVerification $kyc): RedirectResponse
    {
        try {
            $approved = $this->kycService->approveApplication($kyc->id, $request->user()->id);

            return redirect()->route('admin.kyc.index', ['tab' => 'approved'])
                ->with('success', "KYC #{$approved->id} disetujui! Akun {$approved->user->name} resmi di-upgrade ke Seller ({$approved->proposed_store_name}) lengkap dengan 7 hari jadwal operasional.");
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, SellerKycVerification $kyc): RedirectResponse
    {
        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        try {
            $rejected = $this->kycService->rejectApplication($kyc->id, $request->user()->id, $validated['rejection_reason']);

            return redirect()->route('admin.kyc.index', ['tab' => 'rejected'])
                ->with('success', "KYC #{$rejected->id} ditolak. Kunci NIK & pengajuan dilepas agar pemohon dapat mengajukan ulang dengan dokumen yang benar.");
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
