<?php

namespace App\Http\Controllers;

use App\Services\SellerDebtRecoveryService;
use App\Services\WalletPinService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SellerDebtController extends Controller
{
    public function __construct(
        private readonly SellerDebtRecoveryService $debtRecoveryService,
        private readonly WalletPinService $walletPinService,
    ) {}

    public function repayFromBalance(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000'],
            'wallet_pin' => ['nullable', 'string', 'max:6'],
        ]);

        try {
            $this->walletPinService->verifyPinOrFail($request->user()->id, $request->input('wallet_pin'), true);

            $repayment = $this->debtRecoveryService->repayDebtFromAvailableBalance(
                sellerId: $request->user()->id,
                amount: (float) $validated['amount'],
            );

            $msg = $repayment->auto_unsuspended
                ? "Pelunasan {$repayment->repayment_code} LUNAS! Status suspend toko dicabut otomatis dan toko kembali dibuka."
                : "Pembayaran tunggakan {$repayment->repayment_code} berhasil dicatat. Sisa tunggakan: Rp ".number_format((float) $repayment->debt_after, 0, ',', '.').'.';

            return back()->with('success', $msg);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function repayViaGateway(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000'],
        ]);

        try {
            $gatewayRef = 'GW-DEBT-'.now()->format('YmdHis').'-'.strtoupper(Str::random(5));

            $repayment = $this->debtRecoveryService->repayDebtViaGatewaySettlement(
                sellerId: $request->user()->id,
                amount: (float) $validated['amount'],
                gatewayRef: $gatewayRef,
            );

            $msg = $repayment->auto_unsuspended
                ? "Pelunasan Gateway ({$gatewayRef}) LUNAS! Status suspend toko dicabut otomatis dan toko kembali aktif."
                : "Pelunasan Gateway ({$gatewayRef}) diterima. Sisa tunggakan: Rp ".number_format((float) $repayment->debt_after, 0, ',', '.').'.';

            return back()->with('success', $msg);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
