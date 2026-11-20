<?php

namespace App\Http\Controllers;

use App\Actions\Companies\DeclineInvitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in person's invitations to join companies, outside any company.
 */
class InvitationController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $invitations = $user->memberships()
            ->pendingInvitation()
            ->with(['company:id,name', 'invitedBy:id,name'])
            ->latest('invitation_sent_at')
            ->get(['id', 'company_id', 'user_id', 'role', 'invited_by_user_id', 'invitation_token', 'invitation_sent_at', 'joined_at', 'deactivated_at'])
            ->map(fn (Membership $membership): array => [
                'id' => $membership->id,
                'companyName' => $membership->company->name,
                'role' => $membership->role->label(),
                'invitedBy' => $membership->invitedBy?->name,
                'sentOn' => $membership->invitation_sent_at?->toDateString(),
                'isExpired' => ! $membership->isOpenInvitation(),
            ]);

        return Inertia::render('invitations/Index', [
            'invitations' => $invitations,
        ]);
    }

    /**
     * Declines one of the person's own open invitations; any other id is "not found".
     */
    public function destroy(Request $request, int $invitation, DeclineInvitation $declineInvitation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $declineInvitation->handle($user->memberships()->openInvitation()->findOrFail($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('invitations.index');
    }
}
