<?php

namespace App\Policies;

use App\Models\ApiClient;
use App\Models\User;

class ApiClientPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * A revoked client stays revoked, so its history is kept; a new client is created instead. Route
     * binding already finds only the open company's clients; the company is checked here as well so
     * the rule holds wherever the policy is used.
     */
    public function update(User $user, ApiClient $apiClient): bool
    {
        return $user->isAdmin() && $apiClient->belongsToCurrentCompany() && ! $apiClient->revoked;
    }
}
