<?php

namespace App\Actions\Companies;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gets a company that has no admin yet going again: its first admin's invitation expired, or went
 * to a mistyped address. The invitation is sent again, or to the corrected address, and any other
 * admin invitation still waiting is withdrawn, so a mistyped address's link stops working.
 *
 * Only while the company has no active admin: after that its own admins invite people, and the
 * platform stays out of the company.
 */
class InviteFirstAdmin
{
    public function __construct(private readonly InviteMember $inviteMember) {}

    public function handle(Company $company, string $name, string $email, ?User $invitedBy = null): Membership
    {
        return DB::transaction(function () use ($company, $name, $email, $invitedBy): Membership {
            // Locked so two platform admins can't send two different first admins at once.
            Company::query()->whereKey($company->id)->lockForUpdate()->first();

            if ($company->hasActiveAdmin()) {
                throw new LogicException('The company already has an admin.');
            }

            $invitation = $this->inviteMember->handle($company, $name, $email, Role::Admin, $invitedBy);

            Membership::query()
                ->where('company_id', $company->id)
                ->whereKeyNot($invitation->id)
                ->where('role', Role::Admin)
                ->pendingInvitation()
                ->update(['deactivated_at' => now(), 'invitation_token' => null, 'updated_at' => now()]);

            return $invitation;
        });
    }
}
