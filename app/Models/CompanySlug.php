<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address a company has, or had. Old ones forward to the company's current address.
 *
 * @property string $slug
 * @property int $company_id
 */
class CompanySlug extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
