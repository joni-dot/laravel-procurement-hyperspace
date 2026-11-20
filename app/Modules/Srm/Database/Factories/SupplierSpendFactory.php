<?php

namespace App\Modules\Srm\Database\Factories;

use App\Modules\Srm\Enums\Currency;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Models\SupplierSpend;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierSpend>
 */
class SupplierSpendFactory extends Factory
{
    protected $model = SupplierSpend::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'year' => (int) today()->year,
            'amount' => fake()->numberBetween(1_000, 900_000),
            'currency_code' => Currency::Eur,
        ];
    }
}
