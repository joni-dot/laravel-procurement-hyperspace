<?php

namespace App\Modules\Ppr\Dashboard;

use App\Models\User;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Project;
use Illuminate\Database\Eloquent\Collection;

/**
 * A person's own projects and what needs them there, for the project dashboard and the home page. Only
 * projects the person is a member of count, admins included: an admin sees every project elsewhere, but
 * these pages are about their own work. The numbers themselves come from ProjectAttention.
 */
final class MyProjects
{
    /** @var array{tiles: list<array{projectId: int, tiles: array<string, int>}>, actions: list<array<string, mixed>>}|null */
    private ?array $attention = null;

    /**
     * @param  Collection<int, Project>  $projects  with my_role, the person's role in each
     */
    private function __construct(private readonly User $user, private readonly Collection $projects) {}

    public static function of(User $user): self
    {
        return new self($user, Project::query()
            ->join('project_members', 'project_members.project_id', '=', 'projects.id')
            ->where('project_members.user_id', $user->id)
            ->orderBy('projects.name')
            ->orderBy('projects.id')
            ->get(['projects.*', 'project_members.role as my_role']));
    }

    public function isEmpty(): bool
    {
        return $this->projects->isEmpty();
    }

    /**
     * @return list<array{id: int, name: string, code: string, role: string}>
     */
    public function summaries(): array
    {
        return array_values($this->projects->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'role' => ProjectRole::from($project->getAttribute('my_role'))->label(),
        ])->all());
    }

    /**
     * Each project's headline numbers, in the projects' order.
     *
     * @return list<array{projectId: int, tiles: array<string, int>}>
     */
    public function tiles(): array
    {
        return $this->attention()['tiles'];
    }

    /**
     * Everything the person must act on across their projects, the most overdue first.
     *
     * @return list<array<string, mixed>>
     */
    public function actions(): array
    {
        return $this->attention()['actions'];
    }

    /**
     * Worked out once per project, however many of the results a page asks for.
     *
     * @return array{tiles: list<array{projectId: int, tiles: array<string, int>}>, actions: list<array<string, mixed>>}
     */
    private function attention(): array
    {
        if ($this->attention !== null) {
            return $this->attention;
        }

        $tiles = [];
        $actions = [];
        // Once for every project, so a request running across midnight counts them all from the same day.
        $today = now('UTC')->toDateString();

        foreach ($this->projects as $project) {
            $attention = ProjectAttention::for($project, $today);
            $tiles[] = ['projectId' => $project->id, 'tiles' => $attention->tiles()];

            foreach ($attention->actionsFor($this->user->id, $this->user->notification_days) as $action) {
                $actions[] = ['projectId' => $project->id, 'projectCode' => $project->code, ...$action];
            }
        }

        usort($actions, fn (array $a, array $b) => [$a['date'], $a['projectCode'], $a['number']] <=> [$b['date'], $b['projectCode'], $b['number']]);

        return $this->attention = ['tiles' => $tiles, 'actions' => $actions];
    }
}
