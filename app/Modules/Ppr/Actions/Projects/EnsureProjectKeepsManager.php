<?php

namespace App\Modules\Ppr\Actions\Projects;

use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class EnsureProjectKeepsManager
{
    /**
     * Refuses to demote or remove the project's only project manager, so someone
     * besides the admins can always run the project. Call it inside a transaction:
     * it locks the project so two simultaneous changes can't both pass.
     *
     * @throws ValidationException
     */
    public function handle(ProjectMember $member): void
    {
        Project::query()->whereKey($member->project_id)->lockForUpdate()->first();
        $member->refresh();

        if ($member->role !== ProjectRole::ProjectManager) {
            return;
        }

        // A manager who has left the company can't run the project, so they don't count.
        $otherManagers = ProjectMember::query()
            ->where('project_id', $member->project_id)
            ->where('role', ProjectRole::ProjectManager)
            ->whereKeyNot($member->id)
            ->whereHas('user', fn (Builder $user) => $user->activeInCurrentCompany())
            ->exists();

        if (! $otherManagers) {
            throw ValidationException::withMessages([
                'role' => __('A project needs at least one project manager. Make someone else project manager first.'),
            ]);
        }
    }
}
