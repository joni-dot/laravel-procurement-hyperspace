<?php

namespace App\Modules\Ppr\Database\Factories;

use App\Models\User;
use App\Modules\Ppr\Actions\Projects\AddDefaultMilestones;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city().' '.fake()->randomElement(['plant', 'extension', 'upgrade', 'line']),
            'code' => 'P'.fake()->unique()->numberBetween(100, 99999),
            'description' => null,
            'risk_amber_days' => 7,
            'risk_red_days' => 0,
        ];
    }

    /**
     * The milestones a project made in the app starts with.
     */
    public function withDefaultMilestones(): static
    {
        return $this->afterCreating(fn (Project $project) => app(AddDefaultMilestones::class)->handle($project));
    }

    public function withMember(User $user, ProjectRole $role = ProjectRole::Viewer): static
    {
        return $this->afterCreating(function (Project $project) use ($user, $role) {
            ProjectMember::factory()->for($project)->for($user)->create(['role' => $role]);
        });
    }
}
