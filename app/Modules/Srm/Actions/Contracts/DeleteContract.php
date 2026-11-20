<?php

namespace App\Modules\Srm\Actions\Contracts;

use App\Modules\Srm\Actions\Documents\StoreDocument;
use App\Modules\Srm\Models\Contract;
use Illuminate\Support\Facades\Storage;

class DeleteContract
{
    public function handle(Contract $contract): void
    {
        $filePaths = $contract->documents()->pluck('file_path')->all();

        $contract->delete();

        Storage::disk(StoreDocument::DISK)->delete($filePaths);
    }
}
