<?php

namespace App\Actions\Companies;

use App\Models\Membership;
use LogicException;

/**
 * Removes an invitation the person doesn't want. It never became a membership, so nothing in the
 * company refers to it and there is no history to keep.
 *
 * Demo companies are the exception: their made-up data names the first admin (the project manager, package
 * owner), and the database keeps every named person a member. A declined invitation there is withdrawn
 * instead, the same way the platform withdraws a first admin's mistyped invitation. That covers every
 * invitation of a demo company, which does no harm: inviting the person again brings the same row back.
 */
class DeclineInvitation
{
    public function handle(Membership $membership): void
    {
        if ($membership->joined_at !== null) {
            throw new LogicException('A membership the person has joined is deactivated, never deleted.');
        }

        if ($membership->company->is_demo) {
            $membership->forceFill(['deactivated_at' => now(), 'invitation_token' => null])->save();

            return;
        }

        $membership->delete();
    }
}
