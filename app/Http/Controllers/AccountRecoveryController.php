<?php

namespace App\Http\Controllers;

use App\Services\AccountRecoveryService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountRecoveryController extends Controller
{
    public function __construct(
        private readonly AccountRecoveryService $recoveryService,
    ) {}

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    public function sendPasswordReset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:120'],
        ]);

        $result = $this->recoveryService->requestPasswordReset($validated['email']);

        $redirect = back()->with(
            'success',
            'Jika alamat email terdaftar di NexaTrade, tautan pemulihan kata sandi (berlaku 30 menit) telah dikirimkan.'
        );

        if (! empty($result['preview_url'])) {
            $redirect->with('recovery_preview_url', $result['preview_url'])
                ->with('recovery_preview_token', $result['preview_token']);
        }

        return $redirect;
    }

    public function showResetPassword(Request $request, string $token): View
    {
        $email = (string) $request->query('email', '');

        return view('auth.reset-password', compact('token', 'email'));
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);

        try {
            $this->recoveryService->resetPasswordWithToken(
                email: $validated['email'],
                plainToken: $validated['token'],
                newPassword: $validated['password'],
            );

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            return redirect()
                ->route('login')
                ->with('success', 'Kata sandi akun berhasil direset dan sesi lama telah dihentikan. Silakan masuk dengan kata sandi baru.');
        } catch (DomainException $e) {
            return back()->withInput($request->only('email'))->with('error', $e->getMessage());
        }
    }

    public function requestPinReset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['nullable', 'string', 'max:72'],
        ]);

        try {
            $result = $this->recoveryService->requestWalletPinReset(
                userId: $request->user()->id,
                currentPassword: $request->filled('current_password') ? (string) $validated['current_password'] : null,
            );

            $redirect = back()->with(
                'success',
                'Token reset PIN Dompet 6-Digit telah diterbitkan (berlaku 15 menit, sekali pakai).'
            );

            if (! empty($result['preview_token'])) {
                $redirect->with('pin_reset_preview_token', $result['preview_token']);
            }

            return $redirect;
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function confirmPinReset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:120'],
            'new_pin' => ['required', 'string', 'size:6'],
            'new_pin_confirmation' => ['required', 'same:new_pin'],
        ]);

        try {
            $this->recoveryService->resetWalletPinWithToken(
                userId: $request->user()->id,
                plainToken: $validated['token'],
                newPin: $validated['new_pin'],
            );

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }

            return redirect()
                ->route('account.security')
                ->with('success', 'PIN Dompet 6-Digit berhasil direset! Status penguncian (lockout) telah dibuka kembali.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
