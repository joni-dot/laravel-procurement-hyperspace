<?php

namespace App\Actions\Companies;

use App\Companies\ActiveMembers;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\MadeCompanyAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gets a company whose admins have all left going again, by making one of its own active members (an
 * active membership and an active account) the admin. The platform admin picks the member but never
 * joins the company, so its data stays out of the platform's reach; an invitation to a new address is
 * only for a company with nobody to pick (InviteFirstAdmin). Any admin invitation still waiting is
 * withdrawn, and the member is told by email.
 */
class PromoteMemberToAdmin
{
    public function handle(Company $company, int $membershipId, User $platformAdmin): Membership
    {
        $membership = DB::transaction(function () use ($company, $membershipId, $platformAdmin): Membership {
            // Locked so two platform admins can't pick two different members at once.
            Company::query()->whereKey($company->id)->lockForUpdate()->first();

            // Never the platform admin: the platform doesn't run companies.
            $membership = ActiveMembers::only($company->memberships()->getQuery(), apartFrom: $platformAdmin)
                ->whereKey($membershipId)
                ->with('user:id,name,email')
                ->first();

            if ($membership === null) {
                throw ValidationException::withMessages(['membership_id' => __('Choose one of the company\'s active members.')]);
            }

            if ($company->hasActiveAdmin()) {
                throw ValidationException::withMessages(['membership_id' => __('This company already has an admin, who invites people.')]);
            }

            $membership->update(['role' => Role::Admin]);

            Membership::query()
                ->where('company_id', $company->id)
                ->where('role', Role::Admin)
                ->pendingInvitation()
                ->update(['deactivated_at' => now(), 'invitation_token' => null, 'updated_at' => now()]);

            return $membership;
        });

        $membership->user->notify(new MadeCompanyAdmin($company->name, $company->slug));

        return $membership;
    }
}
