<?php

namespace App\Actions\Companies;

use App\Models\Membership;
use App\Notifications\UserInvitation;
use Illuminate\Support\Str;

/**
 * Emails a fresh invitation link. Only a hash of the token is stored, and a new link replaces the
 * old one, so only the latest email works.
 */
class SendInvitation
{
    public function handle(Membership $membership): void
    {
        $token = Str::random(64);

        $membership->forceFill([
            'invitation_token' => hash('sha256', $token),
            'invitation_sent_at' => now(),
        ])->save();

        $membership->user->notify(new UserInvitation(
            token: $token,
            companyName: $membership->company->name,
            hasAccount: $membership->user->password !== null,
            expiresInDays: intdiv((int) config('auth.passwords.invitations.expire'), 60 * 24),
        ));
    }
}
