<?php

namespace Tests\Feature\Platform;

use App\Actions\Companies\CreateDemoCompany;
use App\Auth\StrongSignIn;
use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Notification as StoredNotification;
use App\Models\User;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Models\Supplier;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class CreateDemoCompanyTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();
        // Platform pages ask for the password again unless it was entered recently.
        $this->withSession(['auth.password_confirmed_at' => time(), StrongSignIn::SESSION_KEY => $this->platformAdmin->id]);
        Notification::fake();
        Mail::fake();
    }

    public function test_a_platform_admin_creates_a_demo_company_filled_with_made_up_data_and_invites_its_admin()
    {
        $usersBefore = User::query()->count();

        $this->actingAs($this->platformAdmin)
            ->post(route('platform.companies.demo.store'), $this->validInput())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('platform.companies.index'));

        $company = Company::query()->where('slug', 'demo-oy')->sole();
        $invitation = Membership::query()->where('company_id', $company->id)->sole();
        $admin = User::query()->where('email', 'anna@example.com')->sole();

        $this->assertTrue($company->is_demo);
        $this->assertSame('Demo Oy', $company->name);
        $this->assertSame($admin->id, $invitation->user_id);
        $this->assertSame(Role::Admin, $invitation->role);
        $this->assertNull($invitation->joined_at);
        $this->assertSame($this->platformAdmin->id, $invitation->invited_by_user_id);
        $this->assertSame('Demo company created. anna@example.com was invited to be its admin.', session(SessionKey::FLASH_DATA)['toast']['message']);

        // Only the first admin's account is new: demo data names nobody else.
        $this->assertSame($usersBefore + 1, User::query()->count());
        Notification::assertSentTo($admin, UserInvitation::class);
        Notification::assertCount(1);
        Mail::assertNothingSent();

        app(CurrentCompany::class)->runAs($company, function () {
            $this->assertSame(150, Supplier::query()->count());
            $this->assertSame(1, Project::query()->count());
            $this->assertGreaterThanOrEqual(40, Package::query()->count());
            $this->assertLessThanOrEqual(80, Package::query()->count());
            $this->assertGreaterThan(0, PackageSupplier::query()->count());
            $this->assertSame(0, StoredNotification::query()->count());
        });
    }

    public function test_the_platform_list_marks_the_demo_company_and_still_shows_none_of_its_data()
    {
        $this->company->update(['name' => 'Zeta']);
        $this->actingAs($this->platformAdmin)->post(route('platform.companies.demo.store'), $this->validInput());
        $supplierName = app(CurrentCompany::class)->runAs(Company::query()->where('slug', 'demo-oy')->sole(), fn () => Supplier::query()->value('name'));

        $this->actingAs($this->platformAdmin)
            ->get(route('platform.companies.index'))
            ->assertOk()
            ->assertDontSee($supplierName)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('companies.data.0.slug', 'demo-oy')
                ->where('companies.data.0.is_demo', true)
                ->where('companies.data.0.pending_invitations_count', 1)
                ->where('companies.data.1.is_demo', false));
    }

    public function test_every_row_belongs_to_the_demo_company_and_no_other_company_gets_any()
    {
        $tables = $this->companyTables();
        $rowsElsewhere = $this->rowsPerTable($tables);

        $invitation = app(CreateDemoCompany::class)->handle('Demo Oy', 'demo-oy', 'Anna Admin', 'anna@example.com');

        // The test's own company, which was open throughout, got nothing.
        $this->assertSame($rowsElsewhere, $this->rowsPerTable($tables));
        $this->assertSame($this->company->id, app(CurrentCompany::class)->id());

        app(CurrentCompany::class)->runAs($invitation->company, function () use ($tables, $invitation) {
            foreach ($tables as $table) {
                $this->assertSame(0, DB::table($table)->where('company_id', '!=', $invitation->company->id)->count(), "{$table} has rows of another company.");
            }

            $this->assertGreaterThan(0, DB::table('suppliers')->count());
        });
    }

    public function test_a_demo_company_is_filled_the_same_way_every_time()
    {
        $first = app(CreateDemoCompany::class)->handle('Demo One', 'demo-one', 'Anna Admin', 'anna@example.com')->company;
        $second = app(CreateDemoCompany::class)->handle('Demo Two', 'demo-two', 'Anna Admin', 'anna@example.com')->company;

        $names = fn (Company $company) => app(CurrentCompany::class)->runAs($company, fn () => Supplier::query()->orderBy('supplier_number')->pluck('name')->all());

        $this->assertSame($names($first), $names($second));
        // The same person may run several demo companies; no second account is made.
        $this->assertSame(1, User::query()->where('email', 'anna@example.com')->count());
    }

    public function test_the_first_admin_may_decline_which_withdraws_the_invitation_and_keeps_the_data()
    {
        $invitation = app(CreateDemoCompany::class)->handle('Demo Oy', 'demo-oy', 'Anna Admin', 'anna@example.com');

        $this->actingAs($invitation->user)
            ->post(route('invitations.decline', ['invitation' => $invitation->id]))
            ->assertRedirect(route('invitations.index'));

        $withdrawn = $invitation->fresh();
        $this->assertNotNull($withdrawn->deactivated_at);
        $this->assertNull($withdrawn->joined_at);
        $this->assertNull($withdrawn->invitation_token);
        $this->assertSame(150, app(CurrentCompany::class)->runAs($invitation->company, fn () => Supplier::query()->count()));
    }

    public function test_a_demo_company_can_invite_only_its_own_first_admin_again()
    {
        $invitation = app(CreateDemoCompany::class)->handle('Demo Oy', 'demo-oy', 'Anna Admin', 'anna@example.com');
        $this->actingAs($invitation->user)->post(route('invitations.decline', ['invitation' => $invitation->id]));
        $this->actingAs($this->platformAdmin);

        // The made-up data names Anna, so another address would find it naming a stranger.
        $this->post(route('platform.companies.first-admin', $invitation->company), ['admin_name' => 'Bob', 'admin_email' => 'bob@example.com'])
            ->assertSessionHasErrors('admin_email');

        $this->post(route('platform.companies.first-admin', $invitation->company), ['admin_name' => 'Anna Admin', 'admin_email' => 'ANNA@example.com'])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($invitation->fresh()->invitation_token);
    }

    public function test_input_is_validated_like_any_new_company()
    {
        $this->actingAs($this->platformAdmin);
        $takenSlug = $this->company->slug;

        $this->post(route('platform.companies.demo.store'), $this->validInput(['name' => '']))->assertSessionHasErrors('name');
        $this->post(route('platform.companies.demo.store'), $this->validInput(['admin_name' => '']))->assertSessionHasErrors('admin_name');
        $this->post(route('platform.companies.demo.store'), $this->validInput(['admin_email' => 'not-an-email']))->assertSessionHasErrors('admin_email');

        foreach (['platform', 'settings', 'demo-ä', $takenSlug] as $slug) {
            $this->post(route('platform.companies.demo.store'), $this->validInput(['slug' => $slug]))->assertSessionHasErrors('slug');
        }

        $this->assertSame(1, Company::count());
        Notification::assertNothingSent();
    }

    public function test_the_answer_is_the_same_whether_or_not_the_email_has_an_account()
    {
        $existing = User::factory()->withoutCompany()->create(['name' => 'Bea Existing', 'email' => 'bea@example.com']);
        $answers = [];

        foreach (['new' => 'nobody@example.com', 'existing' => 'BEA@example.com'] as $kind => $email) {
            $response = $this->actingAs($this->platformAdmin)
                ->post(route('platform.companies.demo.store'), $this->validInput(['slug' => "demo-{$kind}", 'admin_email' => $email]))
                ->assertSessionHasNoErrors();

            $answers[$kind] = [
                'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'),
                'toast' => str_replace(strtolower($email), '{email}', session(SessionKey::FLASH_DATA)['toast']['message']),
            ];
        }

        $this->assertSame($answers['new'], $answers['existing']);
        $this->assertSame('Bea Existing', $existing->fresh()->name);
    }

    public function test_only_platform_admins_may_create_one_and_only_with_two_factor_sign_in()
    {
        foreach ([User::factory()->admin()->withTwoFactor()->create(), User::factory()->withoutCompany()->create()] as $user) {
            $this->actingAs($user)->post(route('platform.companies.demo.store'), $this->validInput())->assertNotFound();
        }

        $this->actingAs(User::factory()->platformAdmin()->create())
            ->post(route('platform.companies.demo.store'), $this->validInput())
            ->assertRedirect(route('security.edit'));

        auth()->logout();
        $this->post(route('platform.companies.demo.store'), $this->validInput())->assertRedirect(route('login'));

        $this->assertSame(1, Company::count());
        Notification::assertNothingSent();
    }

    /**
     * @return list<string>
     */
    private function companyTables(): array
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('column_name', 'company_id')
            ->whereNotIn('table_name', ['memberships', 'company_slugs', 'oauth_clients'])
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();
    }

    /**
     * Rows each table has in the open company.
     *
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function rowsPerTable(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validInput(array $overrides = []): array
    {
        return [
            'name' => 'Demo Oy',
            'slug' => 'demo-oy',
            'admin_name' => 'Anna Admin',
            'admin_email' => 'anna@example.com',
            ...$overrides,
        ];
    }
}
