<?php

namespace App\Modules\Ppr\Database\Factories;

use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectMilestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMilestone>
 */
class ProjectMilestoneFactory extends Factory
{
    protected $model = ProjectMilestone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => rtrim(fake()->unique()->sentence(3, false), '.'),
            'position' => fake()->unique()->numberBetween(0, 30000),
            'is_need_date' => false,
        ];
    }

    public function needDate(): static
    {
        return $this->state(['is_need_date' => true]);
    }
}
