<?php

namespace App\Http\Controllers;

use App\Companies\CompanyHomePages;
use App\Companies\CurrentCompany;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A company's own address, /{company}: opens the overview that suits the person there, chosen by
 * the modules (CompanyHomePages).
 */
class OpenCompanyHomeController extends Controller
{
    public function __invoke(Request $request, CurrentCompany $currentCompany, CompanyHomePages $homePages): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return to_route($homePages->routeFor($user), ['company' => $currentCompany->get()?->slug]);
    }
}
