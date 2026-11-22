<?php

namespace App\Modules\Srm\Http\Controllers\Suppliers;

use App\Files\Uploads;
use App\Http\Controllers\Controller;
use App\Modules\Srm\Actions\Certificates\SaveCertificate;
use App\Modules\Srm\Http\Requests\Suppliers\CertificateRequest;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateController extends Controller
{
    public function store(CertificateRequest $request, Supplier $supplier, SaveCertificate $saveCertificate): RedirectResponse
    {
        $saveCertificate->handle(
            $supplier->certificates()->make(),
            $request->certificateAttributes(),
            $request->reminderDays(),
            $request->uploadedFile(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Certificate added.')]);

        return back();
    }

    public function update(CertificateRequest $request, Supplier $supplier, Certificate $certificate, SaveCertificate $saveCertificate): RedirectResponse
    {
        $saveCertificate->handle(
            $certificate,
            $request->certificateAttributes(),
            $request->reminderDays(),
            $request->uploadedFile(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Certificate updated.')]);

        return back();
    }

    public function destroy(Supplier $supplier, Certificate $certificate): RedirectResponse
    {
        $certificate->delete();

        if ($certificate->file_path) {
            // The certificate is already deleted, so a storage outage must not fail the request;
            // rescue() reports it, and a file left behind is harmless: nothing links to it.
            rescue(fn () => Uploads::disk()->delete((string) $certificate->file_path));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Certificate deleted.')]);

        return back();
    }

    /**
     * Always sent as a download, never shown inside the app, so a crafted file cannot run in the app's context.
     */
    public function show(Supplier $supplier, Certificate $certificate): StreamedResponse
    {
        abort_unless($certificate->file_path && $certificate->file_name, 404);

        return Uploads::disk()->download(
            $certificate->file_path,
            $certificate->file_name,
            ['Content-Type' => $certificate->file_mime_type ?? 'application/octet-stream'],
        );
    }
}
