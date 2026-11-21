<?php

namespace Tests\Feature\Ppr\Notifications;

use App\Companies\CurrentCompany;
use App\Companies\NavigationCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Ppr\Enums\PackageAssignment;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Notification;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Notifications\PackageAssigned;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Tests\Concerns\RefreshDatabase;
use Tests\Concerns\RunsQueuedJobsLikeAWorker;
use Tests\TestCase;

/**
 * Notifications belong to the company of their project. Someone in two companies sees each
 * company's notifications only inside it, and the bell counts only the company the menu points into.
 */
class NotificationCompanyTest extends TestCase
{
    use RefreshDatabase, RunsQueuedJobsLikeAWorker;

    private Company $other;

    private User $person;

    private Notification $here;

    private Notification $there;

    protected function setUp(): void
    {
        parent::setUp();

        $this->other = Company::factory()->create();
        $this->person = User::factory()->create();
        Membership::factory()->create(['company_id' => $this->other->id, 'user_id' => $this->person->id, 'role' => Role::Viewer]);
        $this->here = $this->notify($this->person, $this->packageIn('Harbour crane'));
        $this->there = $this->inCompany($this->other, fn () => $this->notify($this->person, $this->packageIn('Wind farm')));
    }

    public function test_each_company_lists_and_counts_only_its_own_notifications()
    {
        $this->inCompany($this->other, fn () => $this->notify($this->person, $this->packageIn('Wind farm phase 2')));

        $this->actingAs($this->person)
            ->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page
                ->where('notifications.total', 1)
                ->where('notifications.data.0.projectName', 'Harbour crane')
                ->where('ppr.unreadNotificationCount', 1)
            );

        $this->actingAs($this->person)
            ->get(route('notifications.index', ['company' => $this->other->slug]))
            ->assertInertia(fn ($page) => $page
                ->where('notifications.total', 2)
                ->where('ppr.unreadNotificationCount', 2)
            );
    }

    public function test_account_pages_count_the_home_companys_notifications()
    {
        $this->inCompany($this->other, fn () => $this->notify($this->person, $this->packageIn('Wind farm phase 2')));

        $this->person->forceFill(['last_company_id' => $this->other->id])->save();
        $this->assertBellOnAccountPage($this->person, 2);

        $this->person->forceFill(['last_company_id' => $this->company->id])->save();
        $this->assertBellOnAccountPage($this->person, 1);
    }

    public function test_someone_in_no_company_has_nothing_to_count()
    {
        $this->assertBellOnAccountPage(User::factory()->withoutCompany()->create(), 0);
    }

    public function test_another_companys_notification_is_not_found()
    {
        $this->actingAs($this->person)->get(route('notifications.show', $this->there->id))->assertNotFound();
        $this->actingAs($this->person)->patch(route('notifications.update', $this->there->id))->assertNotFound();

        $this->assertNull($this->inCompany($this->other, fn () => $this->there->fresh()?->read_at));
    }

    public function test_marking_all_as_read_marks_only_this_companys_notifications()
    {
        $this->actingAs($this->person)
            ->from(route('notifications.index'))
            ->post(route('notifications.read-all'))
            ->assertRedirect(route('notifications.index'));

        $this->assertNotNull($this->here->fresh()?->read_at);
        $this->assertNull($this->inCompany($this->other, fn () => $this->there->fresh()?->read_at));
    }

    public function test_the_bell_takes_the_same_queries_however_many_notifications_and_projects()
    {
        $this->person->forceFill(['last_company_id' => $this->company->id])->save();
        $few = [$this->bellQueries(), $this->bellQueriesOnAccountPage()];

        foreach (range(1, 5) as $ignored) {
            $package = $this->packageIn('Another project');
            $this->notify($this->person, $package);
            $this->notify($this->person, $package);
        }

        $this->assertSame($few, [$this->bellQueries(), $this->bellQueriesOnAccountPage()]);
        $this->assertSame(1, $few[0]);
    }

    public function test_platform_pages_read_no_notifications_even_for_a_member()
    {
        $platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();
        Membership::factory()->create(['company_id' => $this->company->id, 'user_id' => $platformAdmin->id, 'role' => Role::Admin]);
        $this->notify($platformAdmin, $this->here->project->packages()->firstOrFail());
        $tables = [];
        DB::listen(function ($query) use (&$tables) {
            preg_match_all('/\b(?:from|join|into|update)\s+"?(\w+)"?/i', $query->sql, $matches);
            array_push($tables, ...$matches[1]);
        });

        $this->actingAs($platformAdmin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('platform.companies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ppr.unreadNotificationCount', 0));

        $this->assertNotContains('notifications', $tables);
        $this->assertNotContains('projects', $tables);
    }

    public function test_a_queued_notification_is_dropped_when_the_person_left_this_company_before_it_went_out()
    {
        $package = $this->here->project->packages()->firstOrFail();
        $this->queueJobsForTheWorker();
        $this->person->notify(new PackageAssigned($package, PackageAssignment::Owner));
        $this->person->memberships()->where('company_id', $this->company->id)->update(['deactivated_at' => now()]);

        $this->runQueuedJobsLikeAWorker();

        $this->assertNoJobFailed();
        $this->assertSame(0, Notification::query()->where('data->params->role', 'owner')->count());
    }

    public function test_a_queued_notification_goes_out_in_its_own_company_whatever_happens_in_another()
    {
        $package = $this->here->project->packages()->firstOrFail();
        $this->queueJobsForTheWorker();
        $this->person->notify(new PackageAssigned($package, PackageAssignment::Owner));
        $this->person->memberships()->where('company_id', $this->other->id)->update(['deactivated_at' => now()]);

        $this->runQueuedJobsLikeAWorker();

        $this->assertNoJobFailed();
        $this->assertSame([$this->company->id], Notification::query()->where('data->params->role', 'owner')->pluck('company_id')->all());
        $this->assertSame(0, $this->inCompany($this->other, fn () => Notification::query()->where('data->params->role', 'owner')->count()));
    }

    private function packageIn(string $projectName): Package
    {
        $project = Project::factory()->withMember($this->person, ProjectRole::Buyer)->create(['name' => $projectName]);

        return Package::factory()->for($project)->create();
    }

    private function notify(User $user, Package $package): Notification
    {
        $earlier = Notification::query()->pluck('id');

        $user->notify(new PackageAssigned($package, PackageAssignment::Buyer));

        return Notification::query()->whereNotIn('id', $earlier)->sole();
    }

    private function assertBellOnAccountPage(User $user, int $count): void
    {
        // A request to an account page starts outside every company.
        app(CurrentCompany::class)->clear();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ppr.unreadNotificationCount', $count));

        $this->enterCompany($this->company);
    }

    /**
     * The queries of the shared count alone, inside this company.
     */
    private function bellQueries(): int
    {
        $person = $this->person->fresh() ?? $this->fail('The person is gone.');
        // The page reads these for the menu anyway (the company and the role in it); the bell must not add to them.
        app(NavigationCompany::class)->for($person);
        $person->activeMembershipIn($this->company->id);
        $this->app['request']->setUserResolver(fn () => $person);
        $count = Inertia::getShared('ppr');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $count();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    private function bellQueriesOnAccountPage(): int
    {
        app(CurrentCompany::class)->clear();
        $queries = $this->bellQueries();
        $this->enterCompany($this->company);

        return $queries;
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
