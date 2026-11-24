<?php

namespace App\Http;

use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Reads the filters of a list page from the address. A filter is text, so anything else (such as a
 * list, from "?search[]=") counts as no filter rather than breaking the page.
 */
final class ListFilters
{
    public const int MAX_SEARCH_LENGTH = 100;

    public static function search(Request $request, string $key = 'search'): string
    {
        return Str::limit(trim(self::text($request, $key)), self::MAX_SEARCH_LENGTH, '');
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enumClass  a string-backed enum
     * @return TEnum|null
     */
    public static function choice(Request $request, string $key, string $enumClass): ?BackedEnum
    {
        $value = self::text($request, $key);

        return $value === '' ? null : $enumClass::tryFrom($value);
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }
}
