<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Voucher;
use App\Services\AccountWarrantyService;
use App\Services\AdminCmsAndAuditService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerPromoController extends Controller
{
    public function __construct(
        private readonly AdminCmsAndAuditService $cmsService,
        private readonly AccountWarrantyService $warrantyService,
    ) {}

    public function vouchers(Request $request): View
    {
        $user = $request->user()->load('sellerProfile');

        $vouchers = Voucher::with(['game'])
            ->withSum(['usages as applied_discount_total' => fn ($q) => $q->where('status', 'applied')], 'discount_amount')
            ->where('issuer_type', 'seller')
            ->where('seller_id', $user->id)
            ->orderByDesc('is_active')
            ->orderByDesc('id')
            ->get();

        $games = Game::where('is_active', true)->orderBy('name')->get();

        return view('seller.vouchers', compact('user', 'vouchers', 'games'));
    }

    public function storeVoucher(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:40'],
            'title' => ['required', 'string', 'min:4', 'max:120'],
            'game_id' => ['nullable', 'integer', 'exists:games,id'],
            'discount_type' => ['required', 'in:fixed,percent'],
            'discount_value' => ['required', 'numeric', 'min:1'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:1000'],
            'min_purchase_amount' => ['nullable', 'numeric', 'min:0'],
            'quota_total' => ['required', 'integer', 'min:1', 'max:10000'],
            'max_uses_per_user' => ['nullable', 'integer', 'min:1', 'max:20'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        try {
            $voucher = $this->cmsService->createVoucher(
                actorId: $request->user()->id,
                issuerType: 'seller',
                data: $validated,
                sellerId: $request->user()->id,
            );

            return back()->with('success', "Voucher Toko [{$voucher->code}] berhasil diterbitkan! Diskon ditanggung dari margin toko Anda.");
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function toggleVoucher(Request $request, Voucher $voucher): RedirectResponse
    {
        try {
            $updated = $this->cmsService->toggleVoucher($request->user()->id, $voucher->id, false);
            $label = $updated->is_active ? 'diaktifkan' : 'dinonaktifkan';

            return back()->with('success', "Voucher Toko [{$updated->code}] berhasil {$label}.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function lockDeposit(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:10000', 'max:100000000'],
        ]);

        try {
            $this->warrantyService->transferAvailableToSecurityDeposit($request->user()->id, (float) $validated['amount']);

            return back()->with('success', 'Dana Rp '.number_format((float) $validated['amount'], 0, ',', '.').' berhasil dikunci ke Deposit Jaminan Anti-Hackback.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
