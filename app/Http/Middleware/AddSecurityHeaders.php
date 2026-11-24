<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    private const int ONE_YEAR_IN_SECONDS = 31_536_000;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ]);

        // Signed-in pages hold company data: keep them out of the browser's disk cache, where the next
        // person on a shared computer could find them. hasUser() only asks whether this request signed
        // someone in; it never starts a sign-in check of its own (no database query on /up or /health).
        if (Auth::hasUser() && ! $response->headers->hasCacheControlDirective('public')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.self::ONE_YEAR_IN_SECONDS.'; includeSubDomains');
        }

        return $response;
    }
}
