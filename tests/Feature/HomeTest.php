<?php

namespace Tests\Feature;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Ppr\Dashboard\HomeSections;
use App\Modules\Ppr\Enums\PackageStatus;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\PackageMilestone;
use App\Modules\Ppr\Models\Project;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Models\SupplierRating;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Where people land, /{company}: the Home page, with sections from both modules, each only for the
 * people it is useful for. Project sections for project members and admins, supplier sections for everyone.
 */
class HomeTest extends TestCase
{
    use RefreshDatabase;

    private const array EVERY_SECTION = ['ppr_next_actions', 'ppr_projects', 'srm_attention', 'srm_upcoming'];

    private const array SUPPLIER_SECTIONS = ['srm_attention', 'srm_upcoming'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2027-03-10 12:00:00');
    }

    public function test_home_opens_the_persons_company()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('company.home'));
    }

    public function test_guests_end_up_on_the_login_page()
    {
        $this->get(route('company.home'))->assertRedirect(route('login'));
        $this->followingRedirects()
            ->get(route('home'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login'));
    }

    public function test_signing_in_lands_on_the_home_page()
    {
        $user = User::factory()->create();

        $this->followingRedirects()
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->where('company.slug', $this->company->slug));
    }

    public function test_the_company_switcher_opens_the_other_companys_home_page()
    {
        $user = User::factory()->create();
        $other = Company::factory()->create();
        Membership::factory()->create(['company_id' => $other->id, 'user_id' => $user->id, 'role' => Role::Viewer]);

        $this->actingAs($user)
            ->get("/{$other->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->where('company.slug', $other->slug));
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('people')]
    public function test_each_person_gets_the_sections_useful_to_them(Role $role, bool $inAProject, array $expected)
    {
        $factory = match ($role) {
            Role::Admin => User::factory()->admin(),
            Role::Buyer => User::factory()->buyer(),
            Role::Viewer => User::factory(),
        };
        $user = $factory->create();

        if ($inAProject) {
            Project::factory()->withMember($user, ProjectRole::Viewer)->create();
        }

        $this->assertSame($expected, $this->sectionKeys($user));
    }

    /**
     * @return array<string, array{Role, bool, list<string>}>
     */
    public static function people(): array
    {
        return [
            'admin in a project' => [Role::Admin, true, self::EVERY_SECTION],
            'admin in no project' => [Role::Admin, false, ['ppr_projects', ...self::SUPPLIER_SECTIONS]],
            'buyer in a project' => [Role::Buyer, true, self::EVERY_SECTION],
            'buyer in no project' => [Role::Buyer, false, self::SUPPLIER_SECTIONS],
            'viewer in a project' => [Role::Viewer, true, self::EVERY_SECTION],
            'viewer in no project' => [Role::Viewer, false, self::SUPPLIER_SECTIONS],
        ];
    }

    public function test_belonging_to_a_project_of_another_company_does_not_count()
    {
        $viewer = User::factory()->create();
        $other = Company::factory()->create();
        Membership::factory()->create(['company_id' => $other->id, 'user_id' => $viewer->id, 'role' => Role::Viewer]);
        app(CurrentCompany::class)->runAs($other, fn () => Project::factory()->withMember($viewer, ProjectRole::Viewer)->create());

        $this->assertSame(self::SUPPLIER_SECTIONS, $this->sectionKeys($viewer));
    }

    public function test_sections_arrive_after_the_page_and_hidden_ones_never_arrive()
    {
        $buyer = User::factory()->buyer()->create();
        Project::factory()->create(['name' => 'Someone else’s project']);

        $response = $this->actingAs($buyer)->get(route('company.home'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Home')
            ->where('sections', [
                ['key' => 'srm_attention', 'component' => 'srm/SupplierAttention'],
                ['key' => 'srm_upcoming', 'component' => 'srm/UpcomingDates'],
            ])
            ->missing('section_srm_attention')
            ->missing('section_srm_upcoming')
            ->missing('section_ppr_next_actions')
            ->missing('section_ppr_projects')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('section_srm_attention')
                ->has('section_srm_upcoming')
                ->missing('section_ppr_next_actions')
                ->missing('section_ppr_projects')));
        $this->assertSame(['sections' => ['section_srm_attention', 'section_srm_upcoming']], $response->viewData('page')['deferredProps']);

        // Asking for a hidden section by name gets nothing either.
        $this->loadSections($buyer, ['section_ppr_next_actions', 'section_ppr_projects'])
            ->assertDontSee('Someone else’s project')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('section_ppr_next_actions')
                ->missing('section_ppr_projects'));
    }

    public function test_viewers_never_receive_contract_data()
    {
        Contract::factory()->endsInDays(5)->create(['title' => 'Secret agreement']);
        $viewer = User::factory()->create();

        $this->loadSections($viewer, ['section_srm_attention', 'section_srm_upcoming'])
            ->assertDontSee('Secret agreement')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('section_srm_attention.contracts_action_soon', null)
                ->where('section_srm_upcoming', []));

        $this->loadSections(User::factory()->buyer()->create(), ['section_srm_attention', 'section_srm_upcoming'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('section_srm_attention.contracts_action_soon', 1)
                ->where('section_srm_upcoming.0.title', 'Secret agreement'));
    }

    public function test_the_project_sections_show_the_same_as_the_project_dashboard()
    {
        $person = User::factory()->create(['notification_days' => 7]);
        $owner = User::factory()->create();
        $bridge = Project::factory()->withDefaultMilestones()->withMember($person, ProjectRole::PackageOwner)->withMember($owner, ProjectRole::PackageOwner)
            ->create(['name' => 'Bridge', 'code' => 'BR1']);
        $airport = Project::factory()->withMember($person, ProjectRole::Viewer)->create(['name' => 'Airport', 'code' => 'AP2']);
        Project::factory()->withMember($owner, ProjectRole::ProjectManager)->create(['name' => 'Hidden', 'code' => 'HI3']);
        $late = $this->addPackage($bridge, owner: $person, title: 'Late valve', plannedOrder: '2027-03-05');
        $this->addPackage($bridge, owner: $owner, title: 'Not mine', plannedOrder: '2027-03-05');

        $dashboardAttention = $this->actingAs($person)->get(route('projects.dashboard'), [
            Header::PARTIAL_COMPONENT => 'ppr/Dashboard',
            Header::PARTIAL_ONLY => 'attention',
        ])->viewData('page')['props']['attention'];
        $dashboardAttention = json_decode(json_encode($dashboardAttention), true);

        $sections = $this->loadSections($person, ['section_ppr_next_actions', 'section_ppr_projects'])->viewData('page')['props'];
        $sections = json_decode(json_encode($sections), true);

        $this->assertSame([
            'actions' => $dashboardAttention['actions'],
            'actionsTotal' => 1,
            'notificationDays' => 7,
        ], $sections['section_ppr_next_actions']);
        $this->assertSame($late->id, $sections['section_ppr_next_actions']['actions'][0]['packageId']);

        $this->assertSame(['Airport', 'Bridge'], array_column($sections['section_ppr_projects']['projects'], 'name'));
        $this->assertSame([$airport->id, $bridge->id], array_column($sections['section_ppr_projects']['projects'], 'id'));
        $this->assertSame(['Viewer', 'Package owner'], array_column($sections['section_ppr_projects']['projects'], 'role'));
        $this->assertSame(array_column($dashboardAttention['tiles'], 'tiles'), array_column($sections['section_ppr_projects']['projects'], 'tiles'));
        $this->assertSame(2, $sections['section_ppr_projects']['projects'][1]['tiles']['red']);
        $this->assertFalse($sections['section_ppr_projects']['canSeeAllProjects']);
    }

    public function test_an_admin_in_no_project_gets_an_empty_project_list_to_go_from()
    {
        $this->loadSections(User::factory()->admin()->create(), ['section_ppr_projects'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('section_ppr_projects', ['projects' => [], 'canSeeAllProjects' => true]));
    }

    public function test_only_the_most_urgent_actions_come_with_the_total()
    {
        $person = User::factory()->create(['notification_days' => 7]);
        $project = Project::factory()->withDefaultMilestones()->withMember($person, ProjectRole::PackageOwner)->create();

        foreach (range(1, HomeSections::ACTIONS_SHOWN + 2) as $number) {
            $this->addPackage($project, owner: $person, plannedOrder: '2027-03-01');
        }

        $this->loadSections($person, ['section_ppr_next_actions'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('section_ppr_next_actions.actions', HomeSections::ACTIONS_SHOWN)
                ->where('section_ppr_next_actions.actionsTotal', HomeSections::ACTIONS_SHOWN + 2));
    }

    public function test_each_supplier_tile_count_matches_the_supplier_list_it_links_to()
    {
        $required = CertificateType::factory()->required()->create();

        $missingCertificate = Supplier::factory()->active()->create();
        SupplierRating::factory()->create(['supplier_id' => $missingCertificate->id, 'rated_on' => today()]);

        $expiring = Supplier::factory()->active()->create();
        Certificate::factory()->for($expiring)->for($required, 'type')->expiresInDays(10)->create();

        $contractSupplier = Supplier::factory()->create(['status' => SupplierStatus::Prospect]);
        Contract::factory()->for($contractSupplier)->endsInDays(10)->create();
        Contract::factory()->for($contractSupplier)->endsInDays(20)->create();

        // Archived suppliers never count, whatever needs attention there.
        $archived = Supplier::factory()->active()->archived()->create(['status' => SupplierStatus::Blocked]);
        Contract::factory()->for($archived)->endsInDays(10)->create();
        Certificate::factory()->for($archived)->for($required, 'type')->expiresInDays(10)->create();

        // The same filters as resources/js/components/srm/dashboard/AttentionTiles.vue.
        $links = [
            'contracts_action_soon' => ['contracts_action_soon' => 1],
            'missing_certificates' => ['missing_certificates' => 1],
            'certificates_expiring' => ['status' => 'active', 'certificates_expiring' => 1],
            'high_risk' => ['status' => 'active', 'risk' => 'high'],
            'rating_overdue' => ['rating_overdue' => 1],
        ];

        $buyer = User::factory()->buyer()->create();
        $attention = $this->loadSections($buyer, ['section_srm_attention'])->viewData('page')['props']['section_srm_attention'];
        $dashboard = $this->actingAs($buyer)->get(route('dashboard'))->viewData('page')['props']['attention'];

        $this->assertSame($dashboard, $attention);
        $this->assertSame(1, $attention['contracts_action_soon']);
        $this->assertSame(1, $attention['certificates_expiring']);
        $this->assertSame(1, $attention['high_risk']);

        foreach ($links as $key => $query) {
            $this->actingAs($buyer)
                ->get(route('suppliers.index', $query))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('suppliers.total', $attention[$key]));
        }
    }

    public function test_the_project_sections_take_a_fixed_number_of_queries_per_project_whatever_its_packages()
    {
        $person = User::factory()->create(['notification_days' => 7]);
        $first = Project::factory()->withDefaultMilestones()->withMember($person, ProjectRole::PackageOwner)->create();
        $this->addPackage($first, owner: $person, plannedOrder: '2027-03-08');
        // The first visit to a company also records it as the person's last used one; later visits don't.
        $this->countHomeQueries($person);
        $oneProject = $this->countHomeQueries($person);

        foreach (range(1, 10) as $number) {
            $this->addPackage($first, owner: $person, plannedOrder: '2027-03-08');
        }
        $this->assertSame($oneProject, $this->countHomeQueries($person));

        $dashboardWithOne = $this->countDashboardQueries($person);
        $second = Project::factory()->withDefaultMilestones()->withMember($person, ProjectRole::PackageOwner)->create();
        $this->addPackage($second, owner: $person, plannedOrder: '2027-03-08');
        $perProject = $this->countHomeQueries($person) - $oneProject;

        // Both project sections share one reading of each project, so a project costs the home page what it
        // costs the project dashboard.
        $this->assertGreaterThan(0, $perProject);
        $this->assertSame($perProject, $this->countDashboardQueries($person) - $dashboardWithOne);
    }

    public function test_the_supplier_sections_take_the_same_queries_however_many_suppliers_there_are()
    {
        $buyer = User::factory()->buyer()->create();
        $required = CertificateType::factory()->required()->create();
        $this->addSupplierNeedingAttention($required);
        $this->countHomeQueries($buyer);
        $few = $this->countHomeQueries($buyer);

        foreach (range(1, 5) as $number) {
            $this->addSupplierNeedingAttention($required);
        }

        $this->assertSame($few, $this->countHomeQueries($buyer));
    }

    private function addSupplierNeedingAttention(CertificateType $required): void
    {
        $supplier = Supplier::factory()->active()->create();
        Certificate::factory()->for($supplier)->for($required, 'type')->expiresInDays(10)->create();
        Contract::factory()->for($supplier)->endsInDays(10)->create();
    }

    private function addPackage(Project $project, ?User $owner = null, string $title = 'Pump', ?string $plannedOrder = null): Package
    {
        $package = Package::factory()->for($project)->create([
            'title' => $title,
            'owner_id' => $owner?->id,
            'status' => PackageStatus::Ordered,
        ]);

        if ($plannedOrder !== null) {
            $milestone = $project->milestones()->where('name', 'Order placed')->sole();
            PackageMilestone::factory()->for($package)->for($milestone)->create(['planned_date' => $plannedOrder]);
        }

        return $package;
    }

    /**
     * @return list<string>
     */
    private function sectionKeys(User $user): array
    {
        return array_column($this->actingAs($user)->get(route('company.home'))->assertOk()->viewData('page')['props']['sections'], 'key');
    }

    /**
     * The request the page makes for its deferred sections.
     *
     * @param  list<string>  $props
     */
    private function loadSections(User $user, array $props): TestResponse
    {
        return $this->actingAs($user)->get(route('company.home'), [
            Header::PARTIAL_COMPONENT => 'Home',
            Header::PARTIAL_ONLY => implode(',', $props),
        ])->assertOk();
    }

    // Both requests: the page, and the one that loads its deferred sections.
    private function countHomeQueries(User $person): int
    {
        return $this->countQueries(fn () => $this->actingAs($person)
            ->get(route('company.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page->loadDeferredProps(fn (AssertableInertia $reload) => $reload->has('section_srm_attention'))));
    }

    private function countDashboardQueries(User $person): int
    {
        return $this->countQueries(fn () => $this->actingAs($person)
            ->get(route('projects.dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->loadDeferredProps(fn (AssertableInertia $reload) => $reload->has('attention'))));
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
