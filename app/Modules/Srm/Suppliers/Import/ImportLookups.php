<?php

namespace App\Modules\Srm\Suppliers\Import;

use App\Models\User;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Models\Supplier;
use Symfony\Component\Intl\Countries;

/**
 * Everything the import compares rows against, loaded in a few queries for the whole file rather than
 * row by row. All of it is the current company's (the models' company scope).
 */
final readonly class ImportLookups
{
    /**
     * @param  array<string, string>  $suppliersByNumber  supplier number => name, archived suppliers included
     * @param  array<string, string>  $suppliersByBusinessId  "country|business ID" => name
     * @param  array<string, int>  $categories  lowercased name => id
     * @param  array<string, int>  $buyers  lowercased email => id of an active buyer or admin
     * @param  array<string, string>  $countries  lowercased code or name => code
     * @param  array<string, string>  $statuses  lowercased value or label => value
     */
    private function __construct(
        public array $suppliersByNumber,
        public array $suppliersByBusinessId,
        public array $categories,
        public array $buyers,
        public array $countries,
        public array $statuses,
    ) {}

    /**
     * @param  list<string>  $supplierNumbers  the numbers in the file
     * @param  list<string>  $businessIds  the business IDs in the file
     */
    public static function load(array $supplierNumbers, array $businessIds): self
    {
        return new self(
            self::suppliersByNumber($supplierNumbers),
            self::suppliersByBusinessId($businessIds),
            Category::query()->pluck('id', 'name')->mapWithKeys(fn (int $id, string $name) => [mb_strtolower($name) => $id])->all(),
            User::query()->activeBuyersAndAdmins()->pluck('id', 'email')->mapWithKeys(fn (int $id, string $email) => [mb_strtolower($email) => $id])->all(),
            self::countries(),
            self::statuses(),
        );
    }

    public static function businessIdKey(string $countryCode, string $businessId): string
    {
        return "{$countryCode}|{$businessId}";
    }

    /**
     * @param  list<string>  $numbers
     * @return array<string, string>
     */
    private static function suppliersByNumber(array $numbers): array
    {
        if ($numbers === []) {
            return [];
        }

        return Supplier::query()->whereIn('supplier_number', array_values(array_unique($numbers)))
            ->pluck('name', 'supplier_number')
            ->all();
    }

    /**
     * @param  list<string>  $businessIds
     * @return array<string, string>
     */
    private static function suppliersByBusinessId(array $businessIds): array
    {
        if ($businessIds === []) {
            return [];
        }

        return Supplier::query()->whereIn('business_id', array_values(array_unique($businessIds)))
            ->get(['name', 'country_code', 'business_id'])
            ->mapWithKeys(fn (Supplier $supplier) => [
                self::businessIdKey($supplier->country_code, (string) $supplier->business_id) => $supplier->name,
            ])
            ->all();
    }

    /**
     * Codes, English names (the template's language) and names in the importer's language.
     *
     * @return array<string, string>
     */
    private static function countries(): array
    {
        $countries = [];

        foreach (array_unique(['en', app()->getLocale()]) as $locale) {
            foreach (Countries::getNames($locale) as $code => $name) {
                $countries[mb_strtolower($name)] = $code;
            }
        }

        foreach (Countries::getCountryCodes() as $code) {
            $countries[mb_strtolower($code)] = $code;
        }

        return $countries;
    }

    /**
     * @return array<string, string>
     */
    private static function statuses(): array
    {
        $statuses = [];

        foreach (SupplierStatus::cases() as $status) {
            $statuses[mb_strtolower($status->label())] = $status->value;
            $statuses[$status->value] = $status->value;
        }

        return $statuses;
    }
}
