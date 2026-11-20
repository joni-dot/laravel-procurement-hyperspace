<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Takes a person out of this company, or withdraws their invitation. Their account, their other
 * companies and everything they did here stay.
 */
class DeactivateMembershipController extends Controller
{
    public function __invoke(Membership $membership): RedirectResponse
    {
        $membership->forceFill([
            'deactivated_at' => now(),
            'invitation_token' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was deactivated.', ['name' => $membership->nameForCompany()])]);

        return back();
    }
}
