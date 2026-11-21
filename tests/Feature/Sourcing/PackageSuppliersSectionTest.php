<?php

namespace Tests\Feature\Sourcing;

use App\Companies\CurrentCompany;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Risk\ProjectRisks;
use App\Modules\Ppr\Risk\RiskReason;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Enums\RiskLevel;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The suppliers section on a package's page.
 */
class PackageSuppliersSectionTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $viewer;

    private Project $project;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->manager = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->project = Project::factory()
            ->withMember($this->manager, ProjectRole::ProjectManager)
            ->withMember($this->viewer, ProjectRole::Viewer)
            ->create();
        $this->package = Package::factory()->for($this->project)->create();
    }

    public function test_the_section_lists_the_candidates_with_the_chosen_one_first()
    {
        $chosen = Supplier::factory()->active()->create(['name' => 'Zeta Pumps', 'supplier_number' => 'S-9']);
        $asked = Supplier::factory()->create(['name' => 'Alpha Valves', 'status' => SupplierStatus::Blocked]);
        PackageSupplier::factory()->for($this->package)->for($asked)->create(['note' => 'Asked by phone']);
        PackageSupplier::factory()->for($this->package)->for($chosen)->chosen()->create();

        $this->actingAs($this->viewer)->get($this->packageUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sections', [['key' => 'sourcing_suppliers', 'component' => 'sourcing/PackageSuppliers']])
                ->has('section_sourcing_suppliers.candidates', 2)
                ->where('section_sourcing_suppliers.candidates.0.supplier', [
                    'id' => $chosen->id, 'name' => 'Zeta Pumps', 'number' => 'S-9', 'status' => 'active',
                    'statusLabel' => 'Active', 'riskLevel' => 'low', 'isArchived' => false,
                ])
                ->where('section_sourcing_suppliers.candidates.0.status', 'chosen')
                ->where('section_sourcing_suppliers.candidates.0.statusLabel', 'Chosen')
                ->where('section_sourcing_suppliers.candidates.0.putsPackageAtRisk', false)
                ->where('section_sourcing_suppliers.candidates.1.supplier.name', 'Alpha Valves')
                ->where('section_sourcing_suppliers.candidates.1.supplier.riskLevel', 'high')
                ->where('section_sourcing_suppliers.candidates.1.note', 'Asked by phone')
                // Not chosen, so the blocked supplier puts nothing at risk.
                ->where('section_sourcing_suppliers.candidates.1.putsPackageAtRisk', false)
                ->has('section_sourcing_suppliers.statuses', 4)
                ->where('section_sourcing_suppliers.canManage', false)
                ->where('section_sourcing_suppliers.search', null));
    }

    public function test_the_warning_says_the_same_as_the_packages_risk()
    {
        CertificateType::factory()->required()->create();
        $suppliers = [
            'blocked' => Supplier::factory()->create(['status' => SupplierStatus::Blocked]),
            'blocked but overridden low' => Supplier::factory()->create(['status' => SupplierStatus::Blocked, 'risk_override' => RiskLevel::Low]),
            'overridden high' => $this->certified(Supplier::factory()->active()->create(['risk_override' => RiskLevel::High])),
            'archived' => $this->certified(Supplier::factory()->active()->archived()->create()),
            'poorly rated' => $this->certified(Supplier::factory()->active()->create(['latest_rating_score' => 1.5])),
            'uncertified' => Supplier::factory()->active()->create(),
            'medium' => $this->certified(Supplier::factory()->active()->create(['latest_rating_score' => 3.0])),
            'good' => $this->certified(Supplier::factory()->active()->create(['latest_rating_score' => 4.5])),
            'prospect' => Supplier::factory()->create(['status' => SupplierStatus::Prospect]),
        ];
        $packages = [];

        foreach ($suppliers as $kind => $supplier) {
            $packages[$kind] = Package::factory()->for($this->project)->create();
            PackageSupplier::factory()->for($packages[$kind])->for($supplier)->chosen()->create();
        }

        $risks = ProjectRisks::for($this->project);

        foreach ($packages as $kind => $package) {
            $warns = $this->actingAs($this->viewer)->get($this->packageUrl($package))->inertiaProps('section_sourcing_suppliers.candidates.0.putsPackageAtRisk');

            $this->assertSame($risks[$package->id]->has(RiskReason::SupplierAtRisk), $warns, "The warning and the package risk disagree for a {$kind} supplier.");
        }

        $this->assertSame(['blocked', 'blocked but overridden low', 'overridden high', 'archived', 'poorly rated', 'uncertified'], array_keys(array_filter($packages, fn (Package $package) => $risks[$package->id]->has(RiskReason::SupplierAtRisk))));
    }

    public function test_managers_search_the_suppliers_that_may_be_added()
    {
        $match = Supplier::factory()->active()->create(['name' => 'Pump Works', 'supplier_number' => 'P-100']);
        $byNumber = Supplier::factory()->create(['name' => 'Other name', 'supplier_number' => 'PUMP-7', 'status' => SupplierStatus::Prospect]);
        Supplier::factory()->active()->archived()->create(['name' => 'Archived Pump']);
        Supplier::factory()->create(['name' => 'Blocked Pump', 'status' => SupplierStatus::Blocked]);
        $alreadyOn = Supplier::factory()->active()->create(['name' => 'Pump Already']);
        PackageSupplier::factory()->for($this->package)->for($alreadyOn)->create();
        Supplier::factory()->active()->create(['name' => 'Valve Only']);
        app(CurrentCompany::class)->runAs(Company::factory()->create(), fn () => Supplier::factory()->active()->create(['name' => 'Their Pump']));

        $this->actingAs($this->manager)->getSection('  pump ')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('section_sourcing_suppliers.search', ['term' => 'pump', 'matches' => [
                    ['id' => $byNumber->id, 'name' => 'Other name', 'number' => 'PUMP-7'],
                    ['id' => $match->id, 'name' => 'Pump Works', 'number' => 'P-100'],
                ]]));
    }

    public function test_a_search_returns_at_most_twenty_matches()
    {
        Supplier::factory()->active()->count(25)->sequence(fn ($sequence) => ['name' => sprintf('Crane %02d', $sequence->index)])->create();

        $this->actingAs($this->manager)->getSection('crane')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('section_sourcing_suppliers.search.matches', 20)
                ->where('section_sourcing_suppliers.search.matches.0.name', 'Crane 00'));
    }

    public function test_people_who_may_not_manage_the_suppliers_get_no_search()
    {
        Supplier::factory()->active()->create(['name' => 'Pump Works']);

        $this->actingAs($this->viewer)->getSection('pump')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('section_sourcing_suppliers.search', null));
    }

    public function test_without_a_search_term_no_supplier_is_sent()
    {
        Supplier::factory()->active()->create(['name' => 'Pump Works']);

        $this->actingAs($this->manager)->getSection('   ')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('section_sourcing_suppliers.search', null));
    }

    public function test_the_section_takes_the_same_queries_however_many_candidates_there_are()
    {
        CertificateType::factory()->required()->create();
        $this->addCandidate();
        // Opening the company the first time records it as the person's last one; that happens only once.
        $this->actingAs($this->manager)->getSection('pump');
        $forOne = $this->countQueries(fn () => $this->getSection('pump')->assertOk());

        foreach (range(1, 9) as $ignored) {
            $this->addCandidate();
        }

        $this->assertSame($forOne, $this->countQueries(fn () => $this->getSection('pump')->assertOk()));
        $this->assertLessThanOrEqual(13, $forOne, 'A search reads only what the section needs.');
        $this->assertSame(10, count($this->getSection('pump')->inertiaProps('section_sourcing_suppliers.candidates')));
    }

    private function addCandidate(): void
    {
        $supplier = Supplier::factory()->active()->create(['latest_rating_score' => 3.0]);
        Certificate::factory()->for($supplier)->create();
        PackageSupplier::factory()->for($this->package)->for($supplier)->create();
    }

    private function certified(Supplier $supplier): Supplier
    {
        Certificate::factory()->for($supplier)->create(['certificate_type_id' => CertificateType::query()->where('is_required', true)->value('id'), 'expires_on' => null]);

        return $supplier;
    }

    /**
     * The section alone, the way the search box asks for it.
     *
     * @return TestResponse<Response>
     */
    private function getSection(string $search): TestResponse
    {
        return $this->get($this->packageUrl().'?'.http_build_query(['supplier_search' => $search]), [
            Header::PARTIAL_COMPONENT => 'ppr/projects/packages/Show',
            Header::PARTIAL_ONLY => 'section_sourcing_suppliers',
        ]);
    }

    private function packageUrl(?Package $package = null): string
    {
        return route('projects.packages.show', [$this->project, $package ?? $this->package]);
    }

    private function countQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
