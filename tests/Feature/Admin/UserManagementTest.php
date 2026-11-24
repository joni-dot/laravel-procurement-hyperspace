<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function adminOnlyRoutes(): array
    {
        return [
            'list users' => ['get', 'admin.users.index'],
            'invite user' => ['post', 'admin.users.store'],
            'change role' => ['patch', 'admin.users.update'],
            'deactivate' => ['post', 'admin.users.deactivate'],
            'reactivate' => ['post', 'admin.users.reactivate'],
            'resend invitation' => ['post', 'admin.users.invitation.resend'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function membershipRoutes(): array
    {
        return array_diff_key(self::adminOnlyRoutes(), array_flip(['list users', 'invite user']));
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_guests_are_sent_to_login(string $method, string $route)
    {
        $target = Membership::factory()->invited()->create(['company_id' => $this->company->id]);

        $this->call($method, route($route, ['membership' => $target->id]))->assertRedirect(route('login'));
    }

    #[DataProvider('adminOnlyRoutes')]
    public function test_buyers_and_viewers_are_forbidden(string $method, string $route)
    {
        $target = Membership::factory()->invited()->deactivated()->create(['company_id' => $this->company->id]);

        foreach ([User::factory()->buyer()->create(), User::factory()->create()] as $user) {
            $this->actingAs($user)
                ->call($method, route($route, ['membership' => $target->id]), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'admin'])
                ->assertForbidden();
        }

        $this->assertSame(Role::Viewer, $target->refresh()->role);
    }

    public function test_the_list_shows_members_invitations_and_deactivated_members_with_their_status()
    {
        $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
        $this->inviteAs($admin, 'Ivan Invited', 'ivan@example.com');
        $expired = $this->inviteAs($admin, 'Erin Expired', 'erin@example.com');
        $expired->forceFill(['invitation_sent_at' => now()->subDays(4)])->save();
        User::factory()->create(['name' => 'Dan Deactivated'])->memberships()
            ->update(['deactivated_at' => now(), 'known_name' => 'Dan Deactivated', 'known_email' => 'dan@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('$2y$', escape: false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/users/Index')
                ->has('users.data', 4)
                ->where('users.data.0.name', 'Ada Admin')
                ->where('users.data.0.status', 'active')
                ->where('users.data.0.isYou', true)
                ->where('users.data.0.can', ['update' => false, 'deactivate' => false, 'reactivate' => false, 'resendInvitation' => false])
                ->where('users.data.1.name', 'Dan Deactivated')
                ->where('users.data.1.status', 'deactivated')
                ->where('users.data.1.can.reactivate', true)
                ->where('users.data.2.name', 'Erin Expired')
                ->where('users.data.2.status', 'invitation_expired')
                ->where('users.data.2.can.resendInvitation', true)
                ->where('users.data.3.name', 'Ivan Invited')
                ->where('users.data.3.email', 'ivan@example.com')
                ->where('users.data.3.status', 'invited')
                ->missing('users.data.0.password')
                ->missing('users.data.3.invitation_token')
            );
    }

    public function test_the_list_shows_only_this_companys_memberships()
    {
        $admin = User::factory()->admin()->create();
        $elsewhere = Company::factory()->create();
        Membership::factory()->create(['company_id' => $elsewhere->id, 'user_id' => User::factory()->withoutCompany()->create(['name' => 'Other Company Person'])->id]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 1))
            ->assertDontSee('Other Company Person');
    }

    public function test_an_invited_persons_own_account_name_and_address_never_show()
    {
        $admin = User::factory()->admin()->create();
        $existing = User::factory()->withoutCompany()->create(['name' => 'Real Account Name', 'email' => 'person@example.com']);
        $this->inviteAs($admin, 'Name The Admin Typed', 'person@example.com');
        // The person changes their address before accepting; that one is theirs, not the company's.
        $existing->update(['email' => 'private@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertSee('Name The Admin Typed')
            ->assertSee('person@example.com')
            ->assertDontSee('Real Account Name')
            ->assertDontSee('private@example.com');

        // Searching for the hidden name finds nothing either.
        $this->get(route('admin.users.index', ['search' => 'Real Account']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 0));
    }

    public function test_after_joining_the_list_shows_the_persons_own_name()
    {
        $admin = User::factory()->admin()->create();
        User::factory()->withoutCompany()->create(['name' => 'Real Account Name', 'email' => 'person@example.com']);
        $membership = $this->inviteAs($admin, 'Name The Admin Typed', 'person@example.com');
        $membership->forceFill(['joined_at' => now(), 'invitation_token' => null])->save();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertSee('Real Account Name')
            ->assertDontSee('Name The Admin Typed');
    }

    public function test_a_platform_admin_who_joined_is_marked()
    {
        $admin = User::factory()->admin()->create();
        $platformAdmin = User::factory()->platformAdmin()->create(['name' => 'Aaron Platform']);
        Membership::factory()->create(['company_id' => $this->company->id, 'user_id' => $platformAdmin->id, 'role' => Role::Admin]);
        Membership::factory()->invited()->create([
            'company_id' => $this->company->id,
            'user_id' => User::factory()->platformAdmin()->withoutCompany()->create()->id,
            'known_name' => 'Aaron Invited',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['search' => 'Aaron']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('users.data.0.name', 'Aaron Invited')
                ->where('users.data.0.isPlatformAdmin', false)
                ->where('users.data.1.name', 'Aaron Platform')
                ->where('users.data.1.isPlatformAdmin', true));

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['search' => $admin->name]))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('users.data.0.isPlatformAdmin', false));
    }

    public function test_admin_can_search_by_name_or_email()
    {
        $admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'ada@example.com']);
        User::factory()->create(['name' => 'Bob Buyer', 'email' => 'bob@supplies.test']);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['search' => 'SUPPLIES']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.name', 'Bob Buyer')
                ->where('filters.search', 'SUPPLIES')
            );
    }

    public function test_the_list_pages_by_25_and_its_queries_do_not_grow_with_members()
    {
        $admin = User::factory()->admin()->create(['name' => 'Aaron Admin']);
        User::factory()->count(3)->create();
        // The first visit also remembers the company as the admin's last one.
        $this->actingAs($admin)->get(route('admin.users.index'));
        $fewMembers = $this->countQueries(fn () => $this->actingAs($admin)->get(route('admin.users.index')));

        User::factory()->count(30)->create();
        Membership::factory()->invited()->count(5)->create(['company_id' => $this->company->id]);
        $manyMembers = $this->countQueries(fn () => $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 25)->where('users.total', 39)));

        $this->assertSame($fewMembers, $manyMembers);
    }

    public function test_inviting_a_new_address_and_an_existing_account_look_the_same_to_the_inviter()
    {
        $admin = User::factory()->admin()->create();
        $existing = User::factory()->withoutCompany()->create(['name' => 'Kim Existing', 'email' => 'kim@example.com']);

        foreach (['Nina New' => 'nina@example.com', 'Kim Typed' => 'kim@example.com'] as $name => $email) {
            $this->actingAs($admin)
                ->post(route('admin.users.store'), ['name' => $name, 'email' => strtoupper($email), 'role' => 'buyer'])
                ->assertRedirect(route('admin.users.index'))
                ->assertSessionHasNoErrors();
            $this->assertSame(['type' => 'success', 'message' => "Invitation sent to {$email}."], session(SessionKey::FLASH_DATA)['toast']);
        }

        $rows = collect($this->actingAs($admin)->get(route('admin.users.index'))->inertiaProps('users.data'))
            ->keyBy('email')
            ->map(fn (array $row) => [...$row, 'id' => null]);
        $this->assertEquals(
            [...$rows['nina@example.com'], 'name' => 'Kim Typed', 'email' => 'kim@example.com'],
            $rows['kim@example.com'],
        );
        $this->assertSame('Nina New', $rows['nina@example.com']['name']);
        $this->assertSame('invited', $rows['nina@example.com']['status']);

        // The person gets the email that fits: set a password, or sign in to accept.
        $newUser = User::firstWhere('email', 'nina@example.com');
        $this->assertNull($newUser->password);
        Notification::assertSentTo($newUser, UserInvitation::class, fn (UserInvitation $mail) => $mail->toMail($newUser)->actionText === 'Set password');
        Notification::assertSentTo($existing, UserInvitation::class, fn (UserInvitation $mail) => $mail->toMail($existing)->actionText === 'Accept invitation');

        // An existing account only joins after accepting.
        $this->assertNull($existing->memberships()->sole()->joined_at);
        $this->assertSame('Kim Existing', $existing->refresh()->name);
    }

    public function test_a_former_members_later_account_changes_never_show()
    {
        $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
        $lea = User::factory()->create(['name' => 'Lea Leaving', 'email' => 'lea@example.com']);
        $membership = $lea->memberships()->sole();

        $this->actingAs($admin)->post(route('admin.users.deactivate', $membership))->assertRedirect();
        $lea->update(['name' => 'Lea Elsewhere', 'email' => 'lea@competitor.example']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertDontSee('competitor')
            ->assertDontSee('Lea Elsewhere')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('users.data.1.name', 'Lea Leaving')
                ->where('users.data.1.email', 'lea@example.com'));
        $this->actingAs($admin)
            ->get(route('admin.users.index', ['search' => 'competitor']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('users.data', 0));

        // Back as an active member, the company sees the account as it is now.
        $this->actingAs($admin)->post(route('admin.users.reactivate', $membership))->assertRedirect();
        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('users.data.1.email', 'lea@competitor.example'));
    }

    public function test_inviting_an_address_again_after_its_account_changed_address_keeps_one_invitation()
    {
        $admin = User::factory()->admin()->create();
        $existing = User::factory()->withoutCompany()->create(['email' => 'x@example.com']);
        $this->inviteAs($admin, 'X Person', 'x@example.com');
        $existing->update(['email' => 'x2@example.com']);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'X Person', 'email' => 'x@example.com', 'role' => 'viewer'])
            ->assertSessionHasNoErrors();

        // A second row (and a second account) would tell the inviter an account was behind the first.
        $this->assertSame(1, Membership::query()->where('company_id', $this->company->id)->where('known_email', 'x@example.com')->count());
        $this->assertFalse(User::query()->where('email', 'x@example.com')->exists());
    }

    public function test_inviting_someone_with_a_pending_invitation_sends_it_again()
    {
        $admin = User::factory()->admin()->create();
        $membership = $this->inviteAs($admin, 'Ivan Invited', 'ivan@example.com');
        $oldToken = $membership->invitation_token;

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'Ivan Again', 'email' => 'ivan@example.com', 'role' => 'admin'])
            ->assertSessionHasNoErrors();

        $membership->refresh();
        $this->assertNotSame($oldToken, $membership->invitation_token);
        $this->assertSame(Role::Admin, $membership->role);
        $this->assertSame('Ivan Again', $membership->known_name);
        Notification::assertSentToTimes($membership->user, UserInvitation::class, 2);
    }

    public function test_inviting_a_member_of_this_company_is_refused()
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'member@example.com']);
        User::factory()->create(['email' => 'gone@example.com'])->memberships()->update(['deactivated_at' => now()]);

        foreach (['MEMBER@example.com', 'gone@example.com'] as $email) {
            $this->actingAs($admin)
                ->post(route('admin.users.store'), ['name' => 'X', 'email' => $email, 'role' => 'viewer'])
                ->assertSessionHasErrors(['email' => 'This person is already a member of this company.']);
        }
    }

    public function test_a_member_of_another_company_can_be_invited()
    {
        $admin = User::factory()->admin()->create();
        $elsewhere = Company::factory()->create();
        $person = User::factory()->withoutCompany()->create(['email' => 'busy@example.com']);
        Membership::factory()->create(['company_id' => $elsewhere->id, 'user_id' => $person->id]);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => 'Busy', 'email' => 'busy@example.com', 'role' => 'viewer'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $person->memberships()->count());
    }

    public function test_invite_is_validated_and_ignores_fields_an_admin_should_not_set()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => '', 'email' => 'not-an-address', 'role' => 'superuser'])
            ->assertSessionHasErrors(['name', 'email', 'role']);

        $this->post(route('admin.users.store'), [
            'name' => 'Nina New',
            'email' => 'nina@example.com',
            'role' => 'viewer',
            'password' => 'chosen-by-admin',
            'joined_at' => now()->toDateTimeString(),
        ]);

        $user = User::firstWhere('email', 'nina@example.com');
        $this->assertNull($user->password);
        $this->assertNull($user->memberships()->sole()->joined_at);
    }

    public function test_admin_can_change_a_role_but_not_a_name()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Original']);
        $membership = $user->memberships()->sole();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $membership), ['name' => 'Renamed', 'role' => 'admin'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Role::Admin, $membership->refresh()->role);
        $this->assertSame('Original', $user->refresh()->name);
    }

    public function test_nobody_changes_their_own_role_or_deactivates_themselves()
    {
        $admin = User::factory()->admin()->create();
        $own = $admin->memberships()->sole();

        $this->actingAs($admin)->patch(route('admin.users.update', $own), ['role' => 'viewer'])->assertForbidden();
        $this->actingAs($admin)->post(route('admin.users.deactivate', $own))->assertForbidden();

        $own->refresh();
        $this->assertSame(Role::Admin, $own->role);
        $this->assertNull($own->deactivated_at);
    }

    public function test_the_last_admin_always_stays_because_only_other_admins_can_be_demoted()
    {
        $first = User::factory()->admin()->create();
        $second = User::factory()->admin()->create();

        $this->actingAs($first)->patch(route('admin.users.update', $second->memberships()->sole()), ['role' => 'viewer'])->assertRedirect();
        $this->actingAs($first)->post(route('admin.users.deactivate', $second->memberships()->sole()))->assertRedirect();

        // The one admin left can't remove themselves, and nobody else can.
        $this->actingAs($first)->patch(route('admin.users.update', $first->memberships()->sole()), ['role' => 'viewer'])->assertForbidden();
        $this->assertSame(1, Membership::query()->where('company_id', $this->company->id)->where('role', Role::Admin)->active()->count());
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function changesThatCostAnAdmin(): array
    {
        return [
            'demote' => ['admin.users.update', ['role' => 'viewer']],
            'deactivate' => ['admin.users.deactivate', []],
        ];
    }

    #[DataProvider('changesThatCostAnAdmin')]
    public function test_two_admins_removing_each_other_at_the_same_moment_leave_one_admin(string $route, array $data)
    {
        $first = User::factory()->admin()->create();
        $second = User::factory()->admin()->create();
        $firstMembership = $first->memberships()->sole();

        // The second admin's own request, demoting the first, finishes between this request's
        // permission check and its save.
        Gate::after(function () use ($firstMembership): void {
            Membership::query()->whereKey($firstMembership->id)->update(['role' => Role::Viewer]);
        });

        $method = $route === 'admin.users.update' ? 'patch' : 'post';
        $this->actingAs($first)->{$method}(route($route, $second->memberships()->sole()), $data)->assertForbidden();

        $secondMembership = $second->memberships()->sole();
        $this->assertSame(Role::Admin, $secondMembership->role);
        $this->assertNull($secondMembership->deactivated_at);
    }

    public function test_deactivating_takes_the_person_out_of_this_company_only()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $elsewhere = Company::factory()->create();
        Membership::factory()->create(['company_id' => $elsewhere->id, 'user_id' => $user->id]);

        $this->actingAs($admin)->post(route('admin.users.deactivate', $user->memberships()->where('company_id', $this->company->id)->sole()))->assertRedirect();

        $this->assertNull($user->refresh()->deactivated_at);
        $this->actingAs($user)->get("/{$this->company->slug}/dashboard")->assertNotFound();
        $this->actingAs($user)->get("/{$elsewhere->slug}/dashboard")->assertOk();
    }

    public function test_deactivating_an_invitation_stops_its_link_and_reactivating_lets_it_be_resent()
    {
        $admin = User::factory()->admin()->create();
        $membership = $this->inviteAs($admin, 'Ivan Invited', 'ivan@example.com');

        $this->actingAs($admin)->post(route('admin.users.deactivate', $membership))->assertRedirect();
        $this->assertNull($membership->refresh()->invitation_token);

        $this->post(route('admin.users.reactivate', $membership))->assertRedirect();
        $this->assertSame('invitation_expired', $membership->refresh()->status()->value);

        $this->post(route('admin.users.invitation.resend', $membership))->assertRedirect();
        $this->assertSame('invited', $membership->refresh()->status()->value);
    }

    public function test_admin_can_reactivate_a_member()
    {
        $admin = User::factory()->admin()->create();
        $membership = User::factory()->create()->memberships()->sole();
        $membership->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($admin)->post(route('admin.users.reactivate', $membership))->assertRedirect();

        $this->assertTrue($membership->refresh()->isActive());
    }

    public function test_admin_can_resend_an_invitation_but_not_to_a_member()
    {
        $admin = User::factory()->admin()->create();
        $membership = $this->inviteAs($admin, 'Ivan Invited', 'ivan@example.com');

        $this->actingAs($admin)->post(route('admin.users.invitation.resend', $membership))
            ->assertRedirect();
        Notification::assertSentToTimes($membership->user, UserInvitation::class, 2);

        $this->post(route('admin.users.invitation.resend', User::factory()->create()->memberships()->sole()))
            ->assertForbidden();
    }

    #[DataProvider('membershipRoutes')]
    public function test_another_companys_membership_is_not_found(string $method, string $route)
    {
        $admin = User::factory()->admin()->create();
        $ours = $this->company;

        $theirs = $this->enterCompany(Company::factory()->create());
        $theirAdmin = User::factory()->admin()->create();
        $theirMember = User::factory()->create()->memberships()->sole();
        $theirInvitation = Membership::factory()->invited()->deactivated()->create(['company_id' => $theirs->id]);
        // The other company's admin is a member of ours too; their id must still not reach their company's rows.
        Membership::factory()->create(['company_id' => $ours->id, 'user_id' => $theirAdmin->id]);
        $this->enterCompany($ours);

        foreach ([$theirMember, $theirInvitation, $theirAdmin->memberships()->where('company_id', $theirs->id)->sole()] as $membership) {
            $this->actingAs($admin)
                ->call($method, route($route, ['membership' => $membership->id]), ['role' => 'admin'])
                ->assertNotFound();
        }

        $this->assertSame(Role::Viewer, $theirMember->refresh()->role);
        $this->assertTrue($theirMember->isActive());
        $this->assertNotNull($theirInvitation->refresh()->deactivated_at);
        Notification::assertNothingSent();
    }

    public function test_a_malformed_membership_id_is_not_found()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post("/{$this->company->slug}/admin/users/not-a-number/deactivate")
            ->assertNotFound();
    }

    private function inviteAs(User $admin, string $name, string $email): Membership
    {
        $this->actingAs($admin)
            ->post(route('admin.users.store'), ['name' => $name, 'email' => $email, 'role' => 'viewer'])
            ->assertSessionHasNoErrors();

        return Membership::query()
            ->where('company_id', $this->company->id)
            ->whereHas('user', fn ($users) => $users->where('email', $email))
            ->sole();
    }

    private function countQueries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
