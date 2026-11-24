<?php

namespace App\Companies;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The memberships of people who can work in their company now: an active membership and an active
 * account. Used where the platform looks after a company without an admin, so that someone whose
 * account is deactivated never counts as its admin or its member, and is never made its admin.
 */
final class ActiveMembers
{
    /**
     * @param  Builder<Membership>  $memberships
     * @param  User|null  $apartFrom  left out, such as the platform admin choosing a company's new admin
     * @return Builder<Membership>
     */
    public static function only(Builder $memberships, ?User $apartFrom = null): Builder
    {
        return $memberships
            ->active()
            ->whereHas('user', fn (Builder $users) => $users->whereNull('users.deactivated_at'))
            ->when($apartFrom, fn (Builder $query, User $user) => $query->where('memberships.user_id', '!=', $user->id));
    }
}
