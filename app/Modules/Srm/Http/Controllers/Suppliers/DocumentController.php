<?php

namespace App\Modules\Srm\Http\Controllers\Suppliers;

use App\Companies\DailyLimits;
use App\Files\Uploads;
use App\Http\Controllers\Controller;
use App\Modules\Srm\Actions\Documents\StoreDocument;
use App\Modules\Srm\Http\Requests\Suppliers\DocumentRequest;
use App\Modules\Srm\Models\Document;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function store(DocumentRequest $request, Supplier $supplier, StoreDocument $storeDocument, DailyLimits $dailyLimits): RedirectResponse
    {
        $file = $request->uploadedFile();
        $dailyLimits->storingUpload($file, fn () => $storeDocument->handle($supplier, null, $file, $request->user()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document uploaded.')]);

        return back();
    }

    /**
     * Always sent as a download, never shown inside the app, so a crafted file cannot run in the app's context.
     */
    public function show(Supplier $supplier, Document $document): StreamedResponse
    {
        return Uploads::disk()->download(
            $document->file_path,
            $document->file_name,
            ['Content-Type' => $document->file_mime_type],
        );
    }

    public function destroy(Supplier $supplier, Document $document): RedirectResponse
    {
        $document->delete();

        // The document is already deleted, so a storage outage must not fail the request;
        // rescue() reports it, and a file left behind is harmless: nothing links to it.
        rescue(fn () => Uploads::disk()->delete($document->file_path));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document deleted.')]);

        return back();
    }
}
