<?php

namespace Tests\Feature\Ppr\Projects;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Models\ProjectMember;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\LoadsPackageTree;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Someone who has left the company is shown on its projects with the name and address they had when
 * they left, never with their account's later ones (Membership::nameOnRecordsSql()).
 */
class FormerMemberNamesTest extends TestCase
{
    use LoadsPackageTree;
    use RefreshDatabase;

    private User $manager;

    private User $lea;

    private Project $project;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['name' => 'Mia Manager']);
        $this->lea = User::factory()->create(['name' => 'Lea Before', 'email' => 'lea.before@example.com']);
        $this->project = Project::factory()->withMember($this->manager, ProjectRole::ProjectManager)->create();
        ProjectMember::factory()->for($this->project)->for($this->lea)->create(['role' => ProjectRole::Buyer]);
        $this->package = Package::factory()->for($this->project)->create();
        $this->package->forceFill(['owner_id' => $this->lea->id, 'buyer_id' => $this->lea->id])->save();

        $this->actingAs($this->lea)
            ->post(route('projects.packages.risk-flag.store', [$this->project, $this->package]), ['reason' => 'Supplier went bankrupt'])
            ->assertSessionHasNoErrors();
    }

    public function test_someone_who_left_keeps_the_name_and_address_they_had_then()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.users.deactivate', $this->lea->memberships()->sole()))
            ->assertRedirect();
        $this->lea->update(['name' => 'Lea After', 'email' => 'lea.after@example.com']);

        $this->assertShowsPerson('Lea Before', 'lea.before@example.com');
        $this->assertPagesDontMention('Lea After', 'lea.after@example.com');
    }

    public function test_leaving_another_company_does_not_change_the_name_shown_here()
    {
        Membership::factory()->for(Company::factory())->create([
            'user_id' => $this->lea->id,
            'known_name' => 'Lea Elsewhere',
            'known_email' => 'lea.elsewhere@example.com',
            'joined_at' => now()->subYear(),
            'deactivated_at' => now(),
        ]);
        $this->lea->update(['name' => 'Lea After', 'email' => 'lea.after@example.com']);

        $this->assertShowsPerson('Lea After', 'lea.after@example.com');
        $this->assertPagesDontMention('Lea Before', 'lea.elsewhere@example.com');
    }

    private function assertShowsPerson(string $name, string $email): void
    {
        $this->actingAs($this->manager)
            ->get(route('projects.members.index', $this->project))
            ->assertInertia(fn ($page) => $page
                ->where('members', fn ($members) => $members->contains(fn ($member) => $member['name'] === $name && $member['email'] === $email)));

        $this->actingAs($this->manager)
            ->get(route('projects.packages.show', [$this->project, $this->package]))
            ->assertInertia(fn ($page) => $page
                ->where('package.ownerName', $name)
                ->where('package.buyerName', $name)
                ->where('risk.flag.flaggedByName', $name));

        $this->actingAs($this->manager)
            ->get(route('projects.packages.edit', [$this->project, $this->package]))
            ->assertInertia(fn ($page) => $page
                ->where('package.ownerName', $name)
                ->where('package.buyerName', $name)
                ->where('ownerOptions', fn ($people) => $people->contains(['id' => $this->lea->id, 'name' => $name, 'email' => $email]))
                ->where('buyerOptions', fn ($people) => $people->contains(['id' => $this->lea->id, 'name' => $name, 'email' => $email])));

        $this->actingAs($this->manager)
            ->getPackageTree($this->project)
            ->assertInertia(fn ($page) => $page
                ->where('packages.0.ownerName', $name)
                ->where('packages.0.buyerName', $name));
    }

    private function assertPagesDontMention(string ...$texts): void
    {
        foreach ($this->pages() as $response) {
            foreach ($texts as $text) {
                $response->assertDontSee($text);
            }
        }
    }

    /**
     * @return list<TestResponse<Response>>
     */
    private function pages(): array
    {
        $this->actingAs($this->manager);

        return [
            $this->get(route('projects.members.index', $this->project)),
            $this->get(route('projects.packages.show', [$this->project, $this->package])),
            $this->get(route('projects.packages.edit', [$this->project, $this->package])),
            $this->getPackageTree($this->project),
        ];
    }
}
