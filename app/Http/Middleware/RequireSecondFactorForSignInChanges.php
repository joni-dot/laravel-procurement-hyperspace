<?php

namespace App\Http\Middleware;

use App\Auth\StrongSignIn;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Once an account has two-factor sign-in, its ways to sign in (two-factor settings, recovery codes,
 * passkeys) can only be changed from a session that proved the second factor (StrongSignIn). The
 * password alone, which someone else may have learned, must not be enough to add a sign-in method of
 * their own or to see the recovery codes.
 */
class RequireSecondFactorForSignInChanges
{
    private const array ROUTES = [
        'two-factor.enable',
        'two-factor.disable',
        'two-factor.confirm',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $request->routeIs(...self::ROUTES) || ! $user instanceof User || ! self::isNeededFor($user)) {
            return $next($request);
        }

        // Only looking at the codes changes nothing, and the security page asks for them by itself:
        // that is refused without signing the person out.
        if ($request->routeIs('two-factor.recovery-codes')) {
            abort(403, __('Sign in again with your two-factor code to see your recovery codes.'));
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            abort(401);
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => __('Sign in again with your two-factor code to change how you sign in.')]);

        return to_route('login');
    }

    /**
     * Whether this session must sign in again with the second factor before changing how the person
     * signs in. A "remember me" sign-in never proved it in this session.
     */
    public static function isNeededFor(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && ! StrongSignIn::passedBy($user);
    }
}
