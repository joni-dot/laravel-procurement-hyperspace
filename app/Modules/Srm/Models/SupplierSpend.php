<?php

namespace App\Modules\Srm\Models;

use App\Modules\Srm\Database\Factories\SupplierSpendFactory;
use App\Modules\Srm\Enums\Currency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $supplier_id
 * @property int $year
 * @property string $amount
 * @property Currency $currency_code
 */
#[Fillable(['year', 'amount', 'currency_code'])]
#[UseFactory(SupplierSpendFactory::class)]
class SupplierSpend extends Model
{
    /** @use HasFactory<SupplierSpendFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'currency_code' => Currency::class,
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
