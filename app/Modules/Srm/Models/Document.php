<?php

namespace App\Modules\Srm\Models;

use App\Modules\Srm\Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file belonging to a contract, or to the supplier in general when contract_id is empty.
 *
 * @property int $id
 * @property int $supplier_id
 * @property int|null $contract_id
 * @property int|null $uploaded_by_user_id
 * @property string $file_path
 * @property string $file_name
 * @property string $file_mime_type
 * @property int $file_size
 */
#[UseFactory(DocumentFactory::class)]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isContractDocument(): bool
    {
        return $this->contract_id !== null;
    }
}
