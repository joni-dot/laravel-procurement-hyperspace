<?php

namespace App\Companies;

use App\Models\ApiClient;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

/**
 * Passport's client lookup for the token endpoint, which also turns away clients of a suspended
 * company: they get the same "invalid client" answer as a revoked client, and no token.
 */
class ApiClientRepository extends ClientRepository
{
    public function findActive(string|int $id): ?Client
    {
        $client = parent::findActive($id);

        if (! $client instanceof ApiClient) {
            return null;
        }

        // Passport looks the client up more than once per token request; find() returns the same
        // object each time, so the company is read only once.
        $client->loadMissing('company:id,suspended_at');

        return $client->company?->isSuspended() === false ? $client : null;
    }
}
