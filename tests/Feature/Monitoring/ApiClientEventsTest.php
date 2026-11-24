<?php

namespace Tests\Feature\Monitoring;

use App\Actions\ApiClients\CreateApiClient;
use App\Actions\ApiClients\RegenerateApiClientSecret;
use App\Actions\ApiClients\RevokeApiClient;
use App\Actions\ApiClients\UpdateApiClient;
use App\Models\User;
use Tests\Concerns\ReadsSecurityLog;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Systems that read company data through the API: who let them in, with which rights, and who shut them out.
 */
class ApiClientEventsTest extends TestCase
{
    use ReadsSecurityLog, RefreshDatabase;

    public function test_creating_changing_a_secret_or_scopes_and_revoking_a_client_are_logged_without_its_secret()
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->captureLog();

        $client = app(CreateApiClient::class)->handle($this->company, 'ERP', ['suppliers:read'], $admin->id);
        $firstSecret = (string) $client->plainSecret;
        $newSecret = app(RegenerateApiClientSecret::class)->handle($client);
        app(UpdateApiClient::class)->handle($client, 'ERP', [], $admin->id);
        app(RevokeApiClient::class)->handle($client);

        $this->assertSame(['api_client.created', 'api_client.secret_regenerated', 'api_client.scopes_changed', 'api_client.revoked'], $this->securityEvents());
        $this->assertSame([
            'event' => 'api_client.created',
            'user_id' => $admin->id,
            'company_id' => $this->company->id,
            'api_client_id' => $client->id,
        ], $this->securityLine('api_client.created')['context']);
        $this->assertSecurityLogLeaves([$firstSecret, $newSecret]);
    }

    public function test_a_client_merely_being_used_is_not_a_security_event()
    {
        $admin = User::factory()->admin()->create();
        $client = app(CreateApiClient::class)->handle($this->company, 'ERP', ['suppliers:read'], $admin->id);
        $this->captureLog();

        $client->forceFill(['last_used_at' => now()])->save();

        $this->assertSame([], $this->securityEvents());
    }
}
