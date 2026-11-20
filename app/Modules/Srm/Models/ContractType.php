<?php

namespace App\Modules\Srm\Models;

use App\Companies\BelongsToCompany;
use App\Modules\Srm\Database\Factories\ContractTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 */
#[Fillable(['name'])]
#[UseFactory(ContractTypeFactory::class)]
class ContractType extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ContractTypeFactory> */
    use HasFactory;

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
