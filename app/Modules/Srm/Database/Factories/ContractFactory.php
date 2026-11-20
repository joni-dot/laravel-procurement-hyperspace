<?php

namespace App\Modules\Srm\Database\Factories;

use App\Modules\Srm\Enums\ContractStatus;
use App\Modules\Srm\Enums\ContractValuePeriod;
use App\Modules\Srm\Enums\Currency;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'contract_type_id' => ContractType::factory(),
            'title' => fake()->company().' agreement',
            'contract_number' => fake()->unique()->bothify('C-#####'),
            'status' => ContractStatus::Active,
            'starts_on' => today()->subYear(),
            'ends_on' => today()->addYear(),
            'auto_renews' => false,
            'value' => fake()->numberBetween(1_000, 500_000),
            'currency_code' => Currency::Eur,
            'value_period' => ContractValuePeriod::Yearly,
        ];
    }

    public function endsInDays(int $days): static
    {
        return $this->state(fn (array $attributes) => ['ends_on' => today()->addDays($days)]);
    }

    public function autoRenewing(int $termMonths = 12, int $noticeDays = 90): static
    {
        return $this->state(fn (array $attributes) => [
            'auto_renews' => true,
            'renewal_term_months' => $termMonths,
            'notice_period_days' => $noticeDays,
        ]);
    }
}
