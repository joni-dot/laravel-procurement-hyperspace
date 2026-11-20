<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Lets a deactivated member into the company again. A withdrawn invitation comes back as expired,
 * because its link was cancelled; resending it emails a new one.
 */
class ReactivateMembershipController extends Controller
{
    public function __invoke(Membership $membership): RedirectResponse
    {
        $membership->forceFill(['deactivated_at' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was reactivated.', ['name' => $membership->nameForCompany()])]);

        return back();
    }
}
