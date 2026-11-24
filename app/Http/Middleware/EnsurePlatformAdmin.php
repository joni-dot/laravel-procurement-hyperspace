<?php

namespace App\Http\Middleware;

use App\Auth\StrongSignIn;
use App\Companies\CurrentCompany;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only platform admins, the people who run the service, into /platform/...
 *
 * Everyone else gets "not found", so the area doesn't show that it exists. A platform admin can
 * create and suspend any company, so their account must be protected by two-factor sign-in, and
 * this session must have passed it (StrongSignIn).
 */
class EnsurePlatformAdmin
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_platform_admin, 404);

        if ($user->two_factor_confirmed_at === null) {
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => __('Turn on two-factor authentication before you open the platform pages.'),
            ]);

            return to_route('security.edit');
        }

        if (! StrongSignIn::passedBy($user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => __('Sign in again with your two-factor code to open the platform pages.'),
            ]);

            return to_route('login');
        }

        // Platform pages work on companies, never inside one. Without a company the database shows
        // no company data, so a mistake on these pages can't reveal any.
        if ($this->currentCompany->get() !== null) {
            $this->currentCompany->clear();
        }

        return $next($request);
    }
}
