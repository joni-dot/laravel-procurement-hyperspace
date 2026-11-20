<?php

namespace App\Actions\Companies;

use App\Models\Membership;
use LogicException;

/**
 * Removes an invitation the person doesn't want. It never became a membership, so nothing in the
 * company refers to it and there is no history to keep.
 */
class DeclineInvitation
{
    public function handle(Membership $membership): void
    {
        if ($membership->joined_at !== null) {
            throw new LogicException('A membership the person has joined is deactivated, never deleted.');
        }

        $membership->delete();
    }
}
