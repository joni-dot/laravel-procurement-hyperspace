<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where people land after signing in: the company they used last, or the first they can open.
 */
class OpenCompanyController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        /** @var User $user */
        $user = $request->user();
        $company = $user->homeCompany();

        if ($company === null) {
            return Inertia::render('companies/None');
        }

        return to_route('dashboard', ['company' => $company->slug]);
    }
}
