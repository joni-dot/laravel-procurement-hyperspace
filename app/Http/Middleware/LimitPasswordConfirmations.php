<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify registers "confirm your password" without a rate limit. Whoever holds a signed-in session
 * (a few minutes at an unlocked computer) could otherwise guess the password there, and with it open
 * the security settings. Every form that checks the current password shares these counters, so none
 * of them can be used to guess it instead. Limited per account, whichever session or address the
 * guesses come from.
 */
class LimitPasswordConfirmations
{
    public const int ATTEMPTS_PER_MINUTE = 5;

    public const int ATTEMPTS_PER_HOUR = 20;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $field = $this->checkedField($request);

        if ($field === null || $request->user() === null) {
            return $next($request);
        }

        $account = 'password-confirm|'.$request->user()->getAuthIdentifier();

        foreach ([[$account.'|minute', self::ATTEMPTS_PER_MINUTE, 60], [$account.'|hour', self::ATTEMPTS_PER_HOUR, 3600]] as [$key, $attempts, $decay]) {
            if (RateLimiter::tooManyAttempts($key, $attempts)) {
                throw ValidationException::withMessages([
                    $field => __('Too many attempts. Please try again in :seconds seconds.', [
                        'seconds' => RateLimiter::availableIn($key),
                    ]),
                ]);
            }
        }

        RateLimiter::hit($account.'|minute', 60);
        RateLimiter::hit($account.'|hour', 3600);

        return $next($request);
    }

    /**
     * The form field holding the password this request checks, or null when it checks none.
     */
    private function checkedField(Request $request): ?string
    {
        if ($request->isMethod('POST') && $request->routeIs('password.confirm.store', 'passkey.confirm')) {
            return 'password';
        }

        if ($request->routeIs('user-password.update', 'profile.update') && $request->filled('current_password')) {
            return 'current_password';
        }

        return null;
    }
}
