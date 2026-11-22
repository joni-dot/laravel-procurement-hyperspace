<?php

namespace App\Modules\Srm\Suppliers;

use App\Modules\Srm\Enums\Currency;
use App\Modules\Srm\Enums\DeliveryTerm;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Rules\CountryCode;
use App\Modules\Srm\Rules\FinnishBusinessId;
use BackedEnum;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * How a supplier's own fields are tidied and checked. The supplier form and the file import both use it,
 * so a supplier is the same whichever way it arrives. Checks that need the database (unique numbers,
 * the responsible buyer, categories) are added by each user, because the import looks them up in bulk.
 */
final class SupplierFields
{
    /** The status a new supplier gets when none is chosen; the form preselects the same. */
    public const SupplierStatus DEFAULT_STATUS = SupplierStatus::Prospect;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> only the tidied fields; merge them over the input
     */
    public static function tidy(array $input): array
    {
        return [
            'country_code' => self::text($input, 'country_code', Str::upper(...)) ?? '',
            'business_id' => self::text($input, 'business_id', Str::upper(...)),
            'vat_number' => self::text($input, 'vat_number', fn (string $vat) => Str::upper(Str::remove(' ', $vat))),
            'email' => self::text($input, 'email', Str::lower(...)),
            'website' => self::text($input, 'website', self::websiteWithScheme(...)),
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'supplier_number' => ['nullable', 'string', 'max:50'],
            'status' => ['required', self::oneOf(SupplierStatus::cases())],
            'responsible_user_id' => ['nullable', 'integer'],
            'country_code' => ['required', 'string', new CountryCode],
            'business_id' => [
                'nullable', 'string', 'max:50',
                Rule::when(fn (Fluent $input) => $input->get('country_code') === 'FI', [new FinnishBusinessId]),
            ],
            'vat_number' => ['nullable', 'string', 'max:50'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+()\-\s]+$/'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', self::oneOf(Currency::cases())],
            'delivery_term' => ['nullable', self::oneOf(DeliveryTerm::cases())],
            'delivery_place' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The same check as Rule::enum(), which is many times slower: Laravel turns it into text again and
     * again while checking a value.
     *
     * @param  list<BackedEnum>  $cases
     */
    private static function oneOf(array $cases): In
    {
        return Rule::in(array_map(fn (BackedEnum $case) => $case->value, $cases));
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'business_id.unique' => __('A supplier from this country already has this business ID.'),
            'supplier_number.unique' => __('Another supplier already has this supplier number.'),
            'phone.regex' => __('The phone number may only contain digits, spaces and + ( ) -.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'business_id' => __('business ID'),
            'vat_number' => __('VAT number'),
            'country_code' => __('country'),
            'currency_code' => __('currency'),
            'responsible_user_id' => __('responsible buyer'),
        ];
    }

    /**
     * Tidies a filled-in text; leaves anything that isn't text for the rules to refuse.
     *
     * @param  array<string, mixed>  $input
     * @param  callable(string): string  $tidy
     */
    private static function text(array $input, string $key, callable $tidy): mixed
    {
        $value = $input[$key] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            return $value;
        }

        return trim((string) $value) === '' ? null : $tidy((string) $value);
    }

    /**
     * People often type "example.com", so assume https when no scheme is given.
     */
    private static function websiteWithScheme(string $website): string
    {
        return Str::contains($website, '://') ? $website : "https://{$website}";
    }
}
