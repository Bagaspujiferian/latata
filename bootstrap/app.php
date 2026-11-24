<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsureSimulationModeEnabled;
use App\Http\Middleware\EnsureUserNotBanned;
use App\Http\Middleware\EnsureUserRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->appendToGroup('web', EnsureUserNotBanned::class);
        $middleware->appendToGroup('web', AddSecurityHeaders::class);

        $middleware->alias([
            'role' => EnsureUserRole::class,
            'admin.perm' => EnsureAdminPermission::class,
            'idempotent' => EnsureIdempotentRequest::class,
            'simulation.only' => EnsureSimulationModeEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
