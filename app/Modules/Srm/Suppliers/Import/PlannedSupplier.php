<?php

namespace App\Modules\Srm\Suppliers\Import;

/**
 * A row of the file that will become a new supplier, already tidied and checked like the supplier form.
 */
final readonly class PlannedSupplier
{
    /**
     * @param  array<string, mixed>  $attributes  the supplier's fields, only those the form may set
     * @param  list<int>  $categoryIds  categories the company already has
     * @param  list<string>  $newCategories  names of categories the import creates (admins only)
     * @param  array{name: string, email: string|null, phone: string|null, is_primary: true}|null  $contact
     */
    public function __construct(
        public int $row,
        public array $attributes,
        public array $categoryIds,
        public array $newCategories,
        public ?array $contact,
    ) {}
}
