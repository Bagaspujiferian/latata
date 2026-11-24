<?php

namespace App\Http\Controllers;

use App\Models\WalletTopUp;
use App\Services\MidtransSnapGatewayService;
use App\Services\WalletTopUpService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WalletTopUpController extends Controller
{
    public function __construct(
        private readonly WalletTopUpService $topUpService,
        private readonly MidtransSnapGatewayService $snapGatewayService,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:10000', 'max:10000000'],
            'payment_channel' => ['nullable', 'string', 'in:midtrans_snap,qris,bca_va,bri_va,gopay'],
        ]);

        try {
            $topup = $this->topUpService->createTopUp(
                userId: $this->requireUser($request)->id,
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
        $user = $this->requireUser($request);

        if ((int) $topup->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Anda tidak memiliki akses ke tagihan Top-Up ini.');
        }

        return view('buyer.wallet-topup', compact('topup'));
    }

    public function simulatePay(Request $request, string $topupCode): RedirectResponse
    {
        $topup = WalletTopUp::where('topup_code', $topupCode)->firstOrFail();
        $user = $this->requireUser($request);

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

    public function issueSnapToken(Request $request, string $topupCode): JsonResponse
    {
        $topup = WalletTopUp::where('topup_code', $topupCode)->firstOrFail();
        $user = $this->requireUser($request);

        if ((int) $topup->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Anda tidak memiliki akses ke tagihan Top-Up ini.');
        }

        try {
            $tokenData = $this->snapGatewayService->createOrReuseTopUpSnapToken($topup);

            return response()->json([
                'ok' => true,
                'topup_code' => $topup->topup_code,
                'snap_token' => $tokenData['snap_token'],
                'redirect_url' => $tokenData['redirect_url'],
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function syncPaymentStatus(Request $request, string $topupCode): RedirectResponse|JsonResponse
    {
        $topup = WalletTopUp::where('topup_code', $topupCode)->firstOrFail();
        $user = $this->requireUser($request);

        if ((int) $topup->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Anda tidak memiliki akses ke tagihan Top-Up ini.');
        }

        try {
            $result = $this->snapGatewayService->syncTransactionStatus(
                $topup->topup_code,
                (float) $topup->amount,
            );
            $topup->refresh();

            $httpOk = ($result['http_status'] ?? 500) === 200;

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => $httpOk,
                    'topup_code' => $topup->topup_code,
                    'topup_status' => $topup->status,
                    'result' => $result,
                ], $httpOk ? 200 : 422);
            }

            if (! $httpOk) {
                return back()->with('error', (string) ($result['message'] ?? 'Sinkronisasi status Midtrans gagal.'));
            }

            if ($topup->status === 'PAID') {
                return redirect()->route('buyer.wallet')
                    ->with('success', 'Top-Up Rp '.number_format((float) $topup->amount, 0, ',', '.')." lunas ({$topup->topup_code})! Saldo siap dipakai belanja (dilindungi Anti-Gestun).");
            }

            return redirect()->route('buyer.wallet.topup.show', $topup->topup_code)
                ->with('success', "Status Midtrans disinkronkan: Status Top-Up saat ini [{$topup->status}].");
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }
}
