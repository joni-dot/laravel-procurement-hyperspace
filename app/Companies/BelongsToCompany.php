<?php

namespace App\Companies;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For models whose rows belong to one company. Queries only see the current company's rows and new
 * rows join it. The database enforces the same (CompanyIsolation); this keeps the app's queries
 * honest on their own and lets PostgreSQL use the company indexes.
 *
 * @property int $company_id
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query): void {
            // No company means no rows, never every company's rows.
            $query->where($query->qualifyColumn('company_id'), app(CurrentCompany::class)->id() ?? 0);
        });

        static::creating(function (Model $model): void {
            $model->setAttribute('company_id', $model->getAttribute('company_id')
                ?? app(CurrentCompany::class)->id()
                ?? throw new LogicException('A '.class_basename($model).' can only be created inside a company.'));
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
