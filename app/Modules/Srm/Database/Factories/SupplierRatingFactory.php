<?php

namespace App\Modules\Srm\Database\Factories;

use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Models\SupplierRating;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierRating>
 */
class SupplierRatingFactory extends Factory
{
    protected $model = SupplierRating::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'rated_on' => today(),
            'overall_score' => '4.00',
        ];
    }
}
