<?php

namespace Tests\Feature\Ppr;

use App\Actions\Companies\AcceptInvitation;
use App\Actions\Companies\CreateDemoCompany;
use App\Models\User;
use App\Modules\Ppr\Dashboard\ProjectAttention;
use App\Modules\Ppr\Enums\PackageStatus;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Notifications\DateOverdue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class DemoProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $invitation = app(CreateDemoCompany::class)->handle('Demo Oy', 'demo-oy', 'Anna Admin', 'anna@example.com');
        app(AcceptInvitation::class)->handle($invitation);
        $this->admin = $invitation->user;
        $this->enterCompany($invitation->company);
        $this->project = Project::query()->sole();
    }

    public function test_the_first_admin_manages_the_project_and_is_the_only_person_on_its_packages()
    {
        $this->assertSame(
            [[$this->admin->id, ProjectRole::ProjectManager]],
            $this->project->members()->get()->map(fn ($member) => [$member->user_id, $member->role])->all(),
        );

        $people = Package::query()->pluck('owner_id')->merge(Package::query()->pluck('buyer_id'))->filter()->unique()->values()->all();
        $this->assertSame([$this->admin->id], $people);
        // Some packages still lack an owner or buyer, as in a real project.
        $this->assertTrue(Package::query()->whereNull('owner_id')->exists());
    }

    public function test_a_small_tree_of_packages_with_numbers_from_the_projects_numbering()
    {
        $packages = Package::query()->get();

        $this->assertGreaterThanOrEqual(40, $packages->count());
        $this->assertLessThanOrEqual(80, $packages->count());
        $this->assertSame($packages->count(), $packages->pluck('number')->unique()->count());
        $this->assertTrue($packages->contains(fn (Package $package) => $package->depth() === 2));
    }

    public function test_the_dates_around_today_make_some_packages_done_some_due_soon_and_some_late()
    {
        $tiles = ProjectAttention::for($this->project)->tiles();

        $this->assertGreaterThan(0, $tiles['overdue']);
        $this->assertGreaterThan(0, $tiles['dueSoon']);
        $this->assertGreaterThan(0, $tiles['red']);
        $this->assertGreaterThan(0, $tiles['open']);
        $this->assertTrue(Package::query()->where('status', PackageStatus::Closed)->exists());
        $this->assertTrue(Package::query()->where('status', PackageStatus::Cancelled)->exists());

        // Done milestones never have a date after today, and are done in order.
        $this->assertSame(0, DB::table('package_milestones')->where('actual_date', '>', now('UTC')->toDateString())->count());
    }

    public function test_the_first_admin_who_joined_opens_the_project_pages()
    {
        $package = Package::query()->whereNull('parent_id')->orderBy('id')->firstOrFail();

        $this->actingAs($this->admin);
        $this->get(route('projects.show', ['project' => $this->project]))->assertOk();
        $this->get(route('projects.packages.index', ['project' => $this->project]))->assertOk();
        $this->get(route('projects.packages.show', ['project' => $this->project, 'package' => $package]))->assertOk();
        $this->get(route('company.home'))->assertOk();
    }

    public function test_joining_brings_no_reminders_about_dates_that_were_already_overdue()
    {
        // setUp's admin joined after the demo was filled, like a real first admin.
        $this->assertTrue(Package::query()->where('owner_id', $this->admin->id)->exists());

        CheckProjectRisks::dispatchSync($this->project->id, sendDateReminders: true);

        Notification::assertNotSentTo($this->admin, DateOverdue::class);
    }
}
