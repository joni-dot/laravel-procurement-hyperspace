<?php

namespace App\Policies;

use App\Companies\CurrentCompany;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Only admins manage users. Admins can never change or deactivate their own
 * account, which guarantees the system always keeps at least one active admin.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->isAdmin() && $actor->isNot($user) && $this->isInCurrentCompany($user);
    }

    /**
     * Admins manage only their own company's people; someone from another company is out of reach
     * even when their id is typed into the address.
     */
    private function isInCurrentCompany(User $user): bool
    {
        $companyId = app(CurrentCompany::class)->id();

        return $companyId !== null && $user->memberships()->where('company_id', $companyId)->exists();
    }

    public function deactivate(User $actor, User $user): bool
    {
        return $this->update($actor, $user) && ! $user->isDeactivated();
    }

    public function reactivate(User $actor, User $user): bool
    {
        return $this->update($actor, $user) && $user->isDeactivated();
    }

    public function resendInvitation(User $actor, User $user): bool
    {
        return $this->update($actor, $user) && $user->status() === UserStatus::Invited;
    }
}
