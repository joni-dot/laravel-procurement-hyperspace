<?php

namespace App\Policies;

use App\Companies\CurrentCompany;
use App\Models\Membership;
use App\Models\User;

/**
 * Only a company's admins manage its members. Nobody changes their own role or deactivates
 * themselves, which also means a company always keeps at least one active admin.
 */
class MembershipPolicy
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, Membership $membership): bool
    {
        return $actor->isAdmin()
            && $membership->user_id !== $actor->id
            && $membership->company_id === $this->currentCompany->id();
    }

    public function deactivate(User $actor, Membership $membership): bool
    {
        return $this->update($actor, $membership) && $membership->deactivated_at === null;
    }

    public function reactivate(User $actor, Membership $membership): bool
    {
        return $this->update($actor, $membership) && $membership->deactivated_at !== null;
    }

    public function resendInvitation(User $actor, Membership $membership): bool
    {
        return $this->update($actor, $membership)
            && $membership->joined_at === null
            && $membership->deactivated_at === null;
    }
}
