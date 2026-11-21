<?php

namespace App\Modules\Sourcing\Database\Factories;

use App\Modules\Ppr\Models\Package;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageSupplier>
 */
class PackageSupplierFactory extends Factory
{
    protected $model = PackageSupplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'supplier_id' => Supplier::factory(),
            'status' => QuoteStatus::Asked,
        ];
    }

    public function chosen(): static
    {
        return $this->state(['status' => QuoteStatus::Chosen]);
    }
}
