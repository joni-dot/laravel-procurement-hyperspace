<?php

namespace Tests\Feature\Sourcing;

use App\Models\User;
use App\Modules\Ppr\Actions\Packages\CreatePackage;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Sourcing\Sections\SupplierPackages;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The project packages section on a supplier's page: only packages in projects the person can see.
 */
class SupplierPackagesSectionTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Project $visible;

    private Project $hidden;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::factory()->active()->create();
        $this->member = User::factory()->create();
        $this->visible = Project::factory()->withMember($this->member, ProjectRole::Viewer)->create(['name' => 'Zeta Plant', 'code' => 'ZP']);
        $this->hidden = Project::factory()->create(['name' => 'Beta Plant', 'code' => 'BP']);
    }

    public function test_the_section_lists_the_packages_in_projects_the_person_can_see()
    {
        $first = $this->addPackage($this->visible, 'First pump', chosen: true);
        $second = $this->addPackage($this->visible, 'Second pump');
        $this->addPackage($this->hidden, 'Secret pump');
        PackageSupplier::factory()->for(Package::factory()->for($this->visible)->create())->create();

        $this->actingAs($this->member)->get($this->supplierUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sections', [['key' => 'sourcing_packages', 'component' => 'sourcing/SupplierPackages']])
                ->where('section_sourcing_packages.total', 2)
                ->where('section_sourcing_packages.packages', [
                    [
                        'projectId' => $this->visible->id, 'projectName' => 'Zeta Plant', 'projectCode' => 'ZP',
                        'packageId' => $first->id, 'number' => $first->number, 'title' => 'First pump', 'packageStatus' => $first->status->value,
                        'status' => 'chosen', 'statusLabel' => 'Chosen',
                    ],
                    [
                        'projectId' => $this->visible->id, 'projectName' => 'Zeta Plant', 'projectCode' => 'ZP',
                        'packageId' => $second->id, 'number' => $second->number, 'title' => 'Second pump', 'packageStatus' => $second->status->value,
                        'status' => 'asked', 'statusLabel' => 'Asked for a quote',
                    ],
                ]));
    }

    public function test_admins_see_the_packages_of_every_project()
    {
        $this->addPackage($this->visible, 'First pump');
        $this->addPackage($this->hidden, 'Secret pump');

        $this->actingAs(User::factory()->admin()->create())->get($this->supplierUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('section_sourcing_packages.total', 2)
                // By project name, then number.
                ->where('section_sourcing_packages.packages.0.projectName', 'Beta Plant')
                ->where('section_sourcing_packages.packages.1.projectName', 'Zeta Plant'));
    }

    public function test_someone_in_no_project_sees_none_of_its_packages()
    {
        $this->addPackage($this->visible, 'First pump');
        $this->addPackage($this->hidden, 'Secret pump');

        foreach ([User::factory()->create(), User::factory()->buyer()->create()] as $outsider) {
            $this->actingAs($outsider)->get($this->supplierUrl())
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('section_sourcing_packages', ['packages' => [], 'total' => 0]));
        }
    }

    public function test_a_long_list_is_cut_with_the_total_kept()
    {
        $packages = Package::factory()->for($this->visible)->count(3)->create();
        $packages->each(fn (Package $package) => PackageSupplier::factory()->for($package)->for($this->supplier)->create());
        $section = app(SupplierPackages::class);

        $this->assertCount(3, $section->for($this->member, $this->supplier)['packages']);

        foreach (range(1, SupplierPackages::MOST_SHOWN) as $ignored) {
            PackageSupplier::factory()->for(Package::factory()->for($this->visible)->create())->for($this->supplier)->create();
        }

        $data = $section->for($this->member, $this->supplier);
        $this->assertCount(SupplierPackages::MOST_SHOWN, $data['packages']);
        $this->assertSame(SupplierPackages::MOST_SHOWN + 3, $data['total']);
    }

    public function test_the_section_takes_one_query_however_many_packages_there_are()
    {
        $this->addPackage($this->visible, 'First pump');
        $this->actingAs($this->member)->getSection();
        $forOne = $this->countQueries(fn () => $this->getSection()->assertOk());

        foreach (range(1, 10) as $number) {
            $this->addPackage($this->visible, "Pump {$number}");
        }

        $this->assertSame($forOne, $this->countQueries(fn () => $this->getSection()->assertOk()));
        $this->assertSame(1, $this->countQueries(fn () => app(SupplierPackages::class)->for($this->member, $this->supplier)));
    }

    private function addPackage(Project $project, string $title, bool $chosen = false): Package
    {
        $package = app(CreatePackage::class)->handle($project, $title, null);
        PackageSupplier::factory()->for($package)->for($this->supplier)->create(['status' => $chosen ? 'chosen' : 'asked']);

        return $package;
    }

    private function supplierUrl(): string
    {
        return route('suppliers.show', $this->supplier);
    }

    /**
     * @return TestResponse<Response>
     */
    private function getSection(): TestResponse
    {
        return $this->get($this->supplierUrl(), [
            Header::PARTIAL_COMPONENT => 'srm/suppliers/Show',
            Header::PARTIAL_ONLY => 'section_sourcing_packages',
        ]);
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
