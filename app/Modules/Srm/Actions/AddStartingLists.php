<?php

namespace App\Modules\Srm\Actions;

use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\RatingCriterion;

/**
 * Gives a new company the lists it needs to start right away. Its admins can change them later.
 * Runs inside the new company.
 */
class AddStartingLists
{
    private const array CONTRACT_TYPES = ['Framework agreement', 'Purchase agreement', 'Service contract', 'Non-disclosure agreement', 'Other'];

    /** Equal weights that add up to 100%, so the overall rating score works from the first rating. */
    private const array RATING_CRITERIA = ['Quality' => 25, 'Delivery' => 25, 'Price' => 25, 'Service' => 25];

    public function handle(): void
    {
        foreach (self::CONTRACT_TYPES as $name) {
            ContractType::query()->create(['name' => $name]);
        }

        foreach (self::RATING_CRITERIA as $name => $weight) {
            // The weight isn't fillable from forms; here the app itself sets it.
            RatingCriterion::query()->forceCreate(['name' => $name, 'weight' => $weight]);
        }
    }
}
