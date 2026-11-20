<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Companies\SendInvitation;
use App\Companies\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ResendInvitationController extends Controller
{
    public function __invoke(User $user, SendInvitation $sendInvitation, CurrentCompany $currentCompany): RedirectResponse
    {
        $sendInvitation->handle($user->memberships()->where('company_id', $currentCompany->id())->firstOrFail());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $user->email])]);

        return back();
    }
}
