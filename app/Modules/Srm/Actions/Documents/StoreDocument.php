<?php

namespace App\Modules\Srm\Actions\Documents;

use App\Files\UploadedFileName;
use App\Files\Uploads;
use App\Models\User;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Document;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

class StoreDocument
{
    public function handle(Supplier $supplier, ?Contract $contract, UploadedFile $file, User $uploadedBy): Document
    {
        // Each company's files have a folder of their own, so they can be exported or removed together.
        $filePath = $file->store("companies/{$supplier->company_id}/documents/{$supplier->id}", Uploads::diskName());

        if ($filePath === false) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }

        $document = new Document;
        $document->supplier_id = $supplier->id;
        $document->contract_id = $contract?->id;
        $document->uploaded_by_user_id = $uploadedBy->id;
        $document->file_path = $filePath;
        $document->file_name = UploadedFileName::of($file);
        $document->file_mime_type = (string) $file->getMimeType();
        $document->file_size = (int) $file->getSize();

        try {
            $document->save();
        } catch (Throwable $exception) {
            Uploads::disk()->delete($filePath);

            throw $exception;
        }

        return $document;
    }
}
