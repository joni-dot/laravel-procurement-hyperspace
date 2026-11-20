<?php

namespace App\Modules\Srm\Actions\Documents;

use App\Models\User;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Document;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StoreDocument
{
    /**
     * Files live on the private disk, which is never served directly by the web server.
     */
    public const string DISK = 'local';

    public function handle(Supplier $supplier, ?Contract $contract, UploadedFile $file, User $uploadedBy): Document
    {
        // Each company's files have a folder of their own, so they can be exported or removed together.
        $filePath = $file->store("companies/{$supplier->company_id}/documents/{$supplier->id}", self::DISK);

        if ($filePath === false) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        $document = new Document;
        $document->supplier_id = $supplier->id;
        $document->contract_id = $contract?->id;
        $document->uploaded_by_user_id = $uploadedBy->id;
        $document->file_path = $filePath;
        $document->file_name = mb_substr($file->getClientOriginalName(), 0, 255);
        $document->file_mime_type = (string) $file->getMimeType();
        $document->file_size = (int) $file->getSize();

        try {
            $document->save();
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($filePath);

            throw $exception;
        }

        return $document;
    }
}
