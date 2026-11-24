<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in, reset and password fields are always text. Fortify and Laravel's password checks expect
 * text and fail with a server error on a list or a number, so anything else is replaced with an empty
 * value here, which the forms then refuse with their usual message.
 */
class KeepCredentialsText
{
    private const array FIELDS = ['email', 'password', 'password_confirmation', 'current_password', 'code', 'recovery_code', 'token'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::FIELDS as $field) {
            if ($request->exists($field) && ! is_string($request->input($field)) && $request->input($field) !== null) {
                $request->merge([$field => '']);
            }
        }

        return $next($request);
    }
}
