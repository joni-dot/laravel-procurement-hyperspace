<?php

namespace Tests\Feature\Sourcing;

use App\Models\User;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Models\Supplier;
use Closure;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Everyone who may edit a package manages its suppliers; everyone who may see it sees them.
 */
class PackageSupplierAccessTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Package $package;

    private PackageSupplier $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->project = Project::factory()->create();
        $this->package = Package::factory()->for($this->project)->create();
        $this->candidate = PackageSupplier::factory()->for($this->package)->for(Supplier::factory()->active()->create())->create();
    }

    /**
     * @return array<string, array{Closure(self): User}>
     */
    public static function managers(): array
    {
        return [
            'company admin outside the project' => [fn (self $test) => User::factory()->admin()->create()],
            'project manager' => [fn (self $test) => $test->member(ProjectRole::ProjectManager)],
            'project buyer' => [fn (self $test) => $test->member(ProjectRole::Buyer)],
            'owner of this package' => [fn (self $test) => $test->ownerOfThePackage()],
        ];
    }

    /**
     * @return array<string, array{Closure(self): User, bool}>
     */
    public static function onlookers(): array
    {
        return [
            // bool: whether they see the package page at all
            'owner of another package' => [fn (self $test) => $test->member(ProjectRole::PackageOwner), true],
            'project viewer' => [fn (self $test) => $test->member(ProjectRole::Viewer), true],
            'company buyer outside the project' => [fn (self $test) => User::factory()->buyer()->create(), false],
            'company viewer with no project' => [fn (self $test) => User::factory()->create(), false],
        ];
    }

    /**
     * @param  Closure(self): User  $person
     */
    #[DataProvider('managers')]
    public function test_people_who_may_edit_the_package_manage_its_suppliers(Closure $person)
    {
        $this->actingAs($person($this));
        $supplier = Supplier::factory()->active()->create();

        $this->get($this->packageUrl())->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('section_sourcing_suppliers.canManage', true));
        $this->post($this->url('store'), ['supplier_id' => $supplier->id, 'status' => 'asked'])->assertRedirect($this->packageUrl())->assertSessionHasNoErrors();
        $this->put($this->url('update', $this->candidate), ['status' => 'chosen', 'note' => 'Best offer'])->assertRedirect($this->packageUrl())->assertSessionHasNoErrors();
        $this->delete($this->url('destroy', $this->candidate))->assertRedirect($this->packageUrl());

        $this->assertDatabaseHas('package_suppliers', ['package_id' => $this->package->id, 'supplier_id' => $supplier->id]);
        $this->assertModelMissing($this->candidate);
    }

    /**
     * @param  Closure(self): User  $person
     */
    #[DataProvider('onlookers')]
    public function test_everyone_else_changes_nothing(Closure $person, bool $seesThePackage)
    {
        $this->actingAs($person($this));
        $supplier = Supplier::factory()->active()->create();

        $page = $this->get($this->packageUrl());

        if ($seesThePackage) {
            $page->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('section_sourcing_suppliers.canManage', false)
                ->where('section_sourcing_suppliers.candidates.0.id', $this->candidate->id));
        } else {
            $page->assertForbidden();
        }

        $this->post($this->url('store'), ['supplier_id' => $supplier->id, 'status' => 'asked'])->assertForbidden();
        $this->put($this->url('update', $this->candidate), ['status' => 'chosen', 'note' => 'Sneaky'])->assertForbidden();
        $this->delete($this->url('destroy', $this->candidate))->assertForbidden();

        $this->assertDatabaseCount('package_suppliers', 1);
        $this->assertSame(QuoteStatus::Asked, $this->candidate->fresh()?->status);
        Queue::assertNothingPushed();
    }

    public function test_guests_are_sent_to_login()
    {
        $this->post($this->url('store'), ['supplier_id' => 1, 'status' => 'asked'])->assertRedirect(route('login'));
        $this->delete($this->url('destroy', $this->candidate))->assertRedirect(route('login'));
    }

    public function test_a_supplier_entry_of_another_package_cannot_be_reached_through_this_one()
    {
        $manager = $this->member(ProjectRole::ProjectManager);
        $otherPackage = Package::factory()->for($this->project)->create();
        $foreign = PackageSupplier::factory()->for($otherPackage)->for(Supplier::factory()->active()->create())->create();

        $this->actingAs($manager)->put($this->url('update', $foreign), ['status' => 'chosen'])->assertNotFound();
        $this->actingAs($manager)->delete($this->url('destroy', $foreign))->assertNotFound();

        $this->assertModelExists($foreign);
        $this->assertSame(QuoteStatus::Asked, $foreign->fresh()?->status);
    }

    public function test_a_package_of_another_project_cannot_be_reached_through_this_project()
    {
        $manager = $this->member(ProjectRole::ProjectManager);
        $foreignPackage = Package::factory()->create();

        $this->actingAs($manager)
            ->post(route('projects.packages.suppliers.store', [$this->project, $foreignPackage]), ['supplier_id' => Supplier::factory()->active()->create()->id, 'status' => 'asked'])
            ->assertNotFound();

        $this->assertDatabaseCount('package_suppliers', 1);
    }

    public function test_a_mistyped_address_is_not_found()
    {
        $this->actingAs($this->member(ProjectRole::ProjectManager))
            ->delete(route('projects.packages.suppliers.store', [$this->project, $this->package]).'/abc')
            ->assertNotFound();
    }

    public function member(ProjectRole $role): User
    {
        $user = User::factory()->create();
        $this->project->members()->make(['role' => $role])->forceFill(['user_id' => $user->id])->save();

        return $user;
    }

    public function ownerOfThePackage(): User
    {
        $owner = $this->member(ProjectRole::PackageOwner);
        $this->package->forceFill(['owner_id' => $owner->id])->save();

        return $owner;
    }

    private function packageUrl(): string
    {
        return route('projects.packages.show', [$this->project, $this->package]);
    }

    private function url(string $action, ?PackageSupplier $candidate = null): string
    {
        return route("projects.packages.suppliers.{$action}", [$this->project, $this->package, ...($candidate ? [$candidate] : [])]);
    }
}
