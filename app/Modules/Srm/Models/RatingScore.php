<?php

namespace App\Modules\Srm\Models;

use App\Companies\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $supplier_rating_id
 * @property int $rating_criterion_id
 * @property int $score
 */
#[Fillable(['rating_criterion_id', 'score'])]
class RatingScore extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    /**
     * @return BelongsTo<RatingCriterion, $this>
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RatingCriterion::class, 'rating_criterion_id');
    }
}
