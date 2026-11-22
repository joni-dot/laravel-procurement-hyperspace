<?php

namespace App\Http\Controllers;

use App\Monitoring\HealthChecks;
use Illuminate\Http\JsonResponse;

/**
 * The health check for uptime monitors (docs/monitoring.md): 200 when every check passes, 503 when
 * any fails. Anyone can call it, so the answer names only the checks and whether each passes: no
 * error messages, versions, host names or timings. Laravel's /up stays the plain "the app answers"
 * check for the container and the load balancer.
 */
class ShowHealthController extends Controller
{
    public function __invoke(HealthChecks $checks): JsonResponse
    {
        $results = $checks->run();
        $healthy = ! in_array(false, $results, true);

        return response()
            ->json([
                'status' => $healthy ? 'ok' : 'failing',
                'checks' => array_map(fn (bool $passes): string => $passes ? 'ok' : 'failing', $results),
            ], $healthy ? 200 : 503)
            // A cached answer would hide an outage from the monitor.
            ->header('Cache-Control', 'no-store');
    }
}
