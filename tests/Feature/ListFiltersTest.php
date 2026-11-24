<?php

namespace Tests\Feature;

use App\Auth\StrongSignIn;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Support\Facades\Queue;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The filters of list pages come from the address, where anyone can type anything. Something other
 * than text counts as no filter, and a search matches the characters typed, % and _ included.
 */
class ListFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_supplier_list_treats_a_filter_that_is_not_text_as_no_filter()
    {
        Supplier::factory()->count(2)->create();
        $this->actingAs(User::factory()->create());

        $this->get(route('suppliers.index').'?search[]=a&status[]=&risk[]=high')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('suppliers.data', 2)
                ->where('filters.search', '')
                ->where('filters.status', null)
                ->where('filters.risk', null));
    }

    public function test_the_project_list_treats_a_search_that_is_not_text_as_no_search_and_matches_typed_wildcards_literally()
    {
        $user = User::factory()->admin()->create();
        $members = Project::factory()->withMember($user, ProjectRole::ProjectManager);
        $members->create(['name' => 'Harbour crane', 'code' => 'P1']);
        $members->create(['name' => 'Office 100% renewal', 'code' => 'P2']);
        $this->actingAs($user);

        $this->get(route('projects.index').'?search[]=a')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('projects.data', 2));

        $this->get(route('projects.index', ['search' => '%']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects.data', 1)
                ->where('projects.data.0.name', 'Office 100% renewal'));
    }

    public function test_the_members_list_treats_a_search_that_is_not_text_as_no_search_and_matches_typed_wildcards_literally()
    {
        $admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'ada@example.com']);
        User::factory()->create(['name' => 'Bo Buyer', 'email' => 'bo_buyer@example.com']);
        $this->actingAs($admin);

        $this->get(route('admin.users.index').'?search[]=a')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 2));

        $this->get(route('admin.users.index', ['search' => '_']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Bo Buyer'));
    }

    public function test_the_platform_company_list_treats_a_search_that_is_not_text_as_no_search_and_matches_typed_wildcards_literally()
    {
        $this->company->update(['name' => 'Acme']);
        Company::factory()->create(['name' => 'Bolt 100%']);
        $platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();
        $this->actingAs($platformAdmin)
            ->withSession(['auth.password_confirmed_at' => time(), StrongSignIn::SESSION_KEY => $platformAdmin->id]);

        $this->get(route('platform.companies.index').'?search[]=a')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('companies.data', 2));

        $this->get(route('platform.companies.index', ['search' => '%']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('companies.data', 1)
                ->where('companies.data.0.name', 'Bolt 100%'));
    }

    public function test_the_supplier_search_on_a_package_treats_a_search_that_is_not_text_as_no_search()
    {
        Queue::fake();
        $manager = User::factory()->create();
        $project = Project::factory()->withMember($manager, ProjectRole::ProjectManager)->create();
        $package = Package::factory()->for($project)->create();
        $this->actingAs($manager);

        $this->get(route('projects.packages.show', [$project, $package]).'?supplier_search[]=a', [
            Header::PARTIAL_COMPONENT => 'ppr/projects/packages/Show',
            Header::PARTIAL_ONLY => 'section_sourcing_suppliers',
        ])->assertOk();
    }
}
