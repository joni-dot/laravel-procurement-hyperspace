<?php

namespace Tests\Feature\Ppr\Projects;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Ppr\Actions\Packages\CreatePackage;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectMember;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Projects belong to one company: their codes, the people who can be chosen for them, and who may
 * see and run them all stop at the company's edge.
 */
class ProjectCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->other = Company::factory()->create();
    }

    public function test_two_companies_may_both_use_a_project_code()
    {
        $this->inOtherCompany(fn () => Project::factory()->create(['code' => 'P100']));
        $manager = User::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('projects.store'), ['name' => 'Ours', 'code' => 'p100', 'manager_id' => $manager->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('p100', Project::query()->sole()->code);
    }

    public function test_a_project_cannot_take_another_projects_code_in_the_same_company_whatever_its_case()
    {
        $this->inOtherCompany(fn () => Project::factory()->create(['code' => 'T200']));
        Project::factory()->create(['code' => 'P100']);
        $project = Project::factory()->create(['code' => 'P300']);
        $admin = User::factory()->admin()->create();
        $details = ['name' => 'Renamed', 'risk_amber_days' => 7, 'risk_red_days' => 0];

        $this->actingAs($admin)
            ->put(route('projects.update', $project), [...$details, 'code' => 'p100'])
            ->assertSessionHasErrors('code');
        $this->actingAs($admin)
            ->put(route('projects.update', $project), [...$details, 'code' => 't200'])
            ->assertSessionHasNoErrors();

        $this->assertSame('t200', $project->fresh()->code);
    }

    #[DataProvider('peopleWhoCannotBeChosen')]
    public function test_only_active_members_of_this_company_can_manage_a_new_project(string $who)
    {
        $person = $this->personWho($who);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('projects.store'), ['name' => 'New', 'code' => 'N1', 'manager_id' => $person->id])
            ->assertSessionHasErrors('manager_id');

        $this->assertDatabaseMissing('projects', ['code' => 'N1']);
        $this->actingAs($admin)
            ->get(route('projects.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->reloadOnly('managerOptions', fn (AssertableInertia $reload) => $reload
                    ->where('managerOptions', fn ($people) => $people->pluck('id')->all() === [$admin->id])));
    }

    #[DataProvider('peopleWhoCannotBeChosen')]
    public function test_only_active_members_of_this_company_can_join_a_project(string $who)
    {
        $person = $this->personWho($who);
        $manager = User::factory()->create();
        $project = Project::factory()->withMember($manager, ProjectRole::ProjectManager)->create();

        $this->actingAs($manager)
            ->post(route('projects.members.store', $project), ['user_id' => $person->id, 'role' => ProjectRole::Buyer->value])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(1, $project->members()->count());
        $this->actingAs($manager)
            ->get(route('projects.members.index', $project))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->reloadOnly('userOptions', fn (AssertableInertia $reload) => $reload->where('userOptions', [])));
    }

    /**
     * Even someone already on the project, from before they left or while still invited, is not offered
     * and is refused as a new owner or buyer.
     */
    #[DataProvider('peopleWhoCannotBeChosen')]
    public function test_only_active_members_of_this_company_can_own_or_buy_a_package(string $who)
    {
        $person = $this->personWho($who);
        $manager = User::factory()->create(['name' => 'Max Manager']);
        $project = Project::factory()->withMember($manager, ProjectRole::ProjectManager)->create(['number_includes_category' => false]);
        // The database only lets people of the company onto a project, so the outsider can't be added at all.
        if ($who !== 'from another company') {
            ProjectMember::factory()->for($project)->for($person)->create(['role' => ProjectRole::Buyer]);
        }
        $package = app(CreatePackage::class)->handle($project, 'Transformer', null, null);

        $this->actingAs($manager)
            ->get(route('projects.packages.edit', [$project, $package]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ownerOptions', fn ($people) => $people->pluck('name')->all() === ['Max Manager'])
                ->where('buyerOptions', fn ($people) => $people->pluck('name')->all() === ['Max Manager']));

        $this->actingAs($manager)
            ->put(route('projects.packages.update', [$project, $package]), [
                'title' => 'Transformer', 'status' => 'draft', 'owner_id' => $person->id, 'buyer_id' => $person->id,
            ])
            ->assertSessionHasErrors(['owner_id', 'buyer_id']);

        $this->assertDatabaseHas('packages', ['id' => $package->id, 'owner_id' => null, 'buyer_id' => null]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function peopleWhoCannotBeChosen(): array
    {
        return [
            'from another company' => ['from another company'],
            'whose membership here is deactivated' => ['whose membership here is deactivated'],
            'only invited here' => ['only invited here'],
            'whose account is deactivated' => ['whose account is deactivated'],
        ];
    }

    public function test_an_admin_sees_and_runs_every_project_of_this_company_and_none_of_another()
    {
        $admin = User::factory()->admin()->create();
        Membership::factory()->create(['company_id' => $this->other->id, 'user_id' => $admin->id, 'role' => Role::Admin]);
        $ours = Project::factory()->create(['name' => 'Ours', 'code' => 'O1', 'number_includes_category' => false]);
        $theirs = $this->inOtherCompany(fn () => Project::factory()->create(['name' => 'Theirs', 'code' => 'T1']));
        $details = ['name' => 'Renamed', 'code' => 'X1', 'risk_amber_days' => 7, 'risk_red_days' => 0];

        $this->actingAs($admin)
            ->get(route('projects.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects.data', 1)
                ->where('projects.data.0.code', 'O1')
                ->where('can.create', true));
        $this->get(route('projects.show', $ours))->assertOk();
        $this->put(route('projects.update', $ours), $details)->assertSessionHasNoErrors();
        $this->post(route('projects.packages.store', $ours), ['title' => 'Pump'])->assertSessionHasNoErrors();

        // Their project through our address: the admin rights here don't reach it.
        $this->get(route('projects.show', $theirs))->assertNotFound();
        $this->put(route('projects.update', $theirs), $details)->assertNotFound();
        $this->post(route('projects.packages.store', $theirs), ['title' => 'Pump'])->assertNotFound();

        $this->assertSame('X1', $ours->fresh()->code);
        $this->inOtherCompany(function () use ($theirs) {
            $this->assertSame('T1', $theirs->fresh()->code);
            $this->assertSame(0, Package::query()->count());
        });
        // In their own company the same person is admin there too.
        $this->get(route('projects.show', ['company' => $this->other->slug, 'project' => $theirs->id]))->assertOk();
    }

    public function test_a_company_viewer_who_manages_a_project_runs_it()
    {
        $viewer = User::factory()->create();
        $newMember = User::factory()->create();
        $project = Project::factory()->withDefaultMilestones()->withMember($viewer, ProjectRole::ProjectManager)
            ->create(['code' => 'P1', 'number_includes_category' => false]);

        $this->actingAs($viewer)
            ->get(route('projects.show', $project))
            ->assertOk();
        $this->put(route('projects.update', $project), ['name' => 'Renamed', 'code' => 'P1', 'risk_amber_days' => 10, 'risk_red_days' => 2])
            ->assertSessionHasNoErrors();
        $this->post(route('projects.members.store', $project), ['user_id' => $newMember->id, 'role' => ProjectRole::Buyer->value])
            ->assertSessionHasNoErrors();
        $this->post(route('projects.categories.store', $project), ['code' => 'ELE', 'name' => 'Electrical'])
            ->assertSessionHasNoErrors();
        $this->post(route('projects.milestones.store', $project), ['name' => 'Tested'])
            ->assertSessionHasNoErrors();
        $this->post(route('projects.packages.store', $project), ['title' => 'Pump'])
            ->assertSessionHasNoErrors();

        $package = $project->packages()->sole();
        $this->put(route('projects.packages.update', [$project, $package]), ['title' => 'Big pump', 'status' => 'draft', 'buyer_id' => $newMember->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $project->fresh()->name);
        $this->assertSame($newMember->id, $package->fresh()->buyer_id);
        $this->assertSame(1, $project->categories()->count());
        $this->assertTrue($project->milestones()->where('name', 'Tested')->exists());
        // Creating projects stays with the company's admins.
        $this->get(route('projects.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('can.create', false));
    }

    public function test_an_admin_who_is_not_a_project_member_sees_it_in_the_list_but_not_on_the_dashboard()
    {
        $admin = User::factory()->admin()->create();
        Project::factory()->create(['code' => 'P1']);
        $mine = Project::factory()->withMember($admin, ProjectRole::Viewer)->create(['code' => 'P2']);

        $this->actingAs($admin)
            ->get(route('projects.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('projects.data', 2));
        $this->get(route('projects.dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects', 1)
                ->where('projects.0.id', $mine->id));
    }

    public function test_someone_who_left_the_company_cannot_be_given_a_role_that_changes_anything()
    {
        $manager = User::factory()->create();
        $leaver = User::factory()->create();
        $project = Project::factory()->withMember($manager, ProjectRole::ProjectManager)->withMember($leaver, ProjectRole::Viewer)->create();
        $leaverMember = $project->members()->where('user_id', $leaver->id)->sole();
        Membership::query()->where('company_id', $this->company->id)->where('user_id', $leaver->id)->update(['deactivated_at' => now()]);

        $this->actingAs($manager)
            ->patch(route('projects.members.update', [$project, $leaverMember]), ['role' => 'project_manager'])
            ->assertSessionHasErrors('role');
        $this->assertSame(ProjectRole::Viewer, $leaverMember->fresh()->role);
    }

    public function test_a_manager_who_left_the_company_does_not_keep_the_project_running()
    {
        $manager = User::factory()->create();
        $leaver = User::factory()->create();
        $project = Project::factory()->withMember($manager, ProjectRole::ProjectManager)->withMember($leaver, ProjectRole::ProjectManager)->create();
        Membership::query()->where('company_id', $this->company->id)->where('user_id', $leaver->id)->update(['deactivated_at' => now()]);
        $managerMember = $project->members()->where('user_id', $manager->id)->sole();

        $this->actingAs($manager)
            ->patch(route('projects.members.update', [$project, $managerMember]), ['role' => 'viewer'])
            ->assertSessionHasErrors('role');
        $this->assertSame(ProjectRole::ProjectManager, $managerMember->fresh()->role);
    }

    private function personWho(string $who): User
    {
        return match ($who) {
            'from another company' => tap(User::factory()->withoutCompany()->create(), fn (User $person) => Membership::factory()
                ->create(['company_id' => $this->other->id, 'user_id' => $person->id, 'role' => Role::Buyer])),
            'whose membership here is deactivated' => tap(User::factory()->buyer()->create(), fn (User $person) => $person->memberships()
                ->update(['deactivated_at' => now()])),
            'only invited here' => User::factory()->buyer()->invited()->create(),
            'whose account is deactivated' => User::factory()->buyer()->deactivated()->create(),
        };
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inOtherCompany(callable $callback): mixed
    {
        return app(CurrentCompany::class)->runAs($this->other, $callback);
    }
}
