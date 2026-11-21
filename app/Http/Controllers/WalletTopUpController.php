<?php

namespace App\Http\Controllers;

use App\Models\WalletTopUp;
use App\Services\WalletTopUpService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WalletTopUpController extends Controller
{
    public function __construct(
        private readonly WalletTopUpService $topUpService,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:10000', 'max:10000000'],
            'payment_channel' => ['nullable', 'string', 'in:midtrans_snap,qris,bca_va,bri_va,gopay'],
        ]);

        try {
            $topup = $this->topUpService->createTopUp(
                userId: $request->user()->id,
                amount: (float) $validated['amount'],
                channel: (string) ($validated['payment_channel'] ?? 'midtrans_snap'),
            );

            return redirect()
                ->route('buyer.wallet.topup.show', $topup->topup_code)
                ->with('success', "Tagihan Top-Up {$topup->topup_code} dibuat. Selesaikan pembayaran sebelum kedaluwarsa.");
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(Request $request, string $topupCode): View
    {
        $topup = WalletTopUp::where('topup_code', $topupCode)->firstOrFail();
        $user = $request->user();

        if ((int) $topup->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Anda tidak memiliki akses ke tagihan Top-Up ini.');
        }

        return view('buyer.wallet-topup', compact('topup'));
    }

    public function simulatePay(Request $request, string $topupCode): RedirectResponse
    {
        $topup = WalletTopUp::where('topup_code', $topupCode)->firstOrFail();
        $user = $request->user();

        if ((int) $topup->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Anda tidak memiliki akses ke tagihan Top-Up ini.');
        }

        try {
            $gatewayRef = 'SIM-TOPUP-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6));
            $settled = $this->topUpService->settleTopUp($topup->topup_code, $gatewayRef);

            return redirect()
                ->route('buyer.wallet')
                ->with('success', 'Top-Up Rp '.number_format((float) $settled->amount, 0, ',', '.')." lunas ({$settled->topup_code})! Saldo siap dipakai belanja (dilindungi Anti-Gestun).");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
