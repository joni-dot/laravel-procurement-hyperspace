<?php

namespace App\Http\Controllers\Admin;

use App\Events\MembershipReactivated;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Lets a deactivated member into the company again. A withdrawn invitation comes back as expired,
 * because its link was cancelled; resending it emails a new one.
 */
class ReactivateMembershipController extends Controller
{
    public function __invoke(Request $request, Membership $membership): RedirectResponse
    {
        $membership->forceFill(['deactivated_at' => null])->save();

        MembershipReactivated::dispatch($membership, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was reactivated.', ['name' => $membership->nameForCompany()])]);

        return back();
    }
}
