<?php

namespace App\Actions\Companies;

use App\Models\Membership;

/**
 * Makes an invitation a membership the person can work in. The link stops working at once.
 */
class AcceptInvitation
{
    public function handle(Membership $membership): void
    {
        $membership->forceFill([
            'joined_at' => now(),
            'invitation_token' => null,
        ])->save();

        $membership->user->forgetMemberships();
    }
}
