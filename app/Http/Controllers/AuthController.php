<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletPinService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(
        private readonly WalletPinService $walletPinService,
    ) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('marketplace.index');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower(trim($credentials['email'])))->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            return back()
                ->withInput($request->only('email', 'remember'))
                ->withErrors(['email' => 'Email atau kata sandi tidak sesuai, atau akun sedang nonaktif.']);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->forget('nexatrade_logged_out');

        $targetRoute = match ($user->role) {
            'admin' => route('admin.finance.index'),
            'seller' => route('seller.dashboard'),
            default => route('marketplace.index'),
        };

        return redirect()->intended($targetRoute)
            ->with('success', "Selamat datang kembali, {$user->name}!");
    }

    public function showRegister(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('marketplace.index');
        }

        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $newUser = User::create([
                'name' => trim($validated['name']),
                'email' => strtolower(trim($validated['email'])),
                'password' => Hash::make($validated['password']),
                'role' => 'buyer',
                'is_active' => true,
            ]);

            Wallet::create([
                'user_id' => $newUser->id,
                'pending_balance' => '0.00',
                'available_balance' => '0.00',
                'security_deposit_balance' => '0.00',
                'clawback_debt_balance' => '0.00',
                'updated_at' => now(),
            ]);

            return $newUser;
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('nexatrade_logged_out');

        return redirect()->route('marketplace.index')
            ->with('success', "Akun {$user->name} berhasil didaftarkan! Dompet Escrow Rp 0 Anda telah aktif.");
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('nexatrade_logged_out', true);

        return redirect()->route('login')
            ->with('success', 'Anda telah keluar dari sesi NexaTrade dengan aman.');
    }

    public function security(Request $request): View
    {
        $user = $request->user()->fresh(['wallet', 'sellerProfile']);

        return view('auth.security', compact('user'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        if (! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Kata sandi saat ini tidak sesuai.']);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('success', 'Kata sandi akun berhasil diperbarui.');
    }

    public function updateWalletPin(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'pin' => ['required', 'digits:6', 'confirmed'],
        ]);

        try {
            $this->walletPinService->setPin(
                userId: $request->user()->id,
                pin: $validated['pin'],
                currentPassword: $validated['current_password'],
            );

            return back()->with('success', 'PIN Dompet 6-digit berhasil diaktifkan! Seluruh penarikan saldo, checkout dompet, dan pelunasan kini terlindungi PIN.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
