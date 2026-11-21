<?php

namespace Tests\Feature\Ppr\Risk;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Ppr\Enums\PackageStatus;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Notification;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\PackageMilestone;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Risk\ProjectRisks;
use App\Modules\Ppr\Risk\RiskLevel;
use App\Modules\Ppr\Risk\RiskReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\RefreshDatabase;
use Tests\Concerns\RunsQueuedJobsLikeAWorker;
use Tests\TestCase;

/**
 * The risk check serves every company, but each check runs inside its project's company: its people,
 * their memberships there and the notifications they get all belong to that company.
 */
class RiskCompanyTest extends TestCase
{
    use RefreshDatabase, RunsQueuedJobsLikeAWorker;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2027-03-10 12:00:00');
        $this->other = Company::factory()->create();
    }

    public function test_the_daily_command_queues_every_project_in_its_own_company_and_skips_suspended_companies()
    {
        $here = Project::factory()->create();
        $there = $this->inCompany($this->other, fn () => Project::factory()->create());
        $suspended = Company::factory()->create();
        $this->inCompany($suspended, fn () => Project::factory()->create());
        $suspended->forceFill(['suspended_at' => now()])->save();
        $this->queueJobsForTheWorker();

        $this->artisan('packages:check-risks')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [['project' => $here->id, 'company' => $this->company->id], ['project' => $there->id, 'company' => $this->other->id]],
            $this->queuedChecks(),
        );
    }

    public function test_each_check_runs_inside_its_projects_company_even_for_someone_in_both()
    {
        $person = $this->personInBothCompanies();
        $here = $this->packageOwnedBy($person, dates: ['Order placed' => '2027-03-12']);
        $there = $this->inCompany($this->other, fn () => $this->packageOwnedBy($person, dates: ['Order placed' => '2027-03-13']));
        $this->queueJobsForTheWorker();

        $this->artisan('packages:check-risks')->assertSuccessful();
        $this->runQueuedJobsLikeAWorker();

        $this->assertNoJobFailed();
        $this->assertSame([[$person->id, $here->id, '2027-03-12']], $this->remindersIn($this->company));
        $this->assertSame([[$person->id, $there->id, '2027-03-13']], $this->remindersIn($this->other));
    }

    public function test_a_failing_check_in_one_company_does_not_stop_the_others()
    {
        Exceptions::fake();
        $person = $this->personInBothCompanies();
        $broken = $this->packageOwnedBy($person, dates: ['Order placed' => '2027-03-12']);
        $there = $this->inCompany($this->other, fn () => $this->packageOwnedBy($person, dates: ['Order placed' => '2027-03-13']));
        $this->breakProject($broken->project_id);
        $this->queueJobsForTheWorker();

        $this->artisan('packages:check-risks')->assertSuccessful();
        $this->runQueuedJobsLikeAWorker();

        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame([], $this->remindersIn($this->company));
        $this->assertSame([[$person->id, $there->id, '2027-03-13']], $this->remindersIn($this->other));
    }

    public function test_a_failure_while_queueing_one_company_does_not_stop_the_others()
    {
        Exceptions::fake();
        $person = $this->personInBothCompanies();
        $broken = $this->packageOwnedBy($person, dates: ['Order placed' => '2027-03-12']);
        $there = $this->inCompany($this->other, fn () => $this->packageOwnedBy($person, dates: ['Order placed' => '2027-03-13']));
        // The sync queue runs each check while the command queues it, so the failure happens inside the command.
        $this->breakProject($broken->project_id);

        $this->artisan('packages:check-risks')->assertFailed();

        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame([[$person->id, $there->id, '2027-03-13']], $this->remindersIn($this->other));
    }

    public function test_a_package_whose_owner_left_this_company_is_amber_until_they_come_back()
    {
        $admin = User::factory()->admin()->create();
        $owner = $this->personInBothCompanies();
        $package = $this->packageOwnedBy($owner);
        $this->checkAfterSave($package);
        $this->assertSame('none', $package->fresh()?->notified_risk_level);
        $membership = $owner->memberships()->where('company_id', $this->company->id)->sole();

        $this->actingAs($admin)->post(route('admin.users.deactivate', $membership))->assertSessionHasNoErrors();

        $this->assertRisk($package, RiskLevel::Amber, [RiskReason::OwnerDeactivated]);
        $this->assertSame('amber', $package->fresh()?->notified_risk_level);
        // Only the membership here ended: the account and the other company are untouched.
        $this->assertFalse($owner->fresh()?->isDeactivated());
        $this->assertTrue($owner->fresh()?->isActiveIn($this->other->id));

        $this->actingAs($admin)->post(route('admin.users.reactivate', $membership))->assertSessionHasNoErrors();

        $this->assertRisk($package, RiskLevel::None, []);
        $this->assertSame('none', $package->fresh()?->notified_risk_level);
    }

    public function test_a_buyer_whose_membership_here_is_deactivated_makes_the_package_amber()
    {
        $buyer = $this->personInBothCompanies();
        $package = $this->packageOwnedBy(User::factory()->create(), buyer: $buyer);

        $buyer->memberships()->where('company_id', $this->company->id)->update(['deactivated_at' => now()]);

        $this->assertRisk($package, RiskLevel::Amber, [RiskReason::BuyerDeactivated]);
    }

    public function test_leaving_another_company_changes_nothing_here()
    {
        $buyer = $this->personInBothCompanies();
        $package = $this->packageOwnedBy(User::factory()->create(), buyer: $buyer);
        $otherAdmin = $this->inCompany($this->other, fn () => User::factory()->admin()->create());
        $membershipThere = $buyer->memberships()->where('company_id', $this->other->id)->sole();
        Queue::fake();

        $this->actingAs($otherAdmin)
            ->post(route('admin.users.deactivate', ['company' => $this->other->slug, 'membership' => $membershipThere->id]))
            ->assertSessionHasNoErrors();
        $this->enterCompany($this->company);

        Queue::assertNotPushed(CheckProjectRisks::class);
        $this->assertNotNull($membershipThere->fresh()?->deactivated_at);
        $this->assertRisk($package, RiskLevel::None, []);
    }

    public function test_a_person_deactivated_here_before_the_reminders_go_out_gets_none()
    {
        $person = $this->personInBothCompanies();
        $this->packageOwnedBy($person, dates: ['Order placed' => '2027-03-12']);
        $this->queueJobsForTheWorker();
        $this->artisan('packages:check-risks')->assertSuccessful();
        // The check runs and queues the reminder; the person leaves the company before it is sent.
        $this->runQueuedJobsLikeAWorker(onlyOne: true);
        $person->memberships()->where('company_id', $this->company->id)->update(['deactivated_at' => now()]);

        $this->runQueuedJobsLikeAWorker();

        $this->assertNoJobFailed();
        $this->assertSame(1, DB::table('sent_reminders')->count());
        $this->assertSame([], $this->remindersIn($this->company));
    }

    public function test_a_persons_reminder_period_is_the_same_in_every_company()
    {
        $person = $this->personInBothCompanies();
        $person->forceFill(['notification_days' => 4])->save();
        $dates = ['Order placed' => '2027-03-13', 'Delivered from supplier' => '2027-03-15'];
        $here = $this->packageOwnedBy($person, dates: $dates);
        $there = $this->inCompany($this->other, fn () => $this->packageOwnedBy($person, dates: $dates));

        $this->artisan('packages:check-risks')->assertSuccessful();

        $this->assertSame([[$person->id, $here->id, '2027-03-13']], $this->remindersIn($this->company));
        $this->assertSame([[$person->id, $there->id, '2027-03-13']], $this->remindersIn($this->other));

        // An account setting: changed once, outside every company, it counts in both.
        app(CurrentCompany::class)->clear();
        $this->actingAs($person)->put(route('notification-settings.update'), ['notification_days' => 6])->assertSessionHasNoErrors();
        $this->enterCompany($this->company);
        $this->artisan('packages:check-risks')->assertSuccessful();

        $this->assertSame([[$person->id, $here->id, '2027-03-13'], [$person->id, $here->id, '2027-03-15']], $this->remindersIn($this->company));
        $this->assertSame([[$person->id, $there->id, '2027-03-13'], [$person->id, $there->id, '2027-03-15']], $this->remindersIn($this->other));
    }

    /**
     * A viewer here and in the other company.
     */
    private function personInBothCompanies(): User
    {
        $person = User::factory()->create();
        Membership::factory()->create(['company_id' => $this->other->id, 'user_id' => $person->id, 'role' => Role::Viewer]);

        return $person;
    }

    /**
     * An ordered package that is on time (no risk) unless dates say otherwise.
     *
     * @param  array<string, string>  $dates  planned dates per milestone name
     */
    private function packageOwnedBy(User $owner, ?User $buyer = null, array $dates = []): Package
    {
        // Not reminded of coming dates, so the reminders a test sees are the owner's.
        $buyer ??= User::factory()->create(['notification_days' => 0]);
        $project = Project::factory()
            ->withDefaultMilestones()
            ->withMember($owner, ProjectRole::PackageOwner)
            ->withMember($buyer, ProjectRole::Buyer)
            ->create(['risk_amber_days' => 14, 'risk_red_days' => 0]);
        $package = Package::factory()->for($project)->create([
            'owner_id' => $owner->id,
            'buyer_id' => $buyer->id,
            'status' => PackageStatus::Ordered,
        ]);

        $this->fillIn($package, 'Required on site', ['planned_date' => '2027-12-01', 'forecast_date' => '2027-06-01']);
        foreach ($dates as $milestoneName => $date) {
            $this->fillIn($package, $milestoneName, ['planned_date' => $date]);
        }

        return $package;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fillIn(Package $package, string $milestoneName, array $values): void
    {
        $milestone = $package->project->milestones()->where('name', $milestoneName)->firstOrFail();

        PackageMilestone::factory()->for($package)->for($milestone)->create($values);
    }

    private function breakProject(int $projectId): void
    {
        Project::retrieved(fn (Project $project) => throw_if($project->id === $projectId, new RuntimeException('Broken data')));
    }

    private function checkAfterSave(Package $package): void
    {
        CheckProjectRisks::dispatchSync($package->project_id);
    }

    /**
     * @param  list<RiskReason>  $reasons
     */
    private function assertRisk(Package $package, RiskLevel $level, array $reasons): void
    {
        $risk = ProjectRisks::for($package->project)[$package->id];

        $this->assertSame($level, $risk->level);
        $this->assertSame($reasons, $risk->reasons);
    }

    /**
     * @return list<array{project: int, company: int}>
     */
    private function queuedChecks(): array
    {
        return DB::table('jobs')->pluck('payload')->map(function (string $payload): array {
            $payload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            $check = unserialize($payload['data']['command']);
            $this->assertInstanceOf(CheckProjectRisks::class, $check);

            return ['project' => $check->projectId, 'company' => $payload['company_id']];
        })->all();
    }

    /**
     * The date reminders the company holds, read inside it.
     *
     * @return list<array{int, int, string}> person, package and date of each
     */
    private function remindersIn(Company $company): array
    {
        return $this->inCompany($company, fn () => Notification::query()
            ->whereIn('type', ['date_coming_up', 'date_overdue'])
            ->get()
            ->map(fn (Notification $notification) => [$notification->notifiable_id, $notification->data['package_id'] ?? 0, (string) $notification->data['params']['date']])
            ->sort()
            ->values()
            ->all());
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function inCompany(Company $company, callable $callback): mixed
    {
        return app(CurrentCompany::class)->runAs($company, fn () => $callback());
    }
}
