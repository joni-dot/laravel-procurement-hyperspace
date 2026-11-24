<?php

namespace Tests\Feature\Platform;

use App\Actions\Companies\CreateCompany;
use App\Auth\StrongSignIn;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\MadeCompanyAdmin;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * A company whose first admin never accepted (or whose address was mistyped) can be got going again
 * by a platform admin, but only while it has no admin of its own. A company whose admins have all left
 * gets one of its own members as admin; the platform admin never joins it.
 */
class FirstAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;

    private Company $acme;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->platformAdmin = User::factory()->platformAdmin()->withTwoFactor()->create();
        $this->withSession(['auth.password_confirmed_at' => time(), StrongSignIn::SESSION_KEY => $this->platformAdmin->id]);
        $this->acme = app(CreateCompany::class)->handle('Acme', 'acme', 'Anna Admin', 'ana@exmaple.com', $this->platformAdmin)->company;
    }

    public function test_the_list_offers_it_only_for_companies_without_an_admin()
    {
        $running = Company::factory()->create(['name' => 'Running Oy']);
        Membership::factory()->create(['company_id' => $running->id, 'role' => Role::Admin]);

        $this->actingAs($this->platformAdmin)
            ->get(route('platform.companies.index', ['search' => 'acme']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('companies.data.0.first_admin_invitation', ['name' => 'Anna Admin', 'email' => 'ana@exmaple.com']));

        $this->actingAs($this->platformAdmin)
            ->get(route('platform.companies.index', ['search' => 'running']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('companies.data.0.first_admin_invitation', null));
    }

    public function test_sending_again_to_the_same_address_replaces_the_link()
    {
        $invitation = $this->acme->pendingAdminInvitation()->sole();
        $oldToken = $invitation->invitation_token;

        $this->sendFirstAdmin('Anna Admin', 'ana@exmaple.com')->assertSessionHasNoErrors();

        $this->assertNotSame($oldToken, $invitation->fresh()->invitation_token);
        $this->assertSame(1, $this->acme->memberships()->pendingInvitation()->count());
        Notification::assertSentToTimes($invitation->user, UserInvitation::class, 2);
    }

    public function test_a_corrected_address_gets_the_invitation_and_the_mistyped_ones_link_stops_working()
    {
        $mistyped = $this->acme->pendingAdminInvitation()->sole();

        $this->sendFirstAdmin('Anna Admin', 'anna@example.com')->assertSessionHasNoErrors();

        $mistyped->refresh();
        $this->assertNotNull($mistyped->deactivated_at);
        $this->assertNull($mistyped->invitation_token);
        $corrected = $this->acme->pendingAdminInvitation()->sole();
        $this->assertSame('anna@example.com', $corrected->known_email);
        $this->assertSame(Role::Admin, $corrected->role);
        Notification::assertSentTo($corrected->user, UserInvitation::class);
    }

    public function test_a_company_with_an_admin_is_left_to_its_own_admins()
    {
        Membership::factory()->create(['company_id' => $this->acme->id, 'role' => Role::Admin]);

        $this->sendFirstAdmin('Someone Else', 'someone@example.com')->assertSessionHasErrors('admin_email');

        $this->assertSame(0, Membership::query()->where('known_email', 'someone@example.com')->count());
        $this->assertFalse(User::query()->where('email', 'someone@example.com')->exists());
    }

    public function test_the_answer_is_the_same_whether_or_not_the_address_has_an_account()
    {
        User::factory()->withoutCompany()->create(['email' => 'existing@example.com', 'name' => 'Their Own Name']);

        $this->sendFirstAdmin('Typed Name', 'existing@example.com');
        $withAccount = session(SessionKey::FLASH_DATA)['toast']['message'] ?? null;
        $this->sendFirstAdmin('Typed Name', 'brand.new@example.com');
        $withoutAccount = session(SessionKey::FLASH_DATA)['toast']['message'] ?? null;

        $this->assertSame(
            str_replace('existing@example.com', 'X', (string) $withAccount),
            str_replace('brand.new@example.com', 'X', (string) $withoutAccount),
        );
        $this->assertSame('Their Own Name', User::query()->where('email', 'existing@example.com')->value('name'));
    }

    public function test_a_company_with_members_is_never_sent_an_outside_admin()
    {
        $member = Membership::factory()->create(['company_id' => $this->acme->id, 'role' => Role::Viewer]);

        foreach (['someone@example.com', $this->platformAdmin->email] as $email) {
            $this->sendFirstAdmin('Outsider', $email)->assertSessionHasErrors('admin_email');
        }

        $this->assertSame([$member->id], $this->acme->memberships()->whereNotNull('joined_at')->pluck('id')->all());
        $this->assertFalse($this->platformAdmin->memberships()->where('company_id', $this->acme->id)->exists());
    }

    public function test_a_member_of_a_company_without_admins_can_be_made_its_admin()
    {
        $member = Membership::factory()->create(['company_id' => $this->acme->id, 'role' => Role::Viewer]);
        $waiting = $this->acme->pendingAdminInvitation()->sole();

        $this->assertSame(1, $this->acmeRow()['members_to_promote_count']);
        $this->searchMembers()->assertExactJson(['members' => [['id' => $member->id, 'name' => $member->user->name, 'email' => $member->user->email]]]);

        $this->promote($member->id)->assertSessionHasNoErrors();

        $this->assertSame(Role::Admin, $member->refresh()->role);
        $this->assertNull($waiting->refresh()->invitation_token);
        Notification::assertSentTo($member->user, MadeCompanyAdmin::class, fn (MadeCompanyAdmin $notification) => $notification->companyName === 'Acme');
        $this->assertFalse($this->platformAdmin->memberships()->where('company_id', $this->acme->id)->exists());
    }

    public function test_only_an_active_member_of_that_company_can_be_made_admin_and_only_while_it_has_none()
    {
        $platformAdminsOwn = Membership::factory()->create(['company_id' => $this->acme->id, 'user_id' => $this->platformAdmin->id, 'role' => Role::Viewer]);
        $former = Membership::factory()->create(['company_id' => $this->acme->id, 'role' => Role::Viewer, 'deactivated_at' => now()]);
        $elsewhere = Membership::factory()->create(['company_id' => Company::factory()->create()->id, 'role' => Role::Viewer]);
        $invited = $this->acme->pendingAdminInvitation()->sole();

        foreach ([$platformAdminsOwn, $former, $elsewhere, $invited] as $membership) {
            $this->promote($membership->id)->assertSessionHasErrors('membership_id');
        }
        $this->assertFalse($this->acme->hasActiveAdmin());
        $this->assertSame(Role::Viewer, $elsewhere->refresh()->role);

        $member = Membership::factory()->create(['company_id' => $this->acme->id, 'role' => Role::Viewer]);
        Membership::factory()->create(['company_id' => $this->acme->id, 'role' => Role::Admin]);
        $this->promote($member->id)->assertSessionHasErrors('membership_id');
        $this->assertSame(Role::Viewer, $member->refresh()->role);
    }

    public function test_the_list_names_no_members_and_offers_a_choice_only_for_companies_without_an_admin()
    {
        $running = Company::factory()->create(['name' => 'Running Oy']);
        Membership::factory()->create(['company_id' => $running->id, 'role' => Role::Admin]);
        Membership::factory()->create(['company_id' => $running->id, 'role' => Role::Viewer]);
        $member = Membership::factory()->create(['company_id' => $this->acme->id, 'role' => Role::Viewer]);

        foreach (['acme' => 1, 'running' => 0] as $search => $membersToPromote) {
            $this->actingAs($this->platformAdmin)
                ->get(route('platform.companies.index', ['search' => $search]))
                ->assertDontSee($member->user->email)
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('companies.data', 1)
                    ->where('companies.data.0.members_to_promote_count', $membersToPromote));
        }
    }

    public function test_a_member_whose_account_is_deactivated_is_neither_offered_nor_made_admin()
    {
        $deactivated = Membership::factory()->create([
            'company_id' => $this->acme->id,
            'user_id' => User::factory()->withoutCompany()->deactivated()->create()->id,
        ]);
        $active = Membership::factory()->create(['company_id' => $this->acme->id]);

        $this->assertSame(1, $this->acmeRow()['active_members_count']);
        $this->assertSame(1, $this->acmeRow()['members_to_promote_count']);
        $this->assertSame([$active->id], $this->searchMembers()->json('members.*.id'));

        $this->promote($deactivated->id)->assertSessionHasErrors('membership_id');
        $this->assertSame(Role::Viewer, $deactivated->refresh()->role);
        Notification::assertNothingSentTo($deactivated->user);
    }

    public function test_an_admin_whose_account_is_deactivated_leaves_the_company_needing_a_new_one()
    {
        Membership::factory()->create([
            'company_id' => $this->acme->id,
            'user_id' => User::factory()->withoutCompany()->deactivated()->create()->id,
            'role' => Role::Admin,
        ]);
        $member = Membership::factory()->create(['company_id' => $this->acme->id]);

        $this->assertNotNull($this->acmeRow()['first_admin_invitation']);
        $this->assertSame(1, $this->acmeRow()['members_to_promote_count']);

        $this->promote($member->id)->assertSessionHasNoErrors();
        $this->assertSame(Role::Admin, $member->refresh()->role);
    }

    public function test_a_company_whose_only_member_has_a_deactivated_account_gets_an_invitation()
    {
        $deactivatedUser = User::factory()->withoutCompany()->deactivated()->create();
        Membership::factory()->create(['company_id' => $this->acme->id, 'user_id' => $deactivatedUser->id]);

        $this->assertSame(0, $this->acmeRow()['members_to_promote_count']);

        // They are on the company's list already, so an invitation can't add them.
        $this->sendFirstAdmin('Them Again', $deactivatedUser->email)->assertSessionHasErrors('admin_email');

        $this->sendFirstAdmin('Anna Admin', 'anna@example.com')->assertSessionHasNoErrors();
        $this->assertSame('anna@example.com', $this->acme->pendingAdminInvitation()->sole()->known_email);
    }

    public function test_the_platform_admin_is_never_offered_as_the_new_admin()
    {
        Membership::factory()->create(['company_id' => $this->acme->id, 'user_id' => $this->platformAdmin->id]);
        $member = Membership::factory()->create(['company_id' => $this->acme->id]);

        $this->assertSame(2, $this->acmeRow()['active_members_count']);
        $this->assertSame(1, $this->acmeRow()['members_to_promote_count']);
        $this->assertSame([$member->id], $this->searchMembers()->json('members.*.id'));
        $this->assertSame([], $this->searchMembers($this->platformAdmin->email)->json('members'));
    }

    public function test_a_company_whose_only_active_member_is_the_platform_admin_can_still_get_a_first_admin()
    {
        $own = Membership::factory()->create(['company_id' => $this->acme->id, 'user_id' => $this->platformAdmin->id]);

        $this->assertNotNull($this->acmeRow()['first_admin_invitation']);
        $this->assertSame(0, $this->acmeRow()['members_to_promote_count']);
        $this->promote($own->id)->assertSessionHasErrors('membership_id');

        // Not themself: the platform doesn't run companies.
        $this->sendFirstAdmin('Me', $this->platformAdmin->email)->assertSessionHasErrors('admin_email');
        $this->assertSame(Role::Viewer, $own->refresh()->role);

        $this->sendFirstAdmin('Anna Admin', 'anna@example.com')->assertSessionHasNoErrors();
        $this->assertSame('anna@example.com', $this->acme->pendingAdminInvitation()->sole()->known_email);
    }

    public function test_choosing_a_member_needs_one()
    {
        $this->actingAs($this->platformAdmin)
            ->from(route('platform.companies.index'))
            ->post(route('platform.companies.promote-member', $this->acme), ['membership_id' => 'anyone'])
            ->assertSessionHasErrors('membership_id');
    }

    public function test_only_platform_admins_can_use_it()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('platform.companies.first-admin', $this->acme), ['admin_name' => 'X', 'admin_email' => 'x@example.com'])
            ->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function acmeRow(): array
    {
        return $this->actingAs($this->platformAdmin)
            ->get(route('platform.companies.index', ['search' => 'acme']))
            ->inertiaProps('companies.data.0');
    }

    private function searchMembers(string $search = ''): TestResponse
    {
        return $this->actingAs($this->platformAdmin)
            ->getJson(route('platform.companies.members-to-promote', ['company' => $this->acme, 'search' => $search]))
            ->assertOk();
    }

    private function promote(int $membershipId): TestResponse
    {
        return $this->actingAs($this->platformAdmin)
            ->from(route('platform.companies.index'))
            ->post(route('platform.companies.promote-member', $this->acme), ['membership_id' => $membershipId])
            ->assertRedirect(route('platform.companies.index'));
    }

    private function sendFirstAdmin(string $name, string $email): TestResponse
    {
        return $this->actingAs($this->platformAdmin)
            ->from(route('platform.companies.index'))
            ->post(route('platform.companies.first-admin', $this->acme), ['admin_name' => $name, 'admin_email' => $email])
            ->assertRedirect(route('platform.companies.index'));
    }
}
