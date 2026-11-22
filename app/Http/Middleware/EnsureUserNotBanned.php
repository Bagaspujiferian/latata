<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (bool) $user->is_banned) {
            $reason = trim((string) ($user->ban_reason ?? ''));
            if ($reason === '') {
                $reason = 'Akun Anda ditangguhkan oleh Tim Keamanan NexaTrade.';
            }

            Auth::logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $request->session()->put('nexatrade_logged_out', true);
            }

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'ok' => false,
                    'message' => "Akses ditolak: {$reason}",
                ], 403);
            }

            return redirect()
                ->route('login')
                ->with('error', "Akun Anda dibekukan: {$reason}");
        }

        return $next($request);
    }
}
