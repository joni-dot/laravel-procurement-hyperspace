<?php

namespace App\Models;

use App\Companies\BelongsToCompany;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Settings that admins can change in the app, stored as simple key/value pairs.
 *
 * Each module names its own keys with a string-backed enum, so Core doesn't need to know them.
 *
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    use BelongsToCompany;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    /**
     * The real key is (company_id, key). Eloquent saves by the key column alone and without global
     * scopes, so the company is added here; otherwise only the database's row-level security would
     * stop an update from touching another company's setting.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query): Builder
    {
        return $query->where($this->getKeyName(), $this->getKeyForSaveQuery())->where('company_id', $this->company_id);
    }

    public static function get(BackedEnum $key): ?string
    {
        return static::query()->whereKey((string) $key->value)->value('value');
    }

    public static function put(BackedEnum $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => (string) $key->value], ['value' => $value]);
    }
}
