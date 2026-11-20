<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::limit(Str::slug($name), 30, '').'-'.Str::lower(Str::random(6)),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => ['suspended_at' => now()]);
    }
}
