<?php

namespace App\Modules\Srm\Database\Factories;

use App\Modules\Srm\Models\RatingCriterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatingCriterion>
 */
class RatingCriterionFactory extends Factory
{
    protected $model = RatingCriterion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, asText: true),
        ];
    }
}
