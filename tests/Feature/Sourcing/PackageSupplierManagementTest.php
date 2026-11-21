<?php

namespace Tests\Feature\Sourcing;

use App\Companies\CurrentCompany;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Sourcing\Actions\SavePackageSupplier;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PackageSupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Project $project;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->manager = User::factory()->create();
        $this->project = Project::factory()->withMember($this->manager, ProjectRole::ProjectManager)->create();
        $this->package = Package::factory()->for($this->project)->create();
        $this->actingAs($this->manager);
    }

    public function test_a_supplier_is_added_with_its_status_and_note()
    {
        $supplier = Supplier::factory()->active()->create();

        $this->post($this->url('store'), ['supplier_id' => $supplier->id, 'status' => 'offer_received', 'note' => '  Fast delivery  '])
            ->assertSessionHasNoErrors();

        $candidate = PackageSupplier::query()->sole();
        $this->assertSame($supplier->id, $candidate->supplier_id);
        $this->assertSame(QuoteStatus::OfferReceived, $candidate->status);
        $this->assertSame('Fast delivery', $candidate->note);
    }

    public function test_archived_blocked_and_other_companies_suppliers_cannot_be_added()
    {
        $archived = Supplier::factory()->active()->archived()->create();
        $blocked = Supplier::factory()->create(['status' => SupplierStatus::Blocked]);
        $theirs = app(CurrentCompany::class)->runAs(Company::factory()->create(), fn () => Supplier::factory()->active()->create());

        $this->post($this->url('store'), ['supplier_id' => $archived->id, 'status' => 'asked'])
            ->assertSessionHasErrors(['supplier_id' => 'Archived suppliers cannot be added.']);
        $this->post($this->url('store'), ['supplier_id' => $blocked->id, 'status' => 'asked'])
            ->assertSessionHasErrors(['supplier_id' => 'Blocked suppliers cannot be added.']);
        // The same answer as for a supplier that doesn't exist, so nothing about the other company shows.
        $this->post($this->url('store'), ['supplier_id' => $theirs->id, 'status' => 'asked'])
            ->assertSessionHasErrors(['supplier_id' => 'Choose a supplier from the list.']);
        $this->post($this->url('store'), ['supplier_id' => $theirs->id + 1000, 'status' => 'asked'])
            ->assertSessionHasErrors(['supplier_id' => 'Choose a supplier from the list.']);
        $this->post($this->url('store'), ['supplier_id' => 'abc', 'status' => 'asked'])
            ->assertSessionHasErrors(['supplier_id' => 'Choose a supplier from the list.']);

        $this->assertDatabaseCount('package_suppliers', 0);
    }

    public function test_a_supplier_is_on_a_package_only_once()
    {
        $supplier = Supplier::factory()->active()->create();
        PackageSupplier::factory()->for($this->package)->for($supplier)->create();

        $this->post($this->url('store'), ['supplier_id' => $supplier->id, 'status' => 'asked'])
            ->assertSessionHasErrors(['supplier_id' => 'This supplier is already on the package.']);

        $this->assertDatabaseCount('package_suppliers', 1);
    }

    public function test_adding_the_same_supplier_twice_at_once_is_refused_instead_of_failing()
    {
        $supplier = Supplier::factory()->active()->create();
        // As if another request had added it after this one was validated.
        PackageSupplier::factory()->for($this->package)->for($supplier)->create();
        $late = (new PackageSupplier)->forceFill(['package_id' => $this->package->id, 'supplier_id' => $supplier->id]);

        $this->expectException(ValidationException::class);

        app(SavePackageSupplier::class)->handle($late, QuoteStatus::Asked, null);
    }

    public function test_choosing_from_a_page_opened_before_someone_else_chose_still_wins()
    {
        $first = PackageSupplier::factory()->for($this->package)->for(Supplier::factory()->active()->create())->chosen()->create();
        $second = PackageSupplier::factory()->for($this->package)->for(Supplier::factory()->active()->create())->create(['status' => QuoteStatus::OfferReceived]);
        // A's page still shows the first as chosen while B chooses the second.
        $seenByA = PackageSupplier::query()->findOrFail($first->id);
        app(SavePackageSupplier::class)->handle($second, QuoteStatus::Chosen, null);

        $previous = app(SavePackageSupplier::class)->handle($seenByA, QuoteStatus::Chosen, 'Best offer');

        $this->assertSame(QuoteStatus::OfferReceived, $previous);
        $this->assertSame(QuoteStatus::Chosen, $first->fresh()->status);
        $this->assertSame(QuoteStatus::OfferReceived, $second->fresh()->status);
    }

    public function test_status_and_note_are_checked()
    {
        $supplier = Supplier::factory()->active()->create();
        $candidate = PackageSupplier::factory()->for($this->package)->for($supplier)->create();

        $this->post($this->url('store'), ['supplier_id' => Supplier::factory()->active()->create()->id, 'status' => 'won', 'note' => str_repeat('a', 501)])
            ->assertSessionHasErrors(['status', 'note' => 'Keep the note to 500 characters or fewer.']);
        $this->put($this->url('update', $candidate), ['status' => '', 'note' => str_repeat('a', 501)])
            ->assertSessionHasErrors(['status', 'note']);
        $this->put($this->url('update', $candidate), ['status' => 'declined', 'note' => str_repeat('a', 500)])
            ->assertSessionHasNoErrors();

        $this->assertSame(QuoteStatus::Declined, $candidate->fresh()?->status);
    }

    public function test_choosing_a_supplier_moves_the_one_chosen_before_back_to_offer_received()
    {
        $first = PackageSupplier::factory()->for($this->package)->for(Supplier::factory()->active()->create())->chosen()->create();
        $second = PackageSupplier::factory()->for($this->package)->for(Supplier::factory()->active()->create())->create(['status' => QuoteStatus::OfferReceived]);
        $elsewhere = PackageSupplier::factory()->for(Package::factory()->for($this->project)->create())->for(Supplier::factory()->active()->create())->chosen()->create();

        $this->put($this->url('update', $second), ['status' => 'chosen', 'note' => null])->assertSessionHasNoErrors();

        $this->assertSame(QuoteStatus::OfferReceived, $first->fresh()?->status);
        $this->assertSame(QuoteStatus::Chosen, $second->fresh()?->status);
        $this->assertSame(QuoteStatus::Chosen, $elsewhere->fresh()?->status);

        $third = Supplier::factory()->active()->create();
        $this->post($this->url('store'), ['supplier_id' => $third->id, 'status' => 'chosen'])->assertSessionHasNoErrors();

        $this->assertSame(QuoteStatus::OfferReceived, $second->fresh()?->status);
        $this->assertSame([$third->id], PackageSupplier::query()->where('package_id', $this->package->id)->where('status', 'chosen')->pluck('supplier_id')->all());
    }

    public function test_an_archived_or_blocked_supplier_stays_listed_but_cannot_become_the_chosen_one()
    {
        $archived = PackageSupplier::factory()->for($this->package)->for(Supplier::factory()->active()->create())->create();
        $blocked = PackageSupplier::factory()->for($this->package)->for(Supplier::factory()->active()->create())->create();
        $archived->supplier->forceFill(['archived_at' => now()])->save();
        $blocked->supplier->forceFill(['status' => SupplierStatus::Blocked])->save();

        $this->put($this->url('update', $archived), ['status' => 'chosen'])->assertSessionHasErrors(['status' => 'Archived suppliers cannot be chosen.']);
        $this->put($this->url('update', $blocked), ['status' => 'chosen'])->assertSessionHasErrors(['status' => 'Blocked suppliers cannot be chosen.']);
        $this->put($this->url('update', $blocked), ['status' => 'declined', 'note' => 'Blocked since'])->assertSessionHasNoErrors();

        $this->assertSame(QuoteStatus::Asked, $archived->fresh()?->status);
        $this->assertSame(QuoteStatus::Declined, $blocked->fresh()?->status);
    }

    public function test_a_chosen_supplier_blocked_later_keeps_its_place_and_its_note_can_change()
    {
        $chosen = PackageSupplier::factory()->for($this->package)->for(Supplier::factory()->active()->create())->chosen()->create();
        $chosen->supplier->forceFill(['status' => SupplierStatus::Blocked])->save();

        $this->put($this->url('update', $chosen), ['status' => 'chosen', 'note' => 'Looking for another'])->assertSessionHasNoErrors();

        $this->assertSame('Looking for another', $chosen->fresh()?->note);
        $this->assertSame(QuoteStatus::Chosen, $chosen->fresh()?->status);
    }

    public function test_any_supplier_can_be_removed_including_the_chosen_one()
    {
        $chosen = PackageSupplier::factory()->for($this->package)->for(Supplier::factory()->active()->create())->chosen()->create();

        $this->delete($this->url('destroy', $chosen))->assertRedirect(route('projects.packages.show', [$this->project, $this->package]));

        $this->assertModelMissing($chosen);
        $this->assertModelExists($chosen->supplier);
    }

    public function test_changes_to_the_chosen_supplier_queue_a_risk_check_of_the_package()
    {
        $supplier = Supplier::factory()->active()->create();

        $added = $this->assertQueuesCheck(fn () => $this->post($this->url('store'), ['supplier_id' => $supplier->id, 'status' => 'chosen']));
        $candidate = PackageSupplier::query()->sole();
        $unchosen = $this->assertQueuesCheck(fn () => $this->put($this->url('update', $candidate), ['status' => 'declined']));
        $chosen = $this->assertQueuesCheck(fn () => $this->put($this->url('update', $candidate), ['status' => 'chosen']));
        $removed = $this->assertQueuesCheck(fn () => $this->delete($this->url('destroy', $candidate)));

        foreach ([$added, $unchosen, $chosen, $removed] as $check) {
            $this->assertSame($this->project->id, $check->projectId);
            $this->assertSame($this->manager->id, $check->changedById);
            $this->assertSame([$this->package->id], $check->savedPackageIds);
        }
    }

    public function test_changes_that_leave_the_chosen_supplier_alone_queue_no_check()
    {
        $supplier = Supplier::factory()->active()->create();
        $this->post($this->url('store'), ['supplier_id' => $supplier->id, 'status' => 'asked'])->assertSessionHasNoErrors();
        $candidate = PackageSupplier::query()->sole();
        $this->put($this->url('update', $candidate), ['status' => 'offer_received', 'note' => 'Received'])->assertSessionHasNoErrors();
        $this->delete($this->url('destroy', $candidate))->assertSessionHasNoErrors();

        $chosen = PackageSupplier::factory()->for($this->package)->for(Supplier::factory()->active()->create())->chosen()->create();
        $this->put($this->url('update', $chosen), ['status' => 'chosen', 'note' => 'Only the note'])->assertSessionHasNoErrors();

        Queue::assertNotPushed(CheckProjectRisks::class);
    }

    private function assertQueuesCheck(callable $save): CheckProjectRisks
    {
        Queue::fake();
        $save()->assertSessionHasNoErrors();
        Queue::assertPushed(CheckProjectRisks::class, 1);

        return Queue::pushed(CheckProjectRisks::class)->sole();
    }

    private function url(string $action, ?PackageSupplier $candidate = null): string
    {
        return route("projects.packages.suppliers.{$action}", [$this->project, $this->package, ...($candidate ? [$candidate] : [])]);
    }
}
