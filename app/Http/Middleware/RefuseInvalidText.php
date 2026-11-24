<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Text that isn't valid UTF-8 can't be stored or searched (PostgreSQL refuses it), so a request
 * carrying any is answered "bad request" before it reaches a page, instead of failing deep inside
 * with a server error and a long error log entry.
 */
class RefuseInvalidText
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->isValid($request->query->all()) && $this->isValid($request->input()), 400);

        return $next($request);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function isValid(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                return false;
            }

            if ((is_string($value) && ! mb_check_encoding($value, 'UTF-8')) || (is_array($value) && ! $this->isValid($value))) {
                return false;
            }
        }

        return true;
    }
}
