<?php

namespace App\Http\Middleware;

use App\Security\AdminPermissionMatrix;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Hanya Admin NexaTrade yang memiliki izin akses.');
        }

        if (! AdminPermissionMatrix::allows($user, $permission)) {
            $subroleLabel = $user->admin_subrole?->label() ?? 'Admin';
            abort(403, "Akses Ditolak (RBAC): Peran [{$subroleLabel}] tidak memiliki izin [{$permission}].");
        }

        return $next($request);
    }
}
