<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\RatingCriterion;
use App\Modules\Srm\Models\Supplier;
use Tests\Concerns\IssuesApiTokens;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Input of an unexpected shape gets a plain answer (not found, or a form error) rather than a server error.
 */
class UnexpectedInputTest extends TestCase
{
    use IssuesApiTokens;
    use RefreshDatabase;

    /** Larger than any id the database can hold. */
    private const string TOO_LARGE_ID = '99999999999999999999';

    public function test_record_addresses_with_ids_the_database_cannot_hold_are_not_found()
    {
        $manager = User::factory()->admin()->create();
        $project = Project::factory()->withMember($manager, ProjectRole::ProjectManager)->create();
        $package = Package::factory()->for($project)->create();
        $supplier = Supplier::factory()->create();
        $this->actingAs($manager);

        foreach ([
            route('suppliers.show', self::TOO_LARGE_ID),
            route('suppliers.show', 'not-a-number'),
            route('suppliers.contracts.show', [$supplier, self::TOO_LARGE_ID]),
            route('projects.show', self::TOO_LARGE_ID),
            route('projects.packages.show', [$project, self::TOO_LARGE_ID]),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->get(route('projects.packages.show', [$project, $package]))->assertOk();
    }

    public function test_api_addresses_with_ids_the_database_cannot_hold_are_not_found()
    {
        $this->setUpPassportKeys();
        $headers = ['Authorization' => 'Bearer '.$this->accessTokenFor($this->createApiClient())];

        $this->getJson('/api/v1/suppliers/'.self::TOO_LARGE_ID, $headers)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    }

    public function test_a_page_cursor_the_api_did_not_make_is_refused()
    {
        $this->setUpPassportKeys();
        $headers = ['Authorization' => 'Bearer '.$this->accessTokenFor($this->createApiClient())];
        Supplier::factory()->create();

        foreach ([['id' => 'abc', '_pointsToNextItems' => true], ['_pointsToNextItems' => true], ['id' => [1], '_pointsToNextItems' => true]] as $cursor) {
            $this->getJson(route('api.v1.suppliers.index', ['cursor' => base64_encode((string) json_encode($cursor))]), $headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('cursor');
        }
    }

    public function test_a_token_request_with_lists_instead_of_text_is_refused()
    {
        $this->setUpPassportKeys();
        $client = $this->createApiClient();

        foreach ([
            ['grant_type' => 'client_credentials', 'client_id' => [$client->id], 'client_secret' => $client->plainSecret],
            ['grant_type' => ['client_credentials'], 'client_id' => $client->id, 'client_secret' => $client->plainSecret],
        ] as $input) {
            $response = $this->post(route('api.oauth.token'), $input);

            $this->assertContains($response->getStatusCode(), [400, 401]);
            $response->assertJsonMissingPath('access_token');
        }
    }

    public function test_dates_before_1900_are_refused()
    {
        $supplier = Supplier::factory()->create();
        $this->actingAs(User::factory()->buyer()->create());

        $this->post(route('suppliers.contracts.store', $supplier), [
            'title' => 'Steel supply',
            'contract_type_id' => ContractType::query()->value('id'),
            'status' => 'active',
            'starts_on' => '0000-01-01',
            'ends_on' => '0000-12-31',
        ])->assertSessionHasErrors(['starts_on', 'ends_on']);

        $this->post(route('suppliers.certificates.store', $supplier), [
            'certificate_type_id' => CertificateType::factory()->create()->id,
            'issued_on' => '0000-01-01',
            'expires_on' => '1899-12-31',
        ])->assertSessionHasErrors(['issued_on', 'expires_on']);

        $this->post(route('suppliers.ratings.store', $supplier), [
            'rated_on' => '0000-01-01',
            'scores' => RatingCriterion::query()->inUse()->pluck('id')->mapWithKeys(fn (int $id) => [$id => 3])->all(),
        ])->assertSessionHasErrors('rated_on');

        $this->post(route('suppliers.contracts.store', $supplier), [
            'title' => 'Old supply',
            'contract_type_id' => ContractType::query()->value('id'),
            'status' => 'active',
            'starts_on' => '1900-01-01',
        ])->assertSessionHasNoErrors();
    }

    public function test_numbers_and_lists_where_dates_or_people_belong_are_form_errors()
    {
        $supplier = Supplier::factory()->create();
        $this->actingAs(User::factory()->buyer()->create());

        $this->postJson(route('suppliers.contracts.store', $supplier), [
            'title' => 'Steel supply',
            'contract_type_id' => ContractType::query()->value('id'),
            'status' => 'active',
            'starts_on' => 20000101,
            'ends_on' => 1e300,
            'owner_user_id' => 1e20,
        ])->assertUnprocessable()->assertJsonValidationErrors(['starts_on', 'ends_on', 'owner_user_id']);

        $this->postJson(route('suppliers.certificates.store', $supplier), [
            'certificate_type_id' => CertificateType::factory()->create()->id,
            'issued_on' => ['2020-01-01'],
            'expires_on' => '2030-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('issued_on');

        $this->postJson(route('suppliers.ratings.store', $supplier), [
            'rated_on' => 20200101,
            'scores' => RatingCriterion::query()->inUse()->pluck('id')->mapWithKeys(fn (int $id) => [$id => 3])->all(),
        ])->assertUnprocessable()->assertJsonValidationErrors('rated_on');
    }

    public function test_sign_in_and_password_fields_refuse_lists()
    {
        $this->postJson(route('password.update'), ['token' => 'x', 'email' => ['a@example.com'], 'password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertUnprocessable();

        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('password.confirm.store'), ['password' => ['password']])->assertUnprocessable();
        $this->actingAs($user)->putJson(route('user-password.update'), ['current_password' => ['password'], 'password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->actingAs($user)->patchJson(route('profile.update'), ['name' => 'X', 'email' => 'other@example.com', 'current_password' => ['password']])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_an_email_setting_that_is_not_text_is_a_form_error()
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->put(route('admin.settings.update'), ['reminder_email' => ['a@example.com']])
            ->assertSessionHasErrors('reminder_email');
    }
}
