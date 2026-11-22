<?php

namespace App\Modules\Srm\Http\Controllers\Suppliers;

use App\Http\Controllers\Controller;
use App\Modules\Srm\Http\Requests\Suppliers\SupplierImportRequest;
use App\Modules\Srm\Suppliers\Import\SupplierImportPlanner;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Shows what importing a file would do, saving nothing. The preview travels back to the import page as
 * one-time flash data, so the address stays the import page's and reloading it never posts again.
 */
class PreviewSupplierImportController extends Controller
{
    public function __invoke(SupplierImportRequest $request, SupplierImportPlanner $planner): RedirectResponse
    {
        $plan = $request->plan($planner);

        Inertia::flash('supplierImportPreview', $plan->preview($request->importFile()->getClientOriginalName()));

        return to_route('suppliers.import.create');
    }
}
