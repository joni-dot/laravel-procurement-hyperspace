<?php

namespace App\Http\Middleware;

use App\Companies\CurrentCompany;
use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the company named in the address (/{company}/...) for the rest of the request.
 *
 * Runs before route model binding, so suppliers and other records are looked up inside the
 * company. Someone who isn't an active member gets "not found", the same as for an address no
 * company has, so the answer doesn't tell outsiders which companies exist.
 */
class EnterCompany
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route?->parameter('company');
        $user = $request->user();

        abort_unless($route !== null && is_string($slug) && $user instanceof User, 404);

        $company = Company::findBySlug($slug);
        abort_unless($company !== null && $user->activeMembershipIn($company->id) !== null, 404);

        if ($slug !== $company->slug) {
            return $this->forwardToCurrentAddress($request, $slug, $company);
        }

        if ($company->isSuspended()) {
            return Inertia::render('companies/Suspended', ['companyName' => $company->name])
                ->toResponse($request)
                ->setStatusCode(403);
        }

        $this->currentCompany->set($company);
        // CurrentCompany already did this. Wayfinder reads this method's code to learn that routes
        // behind it get the company filled in, so the TypeScript route helpers don't require it.
        URL::defaults(['company' => $company->slug]);
        $this->rememberAsLastCompany($user, $company);

        // Controllers don't take the company as an argument; they read CurrentCompany.
        $route->forgetParameter('company');

        return $next($request);
    }

    /**
     * An old address of a company forwards its members to the current one. Only reading requests
     * are forwarded; a form sent to an old address would lose its data in a redirect.
     */
    private function forwardToCurrentAddress(Request $request, string $oldSlug, Company $company): Response
    {
        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 404);

        $path = $company->slug.substr($request->path(), strlen($oldSlug));
        $query = $request->getQueryString();

        return redirect()->to('/'.$path.($query ? '?'.$query : ''), 301);
    }

    private function rememberAsLastCompany(User $user, Company $company): void
    {
        if ($user->last_company_id !== $company->id) {
            $user->forceFill(['last_company_id' => $company->id])->saveQuietly();
        }
    }
}
