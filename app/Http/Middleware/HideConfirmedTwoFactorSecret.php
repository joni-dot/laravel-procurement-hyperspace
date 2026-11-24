<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two-factor secret (as text or QR code) is only needed while setting up the authenticator app.
 * Once two-factor sign-in is confirmed, Fortify would still show it to anyone with a signed-in session
 * and the password, who could then copy the second factor for good. Setting it up again means turning
 * two-factor sign-in off and on, which creates a new secret.
 */
class HideConfirmedTwoFactorSecret
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->routeIs('two-factor.secret-key', 'two-factor.qr-code') && $user instanceof User && $user->two_factor_confirmed_at !== null) {
            abort(404);
        }

        return $next($request);
    }
}
