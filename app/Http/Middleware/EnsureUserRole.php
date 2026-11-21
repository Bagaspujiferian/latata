<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('marketplace.index')
                ->with('error', 'Silakan pilih akun melalui Demo Account Switcher di bilah atas.');
        }

        if (! empty($roles) && ! in_array($user->role, $roles, true)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Akses ditolak: peran akun tidak sesuai untuk halaman ini.',
                ], 403);
            }

            return redirect()->route('marketplace.index')
                ->with('error', "Akses dibatasi untuk peran [".implode(', ', $roles)."]. Gunakan Switcher di bilah atas untuk berganti peran.");
        }

        return $next($request);
    }
}
