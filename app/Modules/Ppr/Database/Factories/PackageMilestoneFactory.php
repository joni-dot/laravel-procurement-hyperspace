<?php

namespace App\Modules\Ppr\Database\Factories;

use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\PackageMilestone;
use App\Modules\Ppr\Models\ProjectMilestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageMilestone>
 */
class PackageMilestoneFactory extends Factory
{
    protected $model = PackageMilestone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'project_milestone_id' => ProjectMilestone::factory(),
        ];
    }
}
