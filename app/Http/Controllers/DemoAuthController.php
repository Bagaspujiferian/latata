<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EscrowLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class DemoAuthController extends Controller
{
    public function __construct(
        private readonly EscrowLedgerService $ledgerService,
    ) {}

    public function switchUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'redirect_to' => ['nullable', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->where('is_active', true)->firstOrFail();
        Auth::login($user);
        $request->session()->regenerate();

        if (! empty($validated['redirect_to'])) {
            return redirect($validated['redirect_to'])
                ->with('success', "Berhasil beralih ke akun {$user->name} (".strtoupper($user->role).').');
        }

        $targetRoute = match ($user->role) {
            'admin' => route('admin.chaos-lab'),
            'seller' => route('seller.dashboard'),
            default => route('marketplace.index'),
        };

        return redirect($targetRoute)
            ->with('success', "Sesi aktif dialihkan ke {$user->name} (".strtoupper($user->role).').');
    }

    public function demoTopup(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return back()->with('error', 'Pilih akun terlebih dahulu.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:10000', 'max:5000000'],
        ]);

        $amount = (float) $validated['amount'];
        $ref = 'DEMO-TOPUP-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));

        $this->ledgerService->adminDemoTopup(
            userId: $user->id,
            amount: $amount,
            referenceCode: $ref,
            description: "Demo Top-Up Saldo Instan ({$user->name})",
        );

        return back()->with('success', 'Top-Up Demo Rp '.number_format($amount, 0, ',', '.')." berhasil masuk ke Saldo Aktif (Ref: {$ref}).");
    }
}
