<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where people land after signing in: the company they used last, or the first they can open.
 * Someone in no company yet lands on their invitations, which also explains how joining works.
 */
class OpenCompanyController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $user->homeCompany();

        if ($company === null) {
            // People who run the service usually belong to no company; their work is on the platform pages.
            return $user->is_platform_admin
                ? to_route('platform.companies.index')
                : to_route('invitations.index');
        }

        return to_route('company.home', ['company' => $company->slug]);
    }
}
