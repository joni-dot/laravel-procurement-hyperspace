<?php

namespace App\Modules\Ppr\Access;

use App\Models\User;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectMember;

/**
 * People's roles in projects, looked up once per request, so several permission checks on one page
 * cost one query. Scoped: a fresh one for every request and queued job.
 */
final class ProjectRoles
{
    /** @var array<string, ProjectRole|null> per "user id:project id" */
    private array $roles = [];

    /**
     * The person's role in the project, or null when they are not a member.
     */
    public function of(User $user, Project $project): ?ProjectRole
    {
        $key = "{$user->id}:{$project->id}";

        if (! array_key_exists($key, $this->roles)) {
            $this->roles[$key] = ProjectMember::query()
                ->where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->value('role');
        }

        return $this->roles[$key];
    }
}
