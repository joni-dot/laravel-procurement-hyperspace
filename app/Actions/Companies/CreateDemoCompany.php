<?php

namespace App\Actions\Companies;

use App\Companies\DemoData;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a company filled with made-up data, marked as a demo, and invites its first admin like any other
 * company's. Everything happens in one transaction: filling takes about a second, so there is never a
 * half-filled demo company, and the invitation email (sent after the commit) only goes out once it's ready.
 */
class CreateDemoCompany
{
    public function __construct(
        private readonly CreateCompany $createCompany,
        private readonly DemoData $demoData,
    ) {}

    /**
     * Returns the first admin's invitation; its company is the new demo company.
     */
    public function handle(string $name, string $slug, string $adminName, string $adminEmail, ?User $createdBy = null): Membership
    {
        return DB::transaction(function () use ($name, $slug, $adminName, $adminEmail, $createdBy): Membership {
            $invitation = $this->createCompany->handle($name, $slug, $adminName, $adminEmail, $createdBy);
            $invitation->company->forceFill(['is_demo' => true])->save();

            $this->demoData->fill($invitation->company, $invitation->user);

            return $invitation;
        });
    }
}
