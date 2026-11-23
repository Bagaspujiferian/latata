<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateKycNikException;
use App\Models\SellerKycVerification;
use App\Services\SecureImageUploadService;
use App\Services\SellerKycService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerKycController extends Controller
{
    public function __construct(
        private readonly SellerKycService $kycService,
        private readonly SecureImageUploadService $imageUploadService,
    ) {}

    public function show(Request $request): View
    {
        $user = $this->requireUser($request)->load('sellerProfile');

        $latestKyc = SellerKycVerification::with('reviewer')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        $kycHistory = SellerKycVerification::where('user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        return view('buyer.open-store', compact('user', 'latestKyc', 'kycHistory'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'proposed_store_name' => ['required', 'string', 'min:3', 'max:100'],
            'proposed_store_slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9\-]+$/i'],
            'proposed_store_tagline' => ['nullable', 'string', 'max:150'],
            'full_legal_name' => ['required', 'string', 'min:3', 'max:120'],
            'nik' => ['required', 'string', 'regex:/^\d{16}$/'],
            'id_card_file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:3072', $this->imageUploadService->validationRule(3072)],
            'selfie_file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:3072', $this->imageUploadService->validationRule(3072)],
        ], [
            'nik.regex' => 'NIK wajib terdiri dari tepat 16 digit angka sesuai e-KTP.',
            'proposed_store_slug.regex' => 'URL slug toko hanya boleh berisi huruf, angka, dan tanda hubung (-).',
        ]);

        try {
            $kyc = $this->kycService->submitApplication(
                userId: $this->requireUser($request)->id,
                data: $validated,
                idCardFile: $request->file('id_card_file'),
                selfieFile: $request->file('selfie_file'),
            );

            return redirect()->route('buyer.open-store')
                ->with('success', "Pengajuan Buka Toko \"{$kyc->proposed_store_name}\" berhasil dikirim! Berkas KTP & Selfie tersimpan terenkripsi di penyimpanan privat dan sedang ditinjau Admin.");
        } catch (DuplicateKycNikException|DomainException $e) {
            return back()->withInput($request->except(['nik', 'id_card_file', 'selfie_file']))
                ->with('error', $e->getMessage());
        }
    }
}
