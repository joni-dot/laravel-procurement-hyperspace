<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every log line of a request (errors included) the visitor's address, the method and a request
 * id, and sends the id back as X-Request-Id, so support can find the log lines of the request someone
 * reports. The route is added once it is known (MonitoringServiceProvider).
 *
 * The id is always made here: no proxy in front of the app sets one, and one sent by the visitor
 * could be anything, including another request's id. Runs after TrustProxies, so the address is the
 * visitor's and not the proxy's.
 */
class AddRequestContext
{
    public const string HEADER = 'X-Request-Id';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();

        Context::add([
            'request_id' => $requestId,
            'ip' => $request->ip(),
            'method' => $request->method(),
            // Until a route matches; a server reused for the next request must not keep the last one's.
            'route' => null,
        ]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
