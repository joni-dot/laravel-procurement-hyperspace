<?php

namespace App\Modules\Sourcing\Models;

use App\Companies\BelongsToCompany;
use App\Modules\Ppr\Models\Package;
use App\Modules\Sourcing\Database\Factories\PackageSupplierFactory;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A supplier on a project package: asked for a quote, with an offer, declined, or chosen.
 *
 * @property int $id
 * @property int $package_id
 * @property int $supplier_id
 * @property QuoteStatus $status
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Package $package
 * @property-read Supplier $supplier
 */
#[UseFactory(PackageSupplierFactory::class)]
class PackageSupplier extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<PackageSupplierFactory> */
    use HasFactory;

    protected $fillable = ['status', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
