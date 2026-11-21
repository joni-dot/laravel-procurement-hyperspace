<?php

namespace Tests\Feature\Notifications;

use App\Enums\Role;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Ppr\Enums\PackageAssignment;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Notifications\PackageAssigned;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Notifications\CertificateExpiringNotifications;
use App\Modules\Srm\Notifications\ContractNeedsActionNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * One notifications page and one bell for every module. Each module decides who may still see its kinds;
 * a kind no module registers is never shown.
 */
class NotificationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->buyer()->create();
        $this->supplier = Supplier::factory()->create(['name' => 'Acme Oy', 'responsible_user_id' => $this->buyer->id]);
    }

    public function test_the_page_lists_project_and_supplier_notifications_together_newest_first()
    {
        $project = Project::factory()->withMember($this->buyer, ProjectRole::Buyer)->create(['name' => 'Harbour crane']);
        $package = Package::factory()->for($project)->create(['number' => 'HC-0001', 'title' => 'Hoist motor']);
        $this->travelTo(now()->subHours(2));
        $this->buyer->notify(new PackageAssigned($package, PackageAssignment::Buyer));
        $this->travelBack();
        $this->travelTo(now()->subHour());
        $certificate = $this->certificateExpiringOn('2027-01-15', 'ISO 9001');
        $this->travelBack();
        $contract = $this->contractNeedingAction(['title' => 'Cleaning', 'auto_renews' => false]);

        $this->actingAs($this->buyer)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('notifications/Index')
                ->where('notifications.total', 3)
                ->where('notifications.data.0.id', $contract->id)
                ->where('notifications.data.0.message', ['Acme Oy: Cleaning ends on ', ['date' => today()->addDays(14)->toDateString()], '.'])
                ->where('notifications.data.0.source', 'Suppliers')
                ->where('notifications.data.1.id', $certificate->id)
                ->where('notifications.data.1.message', ['Acme Oy: the ISO 9001 certificate expires on ', ['date' => '2027-01-15'], '.'])
                ->where('notifications.data.2.message', ['You are now the responsible buyer of HC-0001 Hoist motor.'])
                ->where('notifications.data.2.source', 'Harbour crane')
                ->where('unreadNotificationCount', 3)
            );
    }

    public function test_an_auto_renewing_contract_names_the_last_day_to_cancel()
    {
        $contract = $this->contractNeedingAction(['title' => 'Catering', 'auto_renews' => true]);

        $this->actingAs($this->buyer)
            ->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page
                ->where('notifications.data.0.id', $contract->id)
                ->where('notifications.data.0.message.0', 'Acme Oy: the last day to cancel Catering is ')
            );
    }

    public function test_opening_a_certificate_notification_goes_to_the_supplier_and_a_contract_one_to_the_contract()
    {
        $certificate = $this->certificateExpiringOn('2027-01-15');
        $contract = $this->contractNeedingAction();

        $this->actingAs($this->buyer)
            ->get(route('notifications.show', $certificate->id))
            ->assertRedirect(route('suppliers.show', $this->supplier));
        $this->actingAs($this->buyer)
            ->get(route('notifications.show', $contract->id))
            ->assertRedirect(route('suppliers.contracts.show', [$this->supplier, $contract->data['contract_id']]));

        $this->assertNotNull($certificate->fresh()?->read_at);
        $this->assertNotNull($contract->fresh()?->read_at);
    }

    public function test_contract_notifications_are_hidden_from_someone_who_may_no_longer_see_contract_details()
    {
        $contract = $this->contractNeedingAction();
        $certificate = $this->certificateExpiringOn('2027-01-15');
        $this->buyer->memberships()->update(['role' => Role::Viewer]);
        $this->buyer->forgetMemberships();

        $this->actingAs($this->buyer)
            ->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page
                ->where('notifications.total', 1)
                ->where('notifications.data.0.id', $certificate->id)
                ->where('unreadNotificationCount', 1)
            );
        $this->actingAs($this->buyer)->get(route('notifications.show', $contract->id))->assertNotFound();
        $this->actingAs($this->buyer)->patch(route('notifications.update', $contract->id))->assertNotFound();

        // Given the right again, they see it again.
        $this->buyer->memberships()->update(['role' => Role::Admin]);
        $this->buyer->forgetMemberships();

        $this->actingAs($this->buyer)
            ->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page->where('notifications.total', 2)->where('unreadNotificationCount', 2));
    }

    public function test_a_kind_no_module_registers_is_never_shown_counted_or_opened()
    {
        $unknown = $this->stored($this->buyer, 'mystery_kind', ['secret' => 'value']);

        $this->actingAs($this->buyer)
            ->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page->where('notifications.total', 0)->where('unreadNotificationCount', 0));
        $this->actingAs($this->buyer)->get(route('notifications.show', $unknown->id))->assertNotFound();
        $this->actingAs($this->buyer)->patch(route('notifications.update', $unknown->id))->assertNotFound();

        $this->actingAs($this->buyer)->post(route('notifications.read-all'));
        $this->assertNull($unknown->fresh()?->read_at);
    }

    public function test_someone_elses_supplier_notifications_are_not_found_even_for_an_admin()
    {
        $certificate = $this->certificateExpiringOn('2027-01-15');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('notifications.show', $certificate->id))->assertNotFound();
        $this->actingAs($admin)->patch(route('notifications.update', $certificate->id))->assertNotFound();
        $this->actingAs($admin)
            ->get(route('notifications.index'))
            ->assertInertia(fn ($page) => $page->where('notifications.total', 0)->where('unreadNotificationCount', 0));

        $this->assertNull($certificate->fresh()?->read_at);
    }

    public function test_marking_all_as_read_covers_every_module()
    {
        $project = Project::factory()->withMember($this->buyer, ProjectRole::Buyer)->create();
        $this->buyer->notify(new PackageAssigned(Package::factory()->for($project)->create(), PackageAssignment::Owner));
        $this->certificateExpiringOn('2027-01-15');
        $this->contractNeedingAction();

        $this->actingAs($this->buyer)->post(route('notifications.read-all'));

        $this->assertSame(0, Notification::query()->whereNull('read_at')->count());
    }

    public function test_the_page_takes_the_same_queries_however_many_notifications_of_every_module()
    {
        $project = Project::factory()->withMember($this->buyer, ProjectRole::Buyer)->create();
        $this->addOneOfEachKind($project);
        // The first visit also records the company as the person's last one.
        $this->pageQueries();
        $few = $this->pageQueries();

        foreach (range(1, 4) as $ignored) {
            $this->addOneOfEachKind(Project::factory()->withMember($this->buyer, ProjectRole::Buyer)->create());
        }

        $this->assertSame($few, $this->pageQueries());
    }

    public function test_the_bell_counts_every_module_with_one_query()
    {
        $project = Project::factory()->withMember($this->buyer, ProjectRole::Buyer)->create();
        $this->addOneOfEachKind($project);
        $this->stored($this->buyer, 'mystery_kind', []);
        $this->buyer->activeMembershipIn($this->company->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $count = Notification::query()->visibleTo($this->buyer)->whereNull('read_at')->count();
        DB::disableQueryLog();

        $this->assertSame(3, $count);
        $this->assertCount(1, DB::getQueryLog());
    }

    private function addOneOfEachKind(Project $project): void
    {
        $this->buyer->notify(new PackageAssigned(Package::factory()->for($project)->create(), PackageAssignment::Buyer));
        $this->certificateExpiringOn('2027-01-15');
        $this->contractNeedingAction();
    }

    private function pageQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->buyer)->get(route('notifications.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    private function certificateExpiringOn(string $date, ?string $type = null): Notification
    {
        $certificate = Certificate::factory()
            ->for($this->supplier)
            ->for(CertificateType::factory()->state($type === null ? [] : ['name' => $type]), 'type')
            ->create(['expires_on' => $date]);

        return $this->stored($this->buyer, CertificateExpiringNotifications::TYPE, CertificateExpiringNotifications::about($certificate));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contractNeedingAction(array $attributes = []): Notification
    {
        $contract = Contract::factory()->for($this->supplier)->endsInDays(14)->create($attributes);

        return $this->stored($this->buyer, ContractNeedsActionNotifications::TYPE, ContractNeedsActionNotifications::about($contract->refresh()));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stored(User $user, string $type, array $data): Notification
    {
        $notification = new Notification;
        $notification->forceFill([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => $data,
        ])->save();

        return $notification;
    }
}
