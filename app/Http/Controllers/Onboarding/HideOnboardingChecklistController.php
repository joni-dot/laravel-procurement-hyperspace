<?php

namespace App\Http\Controllers\Onboarding;

use App\Companies\CurrentCompany;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Hides the getting-started checklist from Home for every admin of the company. Admins can show it again
 * from the bottom of Home.
 */
class HideOnboardingChecklistController extends Controller
{
    public function __invoke(CurrentCompany $currentCompany): RedirectResponse
    {
        $currentCompany->get()?->forceFill(['onboarding_hidden_at' => now()])->save();

        return to_route('company.home');
    }
}
