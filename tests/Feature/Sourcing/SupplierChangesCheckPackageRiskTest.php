<?php

namespace Tests\Feature\Sourcing;

use App\Models\Notification;
use App\Models\User;
use App\Modules\Ppr\Enums\PackageStatus;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\PackageMilestone;
use App\Modules\Ppr\Models\Project;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\RatingCriterion;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * A package's risk follows its chosen supplier, so every supplier save that can change the supplier's risk
 * queues a check of the projects that chose it, and the people of those projects hear about it.
 */
class SupplierChangesCheckPackageRiskTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $manager;

    private User $owner;

    private Supplier $supplier;

    private Project $project;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        // A company buyer outside the project: they look after the supplier, not the packages.
        $this->buyer = User::factory()->buyer()->create();
        $this->manager = User::factory()->create();
        $this->owner = User::factory()->create();
        $this->supplier = Supplier::factory()->active()->create();
        $this->project = Project::factory()
            ->withDefaultMilestones()
            ->withMember($this->manager, ProjectRole::ProjectManager)
            ->withMember($this->owner, ProjectRole::PackageOwner)
            ->create(['risk_amber_days' => 14, 'risk_red_days' => 0]);
        $this->package = $this->packageWithTimeToSpare($this->project);
        PackageSupplier::factory()->for($this->package)->for($this->supplier)->chosen()->create();
    }

    public function test_blocking_the_chosen_supplier_tells_the_project_managers_and_the_package_owner()
    {
        $this->storeLevels();

        $this->actingAs($this->buyer)->put(route('suppliers.update', $this->supplier), [
            'name' => $this->supplier->name, 'status' => 'blocked', 'country_code' => 'DE',
        ])->assertSessionHasNoErrors();

        $this->assertTold([$this->manager, $this->owner], 'none', 'amber');
        $notification = Notification::query()->where('type', 'risk_changed')->firstOrFail();
        $this->assertSame(['supplier_at_risk'], $notification->data['params']['reasons']);
    }

    public function test_archiving_and_restoring_the_chosen_supplier_tell_them_both_ways()
    {
        $this->storeLevels();

        $this->actingAs($this->buyer)->post(route('suppliers.archive', $this->supplier))->assertSessionHasNoErrors();
        $this->assertTold([$this->manager, $this->owner], 'none', 'amber');

        Notification::query()->delete();
        $this->actingAs($this->buyer)->post(route('suppliers.restore', $this->supplier))->assertSessionHasNoErrors();
        $this->assertTold([$this->manager, $this->owner], 'amber', 'none');
    }

    public function test_a_high_risk_override_tells_everyone_but_the_project_manager_who_set_it()
    {
        $this->manager->memberships()->update(['role' => 'buyer']);
        $this->storeLevels();

        $this->actingAs($this->manager)->put(route('suppliers.risk.update', $this->supplier), ['level' => 'high', 'reason' => 'Going bankrupt'])
            ->assertSessionHasNoErrors();

        $this->assertTold([$this->owner], 'none', 'amber');
    }

    public function test_a_supplier_that_is_only_asked_for_a_quote_changes_no_package()
    {
        $otherProject = Project::factory()->withMember($this->manager, ProjectRole::ProjectManager)->create();
        PackageSupplier::factory()->for(Package::factory()->for($otherProject)->create())->for($this->supplier)->create(['status' => QuoteStatus::OfferReceived]);
        Queue::fake();

        $this->actingAs($this->buyer)->post(route('suppliers.archive', $this->supplier))->assertSessionHasNoErrors();

        Queue::assertPushed(CheckProjectRisks::class, 1);
        Queue::assertPushed(CheckProjectRisks::class, fn (CheckProjectRisks $check) => $check->projectId === $this->project->id
            && $check->changedById === $this->buyer->id
            && $check->savedPackageIds === [$this->package->id]);
    }

    public function test_each_project_that_chose_the_supplier_is_checked_once()
    {
        $otherProject = Project::factory()->create();
        $otherPackages = Package::factory()->for($otherProject)->count(2)->create();
        $otherPackages->each(fn (Package $package) => PackageSupplier::factory()->for($package)->for($this->supplier)->chosen()->create());
        PackageSupplier::factory()->for(Package::factory()->for(Project::factory()->create())->create())->chosen()->create();
        Queue::fake();
        $this->actingAs($this->buyer);

        $this->supplier->forceFill(['status' => SupplierStatus::Blocked])->save();

        $this->assertEqualsCanonicalizing(
            [[$this->project->id, [$this->package->id]], [$otherProject->id, $otherPackages->pluck('id')->sort()->values()->all()]],
            Queue::pushed(CheckProjectRisks::class)->map(fn (CheckProjectRisks $check) => [$check->projectId, $check->savedPackageIds])->all(),
        );
    }

    public function test_only_changes_that_can_change_the_suppliers_risk_queue_checks()
    {
        Queue::fake();
        $this->actingAs($this->buyer);

        $this->supplier->update(['name' => 'Renamed', 'city' => 'Turku', 'notes' => 'New notes']);
        $certificate = Certificate::factory()->for($this->supplier)->create();
        $certificate->update(['reference' => 'NEW-REF']);
        CertificateType::factory()->create();
        $type = CertificateType::factory()->create();
        $type->update(['name' => 'Renamed type']);

        Queue::assertPushed(CheckProjectRisks::class, 1); // the certificate added
    }

    public function test_supplier_module_saves_that_can_change_a_suppliers_risk_queue_a_check()
    {
        $certificate = Certificate::factory()->for($this->supplier)->create();
        $type = CertificateType::factory()->create();
        $criterion = RatingCriterion::factory()->create(['weight' => 10]);
        $this->actingAs($this->buyer);

        $saves = [
            'score' => fn () => $this->supplier->forceFill(['latest_rating_score' => 1.5])->save(),
            'override' => fn () => $this->supplier->forceFill(['risk_override' => 'low'])->save(),
            'certificate added' => fn () => Certificate::factory()->for($this->supplier)->create(),
            'certificate expiry' => fn () => $certificate->update(['expires_on' => today()->addDays(3)]),
            'certificate removed' => fn () => $certificate->delete(),
            'rating' => fn () => $this->post(route('suppliers.ratings.store', $this->supplier), [
                'rated_on' => today()->toDateString(), 'scores' => RatingCriterion::query()->inUse()->pluck('id')->mapWithKeys(fn (int $id) => [$id => 1])->all(),
            ])->assertSessionHasNoErrors(),
            'weights' => fn () => $criterion->forceFill(['weight' => 20])->save(),
            'required certificate type' => fn () => $type->update(['is_required' => true]),
            'new required certificate type' => fn () => CertificateType::factory()->required()->create(),
            'required certificate type removed' => fn () => $type->delete(),
        ];

        foreach ($saves as $save => $work) {
            Queue::fake();
            $work();

            Queue::assertPushed(CheckProjectRisks::class, fn (CheckProjectRisks $check) => $check->projectId === $this->project->id
                && $check->changedById === $this->buyer->id
                && $check->savedPackageIds === [$this->package->id]);
            $this->assertCount(1, Queue::pushed(CheckProjectRisks::class), "One check for: {$save}");
        }
    }

    public function test_finding_the_projects_takes_one_query_however_many_there_are()
    {
        $this->actingAs($this->buyer);
        Queue::fake();
        $queries = $this->countQueries(fn () => $this->supplier->forceFill(['status' => SupplierStatus::Blocked])->save());

        foreach (range(1, 5) as $ignored) {
            PackageSupplier::factory()->for(Package::factory()->for(Project::factory()->create())->create())->for($this->supplier)->chosen()->create();
        }

        $this->assertSame($queries, $this->countQueries(fn () => $this->supplier->forceFill(['status' => SupplierStatus::Active])->save()));
        $this->assertCount(6, Queue::pushed(CheckProjectRisks::class)->filter(fn (CheckProjectRisks $check) => $check->savedPackageIds !== null)->unique('projectId'));
    }

    /**
     * Owned and bought, with months to spare, so nothing but the supplier puts it at risk.
     */
    private function packageWithTimeToSpare(Project $project): Package
    {
        $package = Package::factory()->for($project)->create();
        $package->forceFill(['owner_id' => $this->owner->id, 'buyer_id' => $this->manager->id, 'status' => PackageStatus::Ordered])->save();
        $milestone = $project->milestones()->where('name', 'Required on site')->firstOrFail();
        PackageMilestone::factory()->for($package)->for($milestone)->create(['planned_date' => '2099-06-01', 'forecast_date' => '2099-01-01']);

        return $package;
    }

    /**
     * The first check only stores the levels, so the test's change is the one people hear about.
     */
    private function storeLevels(): void
    {
        app()->call([new CheckProjectRisks($this->project->id), 'handle']);
        $this->assertSame('none', $this->package->fresh()?->notified_risk_level);
    }

    /**
     * @param  list<User>  $people
     */
    private function assertTold(array $people, string $from, string $to): void
    {
        $notifications = Notification::query()->where('type', 'risk_changed')->get();

        $this->assertEqualsCanonicalizing(array_map(fn (User $person) => $person->id, $people), $notifications->pluck('notifiable_id')->all());

        foreach ($notifications as $notification) {
            $this->assertSame($this->package->id, $notification->data['package_id']);
            $this->assertSame($from, $notification->data['params']['from']);
            $this->assertSame($to, $notification->data['params']['to']);
        }
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
