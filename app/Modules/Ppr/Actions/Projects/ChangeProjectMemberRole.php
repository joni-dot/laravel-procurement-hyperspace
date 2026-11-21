<?php

namespace App\Modules\Ppr\Actions\Projects;

use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\ProjectMember;
use Illuminate\Support\Facades\DB;

class ChangeProjectMemberRole
{
    public function __construct(
        private readonly EnsureProjectKeepsManager $ensureProjectKeepsManager,
        private readonly UnassignMemberFromPackages $unassignMemberFromPackages,
    ) {}

    public function handle(ProjectMember $member, ProjectRole $role): void
    {
        if ($member->role === $role) {
            return;
        }

        DB::transaction(function () use ($member, $role) {
            $this->ensureProjectKeepsManager->handle($member);

            $this->unassignMemberFromPackages->handle($member, $role);

            $member->role = $role;
            $member->save();
        });
    }
}
