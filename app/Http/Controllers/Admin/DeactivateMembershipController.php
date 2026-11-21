<?php

namespace App\Http\Controllers\Admin;

use App\Events\MembershipDeactivated;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Takes a person out of this company, or withdraws their invitation. Their account, their other
 * companies and everything they did here stay.
 */
class DeactivateMembershipController extends Controller
{
    public function __invoke(Request $request, Membership $membership): RedirectResponse
    {
        $membership->rememberWhatTheCompanyKnew();
        $membership->forceFill([
            'deactivated_at' => now(),
            'invitation_token' => null,
        ])->save();

        MembershipDeactivated::dispatch($membership, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was deactivated.', ['name' => $membership->nameForCompany()])]);

        return back();
    }
}
