<?php

namespace App\Actions\Companies;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Invites a person to a company. Someone without an account gets one (without a password, which
 * they set when accepting); someone with an account must accept before they join. Either way the
 * inviter sees the same result, so inviting doesn't tell them whether the address has an account.
 */
class InviteMember
{
    public function __construct(private readonly SendInvitation $sendInvitation) {}

    public function handle(Company $company, string $name, string $email, Role $role, ?User $invitedBy = null): Membership
    {
        return DB::transaction(function () use ($company, $name, $email, $role, $invitedBy): Membership {
            $email = Str::lower(trim($email));
            $user = User::query()->where('email', $email)->first()
                ?? User::query()->create(['name' => $name, 'email' => $email]);

            $membership = Membership::query()->firstOrNew(['company_id' => $company->id, 'user_id' => $user->id]);

            if ($membership->joined_at !== null) {
                throw new LogicException('This person is already a member of the company.');
            }

            $membership->fill([
                'role' => $role,
                'invited_name' => $name,
                'invited_by_user_id' => $invitedBy?->id,
            ])->save();

            $this->sendInvitation->handle($membership);

            return $membership;
        });
    }
}
