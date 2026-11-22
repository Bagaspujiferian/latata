<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSimulationModeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('nexatrade.demo_switcher_enabled', false)) {
            abort(404);
        }

        return $next($request);
    }
}
