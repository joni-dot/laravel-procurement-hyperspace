<?php

namespace App\Modules\Srm\Actions\Suppliers;

use App\Companies\CurrentCompany;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Suppliers\Import\PlannedSupplier;
use App\Modules\Srm\Suppliers\Import\SupplierImportPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Saves the suppliers of an import plan, all or nothing, into the current company.
 *
 * Suppliers and their contacts are inserted many rows at a time: saving 5,000 suppliers one by one through
 * the model took 10 to 14 seconds, close to PHP's time limit, against one to three seconds this way. That skips
 * model events, so this does what their listeners do (BelongsToCompany fills in the company), and
 * SupplierImportRowsTest fails when a new listener appears that would need the same.
 */
class ImportSuppliers
{
    private const int ROWS_PER_INSERT = 500;

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * @return int how many suppliers were added
     */
    public function handle(SupplierImportPlan $plan): int
    {
        if ($plan->suppliers === []) {
            return 0;
        }

        $companyId = $this->currentCompany->id() ?? throw new LogicException('Suppliers can only be imported inside a company.');
        $now = CarbonImmutable::now();

        DB::transaction(function () use ($plan, $companyId, $now): void {
            $newCategoryIds = $this->createCategories($plan->newCategories);
            $supplierIds = $this->newSupplierIds(count($plan->suppliers));
            $suppliers = $contacts = $categoryLinks = [];

            foreach ($plan->suppliers as $index => $planned) {
                $supplierId = $supplierIds[$index];
                $timestamps = ['company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now];
                $suppliers[] = [...$planned->attributes, 'id' => $supplierId, ...$timestamps];

                if ($planned->contact !== null) {
                    $contacts[] = [...$planned->contact, 'supplier_id' => $supplierId, ...$timestamps];
                }

                foreach ($this->categoryIds($planned, $newCategoryIds) as $categoryId) {
                    $categoryLinks[] = ['supplier_id' => $supplierId, 'category_id' => $categoryId, 'company_id' => $companyId];
                }
            }

            $this->insertInChunks('suppliers', $suppliers);
            $this->insertInChunks('contacts', $contacts);
            $this->insertInChunks('category_supplier', $categoryLinks);
        });

        return count($plan->suppliers);
    }

    /**
     * Ids from the suppliers' own sequence, taken in one query, so contacts and categories can point to
     * suppliers inserted many at a time.
     *
     * @return list<int>
     */
    private function newSupplierIds(int $count): array
    {
        return array_map(
            intval(...),
            array_column(DB::select("SELECT nextval(pg_get_serial_sequence('suppliers', 'id')) AS id FROM generate_series(1, ?)", [$count]), 'id'),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows  all with the same columns
     */
    private function insertInChunks(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * Through the model, as there are only a few.
     *
     * @param  list<string>  $names
     * @return array<string, int> lowercased name => id
     */
    private function createCategories(array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $ids[mb_strtolower($name)] = Category::create(['name' => $name])->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, int>  $newCategoryIds
     * @return list<int>
     */
    private function categoryIds(PlannedSupplier $planned, array $newCategoryIds): array
    {
        return [
            ...$planned->categoryIds,
            ...array_map(fn (string $name) => $newCategoryIds[mb_strtolower($name)], $planned->newCategories),
        ];
    }
}
