<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Pages\PageSections;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A company's own address, /{company}: the Home page, where people land. Its sections come from the
 * modules (PageSections), each shown only to the people it is useful for; Core knows none of them.
 */
class OpenCompanyHomeController extends Controller
{
    public function __invoke(Request $request, PageSections $sections): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Home', $sections->props('home', $user));
    }
}
