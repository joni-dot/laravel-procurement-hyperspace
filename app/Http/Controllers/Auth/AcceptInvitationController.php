<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Companies\AcceptInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link in an invitation email. A new account sets its password here and joins; an existing
 * account signs in first and then accepts or declines (the buttons use the in-app invitation list's
 * routes). Unknown, used and expired links all get the same answer.
 */
class AcceptInvitationController extends Controller
{
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $membership = $this->findInvitation($token);

        if ($membership === null) {
            return Inertia::render('auth/InvitationUnavailable', ['reason' => 'invalid']);
        }

        $invitedUser = $membership->user;
        $signedInUser = $request->user();

        if ($signedInUser !== null && $signedInUser->isNot($invitedUser)) {
            return Inertia::render('auth/InvitationUnavailable', ['reason' => 'another-account']);
        }

        if ($invitedUser->password === null) {
            return Inertia::render('auth/AcceptInvitation', [
                'token' => $token,
                'email' => $invitedUser->email,
                // What this invitation's inviter typed: the account may carry another company's text.
                'name' => $membership->known_name ?? $invitedUser->name,
                'passwordRules' => PasswordRule::defaults()->toPasswordRulesString(),
            ]);
        }

        if ($signedInUser === null) {
            // Signing in brings the person back to this link.
            return redirect()->guest(route('login'))->with('status', __('Sign in to accept the invitation.'));
        }

        return Inertia::render('auth/JoinCompany', [
            'invitation' => [
                'id' => $membership->id,
                'companyName' => $membership->company->name,
                'role' => $membership->role->label(),
            ],
        ]);
    }

    /**
     * A new account sets its password, joins the company and is signed in.
     */
    public function store(AcceptInvitationRequest $request, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $membership = DB::transaction(function () use ($request, $acceptInvitation): Membership {
            $membership = $this->findInvitation($request->validated('token'), lockForUpdate: true);
            $user = $membership?->user;

            // An account that has a password is never given a new one through an invitation link.
            if ($membership === null || $user === null || $user->password !== null) {
                throw ValidationException::withMessages(['token' => $this->invalidLinkMessage()]);
            }

            if ($request->user() !== null && $request->user()->isNot($user)) {
                throw ValidationException::withMessages(['token' => __('This invitation is for another account. Sign out and open the link again.')]);
            }

            $user->forceFill([
                'name' => $request->validated('name'),
                'password' => $request->validated('password'),
                // Opening the emailed link proves the address works.
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            $acceptInvitation->handle($membership);

            return $membership;
        });

        Auth::login($membership->user);
        $request->session()->regenerate();

        return to_route('dashboard', ['company' => $membership->company->slug]);
    }

    /**
     * Accounts blocked as a whole can't use invitations; they couldn't sign in afterwards anyway.
     */
    private function findInvitation(string $token, bool $lockForUpdate = false): ?Membership
    {
        $membership = Membership::query()
            ->openInvitation()
            ->where('invitation_token', hash('sha256', $token))
            ->with(['user', 'company'])
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first();

        return $membership !== null && ! $membership->user->isDeactivated() ? $membership : null;
    }

    private function invalidLinkMessage(): string
    {
        return __('This invitation link is invalid or has expired. Ask an admin to send a new one.');
    }
}
