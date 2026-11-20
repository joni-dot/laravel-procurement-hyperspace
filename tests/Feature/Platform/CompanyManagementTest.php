<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Srm\Models\Supplier;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();
        // Platform pages ask for the password again unless it was entered recently.
        $this->withSession(['auth.password_confirmed_at' => time()]);
        Notification::fake();
    }

    public function test_the_list_shows_each_company_with_its_status_and_member_counts()
    {
        $this->company->update(['name' => 'Acme']);
        User::factory()->count(2)->create();
        User::factory()->invited()->create();
        User::factory()->create()->memberships()->update(['deactivated_at' => now()]);
        $suspended = Company::factory()->suspended()->create(['name' => 'Bolt']);

        $this->actingAs($this->platformAdmin)
            ->get(route('platform.companies.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('platform/companies/Index')
                ->has('companies.data', 2)
                ->where('companies.data.0', [
                    'id' => $this->company->id,
                    'name' => 'Acme',
                    'slug' => $this->company->slug,
                    'is_suspended' => false,
                    'active_members_count' => 2,
                    'pending_invitations_count' => 1,
                    'created_at' => today()->toDateString(),
                ])
                ->where('companies.data.1.id', $suspended->id)
                ->where('companies.data.1.is_suspended', true)
                ->where('companies.data.1.active_members_count', 0));
    }

    public function test_the_list_can_be_searched_by_name_or_address()
    {
        $this->company->update(['name' => 'Acme', 'slug' => 'acme']);
        Company::factory()->create(['name' => 'Bolt Industries', 'slug' => 'bolt']);

        $this->actingAs($this->platformAdmin)
            ->get(route('platform.companies.index', ['search' => 'industr']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('companies.data', 1)
                ->where('companies.data.0.slug', 'bolt')
                ->where('filters.search', 'industr'));

        $this->get(route('platform.companies.index', ['search' => 'acm']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('companies.data', 1)
                ->where('companies.data.0.slug', 'acme'));
    }

    public function test_the_list_is_paged()
    {
        Company::factory()->count(25)->create();

        $this->actingAs($this->platformAdmin)
            ->get(route('platform.companies.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('companies.data', 25)
                ->where('companies.total', 26));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_companies()
    {
        Company::factory()->count(2)->create();
        $few = $this->countQueriesOfTheList();

        foreach (Company::factory()->count(10)->create() as $company) {
            Membership::factory()->count(2)->create(['company_id' => $company->id]);
        }

        $this->assertSame($few, $this->countQueriesOfTheList());
    }

    public function test_platform_pages_read_no_company_data()
    {
        $supplier = Supplier::factory()->create(['name' => 'Secret Supplier Oy']);
        $tables = [];
        DB::listen(function ($query) use (&$tables) {
            preg_match_all('/\b(?:from|join|into|update)\s+"?(\w+)"?/i', $query->sql, $matches);
            array_push($tables, ...$matches[1]);
        });

        $response = $this->actingAs($this->platformAdmin)->get(route('platform.companies.index'))->assertOk();

        $response->assertDontSee($supplier->name);
        $this->assertSame([], array_values(array_diff(array_unique($tables), ['companies', 'memberships', 'users', 'sessions'])));
    }

    public function test_a_platform_admin_creates_a_company_and_invites_its_first_admin()
    {
        $this->actingAs($this->platformAdmin)
            ->post(route('platform.companies.store'), $this->validInput())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('platform.companies.index'));

        $company = Company::query()->where('slug', 'acme')->sole();
        $invitation = Membership::query()->where('company_id', $company->id)->sole();
        $admin = User::query()->where('email', 'anna@example.com')->sole();

        $this->assertSame('Acme Oy', $company->name);
        $this->assertSame($admin->id, $invitation->user_id);
        $this->assertSame(Role::Admin, $invitation->role);
        $this->assertNull($invitation->joined_at);
        $this->assertSame($this->platformAdmin->id, $invitation->invited_by_user_id);
        Notification::assertSentTo($admin, UserInvitation::class);
    }

    public function test_the_answer_is_the_same_whether_or_not_the_email_has_an_account()
    {
        $existing = User::factory()->withoutCompany()->create(['name' => 'Bea Existing', 'email' => 'bea@example.com']);

        $answers = [];
        foreach (['new' => 'nobody@example.com', 'existing' => 'BEA@example.com'] as $kind => $email) {
            $response = $this->actingAs($this->platformAdmin)
                ->post(route('platform.companies.store'), $this->validInput(['slug' => "company-{$kind}", 'admin_email' => $email]))
                ->assertSessionHasNoErrors();

            $answers[$kind] = [
                'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'),
                'toast' => str_replace(strtolower($email), '{email}', session(SessionKey::FLASH_DATA)['toast']['message']),
            ];
        }

        $this->assertSame($answers['new'], $answers['existing']);
        $this->assertSame('Bea Existing', $existing->fresh()->name);
        Notification::assertSentTo($existing, UserInvitation::class);
    }

    public function test_input_is_validated()
    {
        $this->actingAs($this->platformAdmin);

        $this->post(route('platform.companies.store'), $this->validInput(['name' => '']))->assertSessionHasErrors('name');
        $this->post(route('platform.companies.store'), $this->validInput(['admin_name' => '']))->assertSessionHasErrors('admin_name');
        $this->post(route('platform.companies.store'), $this->validInput(['admin_email' => 'not-an-email']))->assertSessionHasErrors('admin_email');

        foreach (['', 'has space', 'ends-with-dash-', '-starts-with-dash', 'under_score', 'ä-umlaut', str_repeat('a', 41)] as $slug) {
            $this->post(route('platform.companies.store'), $this->validInput(['slug' => $slug]))->assertSessionHasErrors('slug');
        }

        foreach (['platform', 'settings', 'login', 'api', 'invitation', 'www'] as $reserved) {
            $this->post(route('platform.companies.store'), $this->validInput(['slug' => $reserved]))->assertSessionHasErrors('slug');
        }

        $this->assertSame(1, Company::count());
        Notification::assertNothingSent();
    }

    public function test_the_address_is_saved_in_lowercase()
    {
        $this->actingAs($this->platformAdmin)
            ->post(route('platform.companies.store'), $this->validInput(['slug' => ' ACME ']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Company::query()->where('slug', 'acme')->exists());
    }

    public function test_an_address_used_by_another_company_now_or_before_is_refused()
    {
        $oldSlug = $this->company->slug;
        $this->company->update(['slug' => 'current-address']);

        $this->actingAs($this->platformAdmin);
        $this->post(route('platform.companies.store'), $this->validInput(['slug' => 'current-address']))->assertSessionHasErrors('slug');
        $this->post(route('platform.companies.store'), $this->validInput(['slug' => $oldSlug]))->assertSessionHasErrors('slug');

        $other = Company::factory()->create();
        $this->put(route('platform.companies.update', ['company' => $other->id]), ['name' => $other->name, 'slug' => $oldSlug])->assertSessionHasErrors('slug');

        $this->assertSame(2, Company::count());
    }

    public function test_renaming_the_address_keeps_old_links_working()
    {
        $oldSlug = $this->company->slug;
        $member = User::factory()->create();

        $this->actingAs($this->platformAdmin)
            ->put(route('platform.companies.update', ['company' => $this->company->id]), ['name' => 'Acme Group', 'slug' => 'acme-group'])
            ->assertSessionHasNoErrors();

        $this->assertSame('acme-group', $this->company->fresh()->slug);
        $this->assertSame('Acme Group', $this->company->fresh()->name);
        $this->assertStringContainsString("/{$oldSlug} keep working", session(SessionKey::FLASH_DATA)['toast']['message']);

        $this->actingAs($member)->get("/{$oldSlug}/suppliers")->assertRedirect('/acme-group/suppliers');
    }

    public function test_a_company_may_keep_its_address_or_go_back_to_an_old_one()
    {
        $oldSlug = $this->company->slug;
        $this->actingAs($this->platformAdmin);

        $this->put(route('platform.companies.update', ['company' => $this->company->id]), ['name' => 'Same address', 'slug' => $oldSlug])
            ->assertSessionHasNoErrors();
        $this->assertSame('Company updated.', session(SessionKey::FLASH_DATA)['toast']['message']);

        $this->put(route('platform.companies.update', ['company' => $this->company->id]), ['name' => 'Same address', 'slug' => 'new-address'])
            ->assertSessionHasNoErrors();
        $this->put(route('platform.companies.update', ['company' => $this->company->id]), ['name' => 'Same address', 'slug' => $oldSlug])
            ->assertSessionHasNoErrors();

        $this->assertSame($oldSlug, $this->company->fresh()->slug);
    }

    public function test_suspending_closes_the_company_to_its_members_and_reopening_gives_it_back()
    {
        $member = User::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->actingAs($this->platformAdmin)
            ->post(route('platform.companies.suspend', ['company' => $this->company->id]))
            ->assertRedirect();
        $this->assertTrue($this->company->fresh()->isSuspended());

        $this->actingAs($member)
            ->get("/{$this->company->slug}/suppliers/{$supplier->id}")
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('companies/Suspended'));
        // Home no longer opens the suspended company.
        $home = $this->get(route('home'))->headers->get('Location') ?? '';
        $this->assertStringNotContainsString("/{$this->company->slug}/", $home);

        $this->actingAs($this->platformAdmin)
            ->post(route('platform.companies.reopen', ['company' => $this->company->id]))
            ->assertRedirect();
        $this->assertFalse($this->company->fresh()->isSuspended());

        $this->actingAs($member)->get("/{$this->company->slug}/suppliers/{$supplier->id}")->assertOk();
    }

    public function test_an_unknown_company_is_not_found()
    {
        $this->actingAs($this->platformAdmin)->post(route('platform.companies.suspend', ['company' => 999999]))->assertNotFound();
        $this->post('/platform/companies/not-a-number/suspend')->assertNotFound();
    }

    private function countQueriesOfTheList(): int
    {
        // Each request then starts the same way: inside a company, which the platform pages leave.
        $this->enterCompany($this->company);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->platformAdmin)->get(route('platform.companies.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validInput(array $overrides = []): array
    {
        return [
            'name' => 'Acme Oy',
            'slug' => 'acme',
            'admin_name' => 'Anna Admin',
            'admin_email' => 'anna@example.com',
            ...$overrides,
        ];
    }
}
