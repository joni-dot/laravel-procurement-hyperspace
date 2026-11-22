<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Limits health checks per IP address: plenty for any uptime monitor, too few to turn the checks
 * into load. Unlike Laravel's throttle middleware it lets the check through when the rate
 * limiter's store (Redis) is down, so the check can still answer that Redis is failing.
 */
class LimitHealthChecks
{
    public const int CHECKS_PER_MINUTE = 60;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'health|'.$request->ip();

        try {
            $allowed = RateLimiter::attempt($key, self::CHECKS_PER_MINUTE, fn (): bool => true);
        } catch (Throwable) {
            $allowed = true;
        }

        if ($allowed === false) {
            throw new ThrottleRequestsException(headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        return $next($request);
    }
}
