<?php

namespace App\Modules\Srm\Suppliers\Import;

use Symfony\Component\Intl\Countries;

/**
 * What importing a file would do: the suppliers it adds and every row it leaves out or changes, and why.
 */
final readonly class SupplierImportPlan
{
    /** The preview lists at most this many problems; the counts still cover them all. */
    public const int PROBLEMS_SHOWN = 200;

    /** The preview shows this many of the suppliers to be added, so people can check the columns matched. */
    public const int SUPPLIERS_SHOWN = 10;

    /**
     * @param  list<PlannedSupplier>  $suppliers
     * @param  list<ImportProblem>  $problems  in row order
     * @param  list<string>  $newCategories
     * @param  list<string>  $ignoredColumns  headers of the file's columns that aren't imported
     */
    public function __construct(
        public array $suppliers,
        public array $problems,
        public int $skippedRows,
        public int $errorRows,
        public array $newCategories,
        public array $ignoredColumns,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(string $fileName): array
    {
        return [
            'fileName' => $fileName,
            'counts' => [
                'add' => count($this->suppliers),
                'skipped' => $this->skippedRows,
                'errors' => $this->errorRows,
            ],
            'problems' => array_map(
                fn (ImportProblem $problem) => $problem->toArray(),
                array_slice($this->problems, 0, self::PROBLEMS_SHOWN),
            ),
            'problemCount' => count($this->problems),
            'newCategories' => $this->newCategories,
            'ignoredColumns' => $this->ignoredColumns,
            'suppliers' => array_map($this->previewRow(...), array_slice($this->suppliers, 0, self::SUPPLIERS_SHOWN)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function previewRow(PlannedSupplier $supplier): array
    {
        return [
            'row' => $supplier->row,
            'name' => $supplier->attributes['name'],
            'supplier_number' => $supplier->attributes['supplier_number'] ?? null,
            'country' => Countries::getName($supplier->attributes['country_code'], app()->getLocale()),
            'city' => $supplier->attributes['city'] ?? null,
            'contact' => $supplier->contact['name'] ?? null,
        ];
    }
}
