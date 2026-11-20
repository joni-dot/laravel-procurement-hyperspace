<?php

namespace Tests\Feature\Api;

use App\Companies\CurrentCompany;
use App\Models\ApiClient;
use App\Models\Company;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\Concerns\IssuesApiTokens;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * An API client belongs to one company and only ever sees that company's data.
 */
class ApiClientCompanyTest extends TestCase
{
    use IssuesApiTokens;
    use RefreshDatabase;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPassportKeys();
        $this->other = Company::factory()->create();
    }

    public function test_a_client_sees_only_its_own_companys_suppliers()
    {
        $ours = Supplier::factory()->create();
        $theirs = app(CurrentCompany::class)->runAs($this->other, fn () => Supplier::factory()->create());
        $headers = $this->headersFor($this->createApiClient());

        // The test itself stands in the other company, so only the client's token can open the right one.
        $this->enterCompany($this->other);

        $this->getJson(route('api.v1.suppliers.index', ['include_archived' => 'true']), $headers)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$ours->id]);
        $this->getJson(route('api.v1.suppliers.show', $ours), $headers)->assertOk();
        $this->getJson(route('api.v1.suppliers.show', $theirs), $headers)
            ->assertNotFound()
            ->assertJsonMissingPath('data');
    }

    public function test_the_api_works_without_any_company_open_beforehand()
    {
        $ours = Supplier::factory()->create();
        $headers = $this->headersFor($this->createApiClient());
        app(CurrentCompany::class)->clear();

        $this->getJson(route('api.v1.suppliers.index'), $headers)->assertOk()->assertJsonPath('data.*.id', [$ours->id]);
    }

    public function test_each_companys_client_gets_its_own_data()
    {
        app(CurrentCompany::class)->runAs($this->other, fn () => Supplier::factory()->count(2)->create());
        Supplier::factory()->create();
        $ourHeaders = $this->headersFor($this->createApiClient());
        $theirHeaders = $this->headersFor($this->createApiClient(company: $this->other));

        $this->getJson(route('api.v1.suppliers.index'), $theirHeaders)->assertJsonCount(2, 'data');
        $this->getJson(route('api.v1.suppliers.index'), $ourHeaders)->assertJsonCount(1, 'data');
    }

    public function test_clients_of_a_suspended_company_get_no_tokens_and_their_tokens_stop_working()
    {
        $client = $this->createApiClient();
        $headers = $this->headersFor($client);
        $this->suspend($this->company);

        $this->getJson(route('api.v1.suppliers.index'), $headers)->assertForbidden()->assertJsonMissingPath('data');
        $this->requestToken($client->id, $client->plainSecret)->assertUnauthorized()->assertJsonMissingPath('access_token');

        $this->company->forceFill(['suspended_at' => null])->save();
        $this->forgetRememberedClients();

        $this->getJson(route('api.v1.suppliers.index'), $headers)->assertOk();
        $this->requestToken($client->id, $client->plainSecret)->assertOk();
    }

    public function test_api_calls_are_logged_with_the_clients_company()
    {
        $headers = $this->headersFor($this->createApiClient());
        $log = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('api')->andReturn($log);

        $this->getJson(route('api.v1.suppliers.index'), $headers)->assertOk();

        $log->shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'API request'
            && $context['company_id'] === $this->company->id);
    }

    public function test_new_clients_belong_to_the_company_they_were_created_in()
    {
        $client = $this->createApiClient(company: $this->other);

        $this->assertSame($this->other->id, ApiClient::query()->findOrFail($client->id)->company_id);
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(ApiClient $client): array
    {
        return ['Authorization' => 'Bearer '.$this->accessTokenFor($client)];
    }

    private function suspend(Company $company): void
    {
        $company->forceFill(['suspended_at' => now()])->save();
        $this->forgetRememberedClients();
    }
}
