<?php

namespace App\Http\Controllers;

use App\Actions\Companies\AcceptInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Accepts one of the signed-in person's own open invitations and opens the company. Any other id,
 * another person's invitation included, is "not found".
 */
class JoinCompanyController extends Controller
{
    public function __invoke(Request $request, int $invitation, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $user->memberships()->openInvitation()->with('company')->findOrFail($invitation);

        $acceptInvitation->handle($membership);

        return to_route('company.home', ['company' => $membership->company->slug]);
    }
}
