<?php

namespace App\Modules\Srm\Models;

use App\Companies\BelongsToCompany;
use App\Modules\Srm\Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $supplier_id
 * @property string $name
 * @property string|null $job_title
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 */
#[Fillable(['name', 'job_title', 'email', 'phone', 'is_primary'])]
#[UseFactory(ContactFactory::class)]
class Contact extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
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
