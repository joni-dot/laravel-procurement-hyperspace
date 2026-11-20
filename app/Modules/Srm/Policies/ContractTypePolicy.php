<?php

namespace App\Modules\Srm\Policies;

use App\Models\User;

class ContractTypePolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }
}
