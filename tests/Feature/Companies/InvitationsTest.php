<?php

namespace Tests\Feature\Companies;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class InvitationsTest extends TestCase
{
    use RefreshDatabase;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->person = User::factory()->create(['email' => 'kim@example.com']);
    }

    public function test_guests_are_sent_to_login()
    {
        $invitation = $this->inviteTo(Company::factory()->create());

        $this->get(route('invitations.index'))->assertRedirect(route('login'));
        $this->post(route('invitations.accept', ['invitation' => $invitation->id]))->assertRedirect(route('login'));
        $this->post(route('invitations.decline', ['invitation' => $invitation->id]))->assertRedirect(route('login'));
    }

    public function test_the_list_shows_the_persons_own_pending_invitations()
    {
        $inviter = User::factory()->withoutCompany()->create(['name' => 'Ada Inviter']);
        $acme = Company::factory()->create(['name' => 'Acme']);
        $globex = Company::factory()->create(['name' => 'Globex']);
        $this->inviteTo($acme, Role::Buyer, ['invited_by_user_id' => $inviter->id, 'invitation_sent_at' => now()->subDay()]);
        $this->inviteTo($globex, Role::Admin, ['invitation_sent_at' => now()->subDays(4)]);
        $this->inviteTo(Company::factory()->create(['name' => 'Withdrawn']), Role::Viewer, ['deactivated_at' => now()]);
        Membership::factory()->invited()->create(['company_id' => $acme->id, 'user_id' => User::factory()->withoutCompany()->create()->id]);

        $this->actingAs($this->person)
            ->get(route('invitations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('invitations/Index')
                ->has('invitations', 2)
                ->where('invitations.0.companyName', 'Acme')
                ->where('invitations.0.role', 'Buyer')
                ->where('invitations.0.invitedBy', 'Ada Inviter')
                ->where('invitations.0.sentOn', now()->subDay()->toDateString())
                ->where('invitations.0.isExpired', false)
                ->where('invitations.1.companyName', 'Globex')
                ->where('invitations.1.isExpired', true)
                ->missing('invitations.0.invitation_token')
                ->where('invitationCount', 1)
            );
    }

    public function test_accepting_joins_the_company_and_opens_it()
    {
        $acme = Company::factory()->create();
        $invitation = $this->inviteTo($acme, Role::Buyer);

        $this->actingAs($this->person)
            ->post(route('invitations.accept', ['invitation' => $invitation->id]))
            ->assertRedirect(route('company.home', ['company' => $acme->slug]));

        $invitation->refresh();
        $this->assertTrue($invitation->isActive());
        $this->assertNull($invitation->invitation_token);
        $this->actingAs($this->person)
            ->get("/{$acme->slug}/dashboard")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.role', 'buyer'));
    }

    public function test_declining_removes_only_the_invitation()
    {
        $invitation = $this->inviteTo(Company::factory()->create());

        $this->actingAs($this->person)
            ->post(route('invitations.decline', ['invitation' => $invitation->id]))
            ->assertRedirect(route('invitations.index'));

        $this->assertNull($invitation->fresh());
        $this->assertTrue($this->person->memberships()->where('company_id', $this->company->id)->sole()->isActive());
    }

    public function test_only_the_persons_own_open_invitations_can_be_accepted_or_declined()
    {
        $company = Company::factory()->create();
        $someoneElses = Membership::factory()->invited()->create(['company_id' => $company->id]);
        $expired = $this->inviteTo(Company::factory()->create(), Role::Viewer, ['invitation_sent_at' => now()->subDays(4)]);
        $withdrawn = $this->inviteTo(Company::factory()->create(), Role::Viewer, ['deactivated_at' => now(), 'invitation_token' => null]);
        $joined = $this->person->memberships()->where('company_id', $this->company->id)->sole();

        foreach ([$someoneElses, $expired, $withdrawn, $joined] as $membership) {
            $this->actingAs($this->person)->post(route('invitations.accept', ['invitation' => $membership->id]))->assertNotFound();
            $this->actingAs($this->person)->post(route('invitations.decline', ['invitation' => $membership->id]))->assertNotFound();
        }

        $this->assertNull($someoneElses->refresh()->joined_at);
        $this->assertNull($expired->refresh()->joined_at);
        $this->assertNotNull($withdrawn->fresh());
        $this->assertNotNull($joined->fresh());
    }

    public function test_the_invitation_count_is_shared_with_one_query()
    {
        $this->inviteTo(Company::factory()->create());
        $this->inviteTo(Company::factory()->create());
        $this->inviteTo(Company::factory()->create(), Role::Viewer, ['invitation_sent_at' => now()->subDays(4)]);

        $this->actingAs($this->person);
        $this->get(route('profile.edit'));

        DB::enableQueryLog();
        $this->get(route('profile.edit'))->assertInertia(fn (AssertableInertia $page) => $page->where('invitationCount', 2));
        $countQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'count(*)')
            && str_contains($query['query'], 'from "memberships"'));
        DB::disableQueryLog();

        $this->assertCount(1, $countQueries);
    }

    public function test_someone_in_no_company_lands_on_their_invitations()
    {
        $newcomer = User::factory()->withoutCompany()->create();
        // A real request outside a company starts without one; the test's own company would linger.
        app(CurrentCompany::class)->clear();

        $this->actingAs($newcomer)->get(route('home'))->assertRedirect(route('invitations.index'));
        $this->actingAs($newcomer)
            ->get(route('invitations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('invitations', 0)
                ->where('company', null)
                ->where('invitationCount', 0));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function inviteTo(Company $company, Role $role = Role::Viewer, array $attributes = []): Membership
    {
        return Membership::factory()->invited()->create([
            'company_id' => $company->id,
            'user_id' => $this->person->id,
            'role' => $role,
            ...$attributes,
        ]);
    }
}
