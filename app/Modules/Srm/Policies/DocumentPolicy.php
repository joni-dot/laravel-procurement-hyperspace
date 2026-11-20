<?php

namespace App\Modules\Srm\Policies;

use App\Models\User;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Document;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return ! $document->isContractDocument() || $user->can('viewDetails', Contract::class);
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->can('update', $document->supplier);
    }
}
