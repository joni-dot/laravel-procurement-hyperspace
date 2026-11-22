<?php

namespace App\Http\Controllers\Onboarding;

use App\Companies\CurrentCompany;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Brings back the getting-started checklist an admin hid, for every admin of the company.
 */
class ShowOnboardingChecklistController extends Controller
{
    public function __invoke(CurrentCompany $currentCompany): RedirectResponse
    {
        $currentCompany->get()?->forceFill(['onboarding_hidden_at' => null])->save();

        return to_route('company.home');
    }
}
