<?php

namespace App\Actions\Companies;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a company and invites its first admin. Both happen or neither does, so there is never a
 * company that nobody can be invited to run.
 */
class CreateCompany
{
    public function __construct(private readonly InviteMember $inviteMember) {}

    /**
     * Returns the first admin's invitation; its company is the new company.
     */
    public function handle(string $name, string $slug, string $adminName, string $adminEmail, ?User $createdBy = null): Membership
    {
        return DB::transaction(function () use ($name, $slug, $adminName, $adminEmail, $createdBy): Membership {
            $company = Company::query()->create(['name' => $name, 'slug' => $slug]);

            return $this->inviteMember->handle($company, $adminName, $adminEmail, Role::Admin, $createdBy);
        });
    }
}
