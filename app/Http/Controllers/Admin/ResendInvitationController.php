<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Companies\SendInvitation;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ResendInvitationController extends Controller
{
    public function __invoke(Membership $membership, SendInvitation $sendInvitation): RedirectResponse
    {
        $sendInvitation->handle($membership);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $membership->invited_email])]);

        return back();
    }
}
