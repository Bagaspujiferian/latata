<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /** @var list<string> */
    private const SENSITIVE_PATH_PREFIXES = [
        'buyer/orders',
        'buyer/wallet',
        'buyer/open-store',
        'seller/wallet',
        'seller/kyc',
        'account/security',
        'admin',
        'checkout',
        'invoices',
        'wallet/bank-accounts',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $path = ltrim($request->path(), '/');
        foreach (self::SENSITIVE_PATH_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
                $response->headers->set('Pragma', 'no-cache');
                break;
            }
        }

        return $response;
    }
}
