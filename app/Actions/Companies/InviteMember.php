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

            // An invitation already sent to this address is sent again, even if the account behind
            // it has changed its address since. Looking the account up by its current address
            // instead would add a second row, which would tell the inviter an account existed.
            $membership = Membership::query()
                ->where('company_id', $company->id)
                ->whereNull('joined_at')
                ->where('known_email', $email)
                ->first()
                ?? Membership::query()->firstOrNew([
                    'company_id' => $company->id,
                    'user_id' => (User::query()->where('email', $email)->first()
                        ?? User::query()->create(['name' => $name, 'email' => $email]))->id,
                ]);

            if ($membership->joined_at !== null) {
                throw new LogicException('This person is already a member of the company.');
            }

            $membership->fill([
                'role' => $role,
                'known_name' => $name,
                'known_email' => $email,
                'invited_by_user_id' => $invitedBy?->id,
            ]);
            // Inviting again after the invitation was withdrawn makes it a live invitation again.
            $membership->deactivated_at = null;
            $membership->save();

            $this->sendInvitation->handle($membership);

            return $membership;
        });
    }
}
