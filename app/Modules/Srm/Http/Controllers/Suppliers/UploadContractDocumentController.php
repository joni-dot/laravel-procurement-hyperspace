<?php

namespace App\Modules\Srm\Http\Controllers\Suppliers;

use App\Companies\DailyLimits;
use App\Http\Controllers\Controller;
use App\Modules\Srm\Actions\Documents\StoreDocument;
use App\Modules\Srm\Http\Requests\Suppliers\DocumentRequest;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class UploadContractDocumentController extends Controller
{
    public function __invoke(DocumentRequest $request, Supplier $supplier, Contract $contract, StoreDocument $storeDocument, DailyLimits $dailyLimits): RedirectResponse
    {
        $file = $request->uploadedFile();
        $dailyLimits->storingUpload($file, fn () => $storeDocument->handle($supplier, $contract, $file, $request->user()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document uploaded.')]);

        return back();
    }
}
