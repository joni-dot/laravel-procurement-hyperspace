<?php

namespace App\Modules\Srm\Actions\Contracts;

use App\Files\Uploads;
use App\Modules\Srm\Models\Contract;

class DeleteContract
{
    public function handle(Contract $contract): void
    {
        $filePaths = $contract->documents()->pluck('file_path')->all();

        $contract->delete();

        // The contract and its documents are already deleted, so a storage outage must not fail the
        // request; rescue() reports it, and files left behind are harmless: nothing links to them.
        rescue(fn () => Uploads::disk()->delete($filePaths));
    }
}
