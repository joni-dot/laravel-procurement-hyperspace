<?php

namespace App\Http\Middleware;

use App\Companies\CurrentCompany;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only platform admins, the people who run the service, into /platform/...
 *
 * Everyone else gets "not found", so the area doesn't show that it exists. A platform admin can
 * create and suspend any company, so their account must be protected by two-factor sign-in.
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

        // Platform pages work on companies, never inside one. Without a company the database shows
        // no company data, so a mistake on these pages can't reveal any.
        if ($this->currentCompany->get() !== null) {
            $this->currentCompany->clear();
        }

        return $next($request);
    }
}
