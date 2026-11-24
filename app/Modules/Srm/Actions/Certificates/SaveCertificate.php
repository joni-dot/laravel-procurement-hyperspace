<?php

namespace App\Modules\Srm\Actions\Certificates;

use App\Companies\CurrentCompany;
use App\Files\UploadedFileName;
use App\Files\Uploads;
use App\Modules\Srm\Actions\Reminders\SyncReminders;
use App\Modules\Srm\Models\Certificate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class SaveCertificate
{
    public function __construct(
        private readonly SyncReminders $syncReminders,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $reminderDays
     */
    public function handle(Certificate $certificate, array $attributes, array $reminderDays, ?UploadedFile $file): Certificate
    {
        $replacedFilePath = $file ? $certificate->file_path : null;
        $storedFilePath = $file?->store($this->folderFor($certificate), Uploads::diskName());

        try {
            DB::transaction(function () use ($certificate, $attributes, $reminderDays, $file, $storedFilePath) {
                $certificate->fill($attributes);

                if ($file && $storedFilePath) {
                    $certificate->file_path = $storedFilePath;
                    $certificate->file_name = UploadedFileName::of($file);
                    $certificate->file_mime_type = $file->getMimeType();
                    $certificate->file_size = $file->getSize() ?: null;
                }

                $certificate->save();
                $this->syncReminders->handle($certificate->reminders(), $certificate->expires_on, $reminderDays);
            });
        } catch (Throwable $exception) {
            if ($storedFilePath) {
                Uploads::disk()->delete($storedFilePath);
            }

            throw $exception;
        }

        if ($replacedFilePath) {
            // The certificate is already saved with its new file, so a storage outage must not fail
            // the request; rescue() reports it, and the old file left behind is harmless.
            rescue(fn () => Uploads::disk()->delete($replacedFilePath));
        }

        return $certificate;
    }

    /**
     * Each company's files have a folder of their own, so they can be exported or removed together.
     * A new certificate gets its company when saved, which is the company open now.
     */
    private function folderFor(Certificate $certificate): string
    {
        $companyId = $certificate->company_id ?? $this->currentCompany->id()
            ?? throw new LogicException('A certificate can only be saved inside a company.');

        return "companies/{$companyId}/certificates/{$certificate->supplier_id}";
    }
}
