<?php

namespace Tests\Feature;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Project;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Where people land: their company, and inside it the overview that suits them. People who work on
 * projects (and admins, who see every project) land on the project dashboard, everyone else on the
 * supplier dashboard.
 */
class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_opens_the_persons_company()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect(route('company.home'));
    }

    public function test_guests_end_up_on_the_login_page()
    {
        $this->followingRedirects()
            ->get(route('home'))
            ->assertInertia(fn ($page) => $page->component('auth/Login'));
    }

    public function test_someone_in_no_project_lands_on_the_supplier_dashboard()
    {
        $this->actingAs(User::factory()->buyer()->create())
            ->followingRedirects()
            ->get(route('home'))
            ->assertInertia(fn ($page) => $page->component('srm/Dashboard'));
    }

    public function test_a_project_member_lands_on_the_project_dashboard()
    {
        $viewer = User::factory()->create();
        Project::factory()->withMember($viewer, ProjectRole::Viewer)->create();

        $this->actingAs($viewer)->get(route('company.home'))->assertRedirect(route('projects.dashboard'));
    }

    public function test_an_admin_lands_on_the_project_dashboard_even_without_projects()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('company.home'))
            ->assertRedirect(route('projects.dashboard'));
    }

    public function test_belonging_to_a_project_of_another_company_does_not_count()
    {
        $viewer = User::factory()->create();
        $other = Company::factory()->create();
        Membership::factory()->create(['company_id' => $other->id, 'user_id' => $viewer->id, 'role' => Role::Viewer]);
        app(CurrentCompany::class)->runAs($other, fn () => Project::factory()->withMember($viewer, ProjectRole::Viewer)->create());

        $this->actingAs($viewer)->get(route('company.home'))->assertRedirect(route('dashboard'));
    }
}
