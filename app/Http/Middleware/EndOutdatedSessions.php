<?php

namespace App\Http\Middleware;

use App\Auth\SignInVersion;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a session that signed in before the account's ways to sign in changed (SignInVersion).
 */
class EndOutdatedSessions
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || SignInVersion::isCurrent($user)) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(401);
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => __('You were signed out because the sign-in settings of your account changed. Sign in again.')]);

        return to_route('login');
    }
}
