<?php

namespace App\Models;

use BackedEnum;
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
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    public static function get(BackedEnum $key): ?string
    {
        return static::query()->whereKey((string) $key->value)->value('value');
    }

    public static function put(BackedEnum $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => (string) $key->value], ['value' => $value]);
    }
}
