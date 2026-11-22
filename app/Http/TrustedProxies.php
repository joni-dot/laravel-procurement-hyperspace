<?php

namespace App\Http;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Behind a proxy or load balancer, every visitor would otherwise share the proxy's address (and so its
 * rate limits), and the app would think HTTPS requests were plain HTTP. Only the proxies listed in
 * TRUSTED_PROXIES are believed, because anyone else could send the same headers to fake an address.
 */
final class TrustedProxies
{
    /**
     * Called while the app boots: bootstrap/app.php runs before the configuration is loaded.
     */
    public static function applyConfiguration(): void
    {
        $setting = trim((string) config('app.trusted_proxies'));

        TrustProxies::at(match ($setting) {
            '' => [],
            '*' => '*',
            default => array_values(array_filter(array_map(trim(...), explode(',', $setting)))),
        });

        // Only the visitor's address, the host name and HTTPS. Laravel would also believe
        // X-Forwarded-Port and -Prefix, which a proxy may pass on from the visitor unchanged; they
        // would let a visitor change every address the app builds for that request.
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
    }
}
