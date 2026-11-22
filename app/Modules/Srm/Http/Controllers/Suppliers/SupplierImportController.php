<?php

namespace App\Modules\Srm\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Modules\Srm\Actions\Suppliers\ImportSuppliers;
use App\Modules\Srm\Http\Requests\Suppliers\SupplierImportRequest;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Suppliers\Import\ImportColumn;
use App\Modules\Srm\Suppliers\Import\SupplierImportPlan;
use App\Modules\Srm\Suppliers\Import\SupplierImportPlanner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importing suppliers from a file. The page previews the file first (PreviewSupplierImportController);
 * the import reads and checks the same file again, as files are never kept between requests.
 */
class SupplierImportController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('srm/suppliers/Import', [
            'columns' => array_map(fn (ImportColumn $column) => [
                'header' => $column->value,
                'label' => $column->label(),
                'hint' => $column->hint(),
                'required' => $column->isRequired(),
            ], ImportColumn::cases()),
            'maxRows' => SupplierImportPlanner::MAX_ROWS,
            'canCreateCategories' => $request->user()->can('manage', Category::class),
        ]);
    }

    public function store(SupplierImportRequest $request, SupplierImportPlanner $planner, ImportSuppliers $importSuppliers): RedirectResponse
    {
        $plan = $request->plan($planner);

        if ($plan->suppliers === []) {
            throw ValidationException::withMessages(['file' => __('No row of the file can be added.')]);
        }

        try {
            $importSuppliers->handle($plan);
        } catch (UniqueConstraintViolationException) {
            // Someone added the same supplier or category while this file was being imported.
            throw ValidationException::withMessages(['file' => __('Suppliers changed while the file was being imported. Nothing was saved; please check the file again.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->summary($plan)]);

        return to_route('suppliers.index');
    }

    private function summary(SupplierImportPlan $plan): string
    {
        return implode(' ', array_filter([
            trans_choice(':count supplier added.|:count suppliers added.', count($plan->suppliers)),
            $plan->skippedRows > 0 ? trans_choice(':count row skipped.|:count rows skipped.', $plan->skippedRows) : null,
            $plan->errorRows > 0 ? trans_choice(':count row with errors left out.|:count rows with errors left out.', $plan->errorRows) : null,
        ]));
    }
}
