<?php

namespace App\Modules\Srm\Database\Factories;

use App\Modules\Srm\Models\CertificateType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificateType>
 */
class CertificateTypeFactory extends Factory
{
    protected $model = CertificateType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'ISO '.fake()->unique()->numberBetween(1000, 99999),
            'is_required' => false,
        ];
    }

    public function required(): static
    {
        return $this->state(fn (array $attributes) => ['is_required' => true]);
    }
}
