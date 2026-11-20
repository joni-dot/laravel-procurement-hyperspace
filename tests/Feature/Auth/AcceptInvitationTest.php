<?php

namespace Tests\Feature\Auth;

use App\Actions\Companies\InviteMember;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class AcceptInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const string NEW_PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_the_invitation_email_links_to_the_app_address_not_the_request_host()
    {
        config(['app.url' => 'https://app.example.com']);

        $this->actingAs(User::factory()->admin()->create())
            ->post("http://attacker.example/{$this->company->slug}/admin/users", ['name' => 'Nina', 'email' => 'new@example.com', 'role' => 'viewer'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $user = User::firstWhere('email', 'new@example.com');
        Notification::assertSentTo($user, UserInvitation::class, fn (UserInvitation $invitation) => preg_match(
            '#^https://app\.example\.com/invitation/[A-Za-z0-9]{64}$#',
            $invitation->toMail($user)->actionUrl,
        ) === 1);
    }

    public function test_a_new_account_sees_the_set_password_form_with_its_name()
    {
        $link = $this->invite('new@example.com', 'Nina New');

        $this->get($link)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/AcceptInvitation')
                ->where('email', 'new@example.com')
                ->where('name', 'Nina New')
            );
    }

    public function test_the_form_offers_the_name_this_invitation_used_not_another_companys()
    {
        $this->invite('kim@example.com', 'Kimmo (Acme contractor)');
        $this->enterCompany(Company::factory()->create());
        $membership = app(InviteMember::class)->handle($this->company, 'Kim Virtanen', 'kim@example.com', Role::Viewer);
        $link = Notification::sent($membership->user, UserInvitation::class)->last()->toMail($membership->user)->actionUrl;

        $this->get($link)->assertInertia(fn (AssertableInertia $page) => $page->where('name', 'Kim Virtanen'));
    }

    public function test_a_new_account_sets_its_password_joins_and_is_signed_in()
    {
        $link = $this->invite('new@example.com', 'Nina New');

        $this->post(route('invitation.store'), $this->setPasswordInput($link, name: 'Nina Chosen'))
            ->assertRedirect(route('dashboard', ['company' => $this->company->slug]));

        $user = User::firstWhere('email', 'new@example.com');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Nina Chosen', $user->name);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $membership = $user->memberships()->sole();
        $this->assertTrue($membership->isActive());
        $this->assertNull($membership->invitation_token);

        $this->get(route('dashboard', ['company' => $this->company->slug]))->assertOk();
    }

    public function test_an_invitation_link_works_only_once()
    {
        $link = $this->invite('new@example.com');

        $this->post(route('invitation.store'), $this->setPasswordInput($link));
        auth()->logout();

        $this->post(route('invitation.store'), $this->setPasswordInput($link, password: 'another-password-123'))
            ->assertSessionHasErrors('token');
        $this->assertInvalid($link);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, User::firstWhere('email', 'new@example.com')->password));
    }

    public function test_expired_used_and_unknown_links_get_the_same_answer()
    {
        $expired = $this->invite('expired@example.com');
        $this->travel(3)->days();
        $this->travel(1)->minute();
        $used = $this->invite('used@example.com');
        $this->post(route('invitation.store'), $this->setPasswordInput($used));
        auth()->logout();
        $unknown = route('invitation.show', ['token' => str_repeat('a', 64)]);

        $answers = array_map(fn (string $link) => $this->get($link)->assertOk()->inertiaPage()['props'], [$expired, $used, $unknown]);

        $this->assertSame(['reason' => 'invalid'], array_intersect_key($answers[0], ['reason' => true]));
        $this->assertEquals($answers[0], $answers[1]);
        $this->assertEquals($answers[0], $answers[2]);

        $this->post(route('invitation.store'), $this->setPasswordInput($expired))->assertSessionHasErrors('token');
        $this->assertNull(User::firstWhere('email', 'expired@example.com')->password);
    }

    public function test_a_withdrawn_invitation_or_a_blocked_account_cannot_use_the_link()
    {
        $withdrawn = $this->invite('withdrawn@example.com');
        Membership::query()->update(['deactivated_at' => now()]);
        $this->assertInvalid($withdrawn);

        $blocked = $this->invite('blocked@example.com');
        User::query()->where('email', 'blocked@example.com')->update(['deactivated_at' => now()]);
        $this->assertInvalid($blocked);
        $this->post(route('invitation.store'), $this->setPasswordInput($blocked))->assertSessionHasErrors('token');
    }

    public function test_a_password_reset_token_cannot_be_used_as_an_invitation()
    {
        $this->invite('new@example.com');
        $resetToken = app('auth.password.broker')->createToken(User::firstWhere('email', 'new@example.com'));

        $this->assertInvalid(route('invitation.show', ['token' => $resetToken]));
        $this->post(route('invitation.store'), $this->setPasswordInput(route('invitation.show', ['token' => $resetToken])))
            ->assertSessionHasErrors('token');
    }

    public function test_passwords_must_match()
    {
        $link = $this->invite('new@example.com');

        $this->post(route('invitation.store'), [
            ...$this->setPasswordInput($link),
            'password_confirmation' => 'something-else',
        ])->assertSessionHasErrors('password');
    }

    public function test_invited_people_cannot_sign_in_before_setting_a_password()
    {
        $this->invite('new@example.com');

        $this->post(route('login.store'), ['email' => 'new@example.com', 'password' => '']);
        $this->post(route('login.store'), ['email' => 'new@example.com', 'password' => 'anything']);

        $this->assertGuest();
    }

    public function test_an_existing_account_signs_in_first_and_comes_back_to_the_invitation()
    {
        $person = User::factory()->withoutCompany()->create(['email' => 'kim@example.com']);
        $link = $this->invite('kim@example.com', role: Role::Buyer);

        $this->get($link)->assertRedirect(route('login'));

        $this->post(route('login.store'), ['email' => 'kim@example.com', 'password' => 'password'])
            ->assertRedirect($link);

        $this->get($link)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/JoinCompany')
                ->where('invitation.companyName', $this->company->name)
                ->where('invitation.role', 'Buyer')
                ->where('invitation.id', $person->memberships()->sole()->id)
            );
    }

    public function test_an_existing_account_is_never_given_a_password_through_the_link()
    {
        $person = User::factory()->withoutCompany()->create(['email' => 'kim@example.com']);
        $link = $this->invite('kim@example.com');

        $this->post(route('invitation.store'), $this->setPasswordInput($link))->assertSessionHasErrors('token');

        $this->assertTrue(Hash::check('password', $person->refresh()->password));
        $this->assertNull($person->memberships()->sole()->joined_at);
        $this->assertGuest();
    }

    public function test_an_existing_account_accepts_from_the_link_page()
    {
        $person = User::factory()->withoutCompany()->create(['email' => 'kim@example.com']);
        $link = $this->invite('kim@example.com');
        $invitationId = $this->actingAs($person)->get($link)->inertiaProps('invitation.id');

        $this->post(route('invitations.accept', ['invitation' => $invitationId]))
            ->assertRedirect(route('dashboard', ['company' => $this->company->slug]));

        $this->assertTrue($person->memberships()->sole()->isActive());
        $this->assertInvalid($link);
    }

    public function test_an_existing_account_declines_from_the_link_page()
    {
        $person = User::factory()->withoutCompany()->create(['email' => 'kim@example.com']);
        $link = $this->invite('kim@example.com');
        $invitationId = $this->actingAs($person)->get($link)->inertiaProps('invitation.id');

        $this->post(route('invitations.decline', ['invitation' => $invitationId]))
            ->assertRedirect(route('invitations.index'));

        $this->assertSame(0, $person->memberships()->count());
        $this->assertNotNull($person->fresh());
    }

    public function test_signed_in_as_another_account_the_link_says_so_without_naming_the_invited_address()
    {
        $link = $this->invite('new@example.com', 'Nina New');
        User::factory()->withoutCompany()->create(['email' => 'kim@example.com']);
        $someoneElse = User::factory()->create();

        $this->actingAs($someoneElse)
            ->get($link)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/InvitationUnavailable')
                ->where('reason', 'another-account'))
            ->assertDontSee('new@example.com')
            ->assertDontSee('Nina New');

        $this->post(route('invitation.store'), $this->setPasswordInput($link))->assertSessionHasErrors('token');
        $this->assertNull(User::firstWhere('email', 'new@example.com')->password);
        $this->assertAuthenticatedAs($someoneElse);

        $existingLink = $this->invite('kim@example.com');
        $this->actingAs($someoneElse)->get($existingLink)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reason', 'another-account'))
            ->assertDontSee('kim@example.com');
    }

    private function invite(string $email, string $name = 'Invited Person', Role $role = Role::Viewer): string
    {
        $membership = app(InviteMember::class)->handle($this->company, $name, $email, $role);
        $link = null;

        Notification::assertSentTo($membership->user, UserInvitation::class, function (UserInvitation $invitation) use ($membership, &$link): bool {
            $link = $invitation->toMail($membership->user)->actionUrl;

            return true;
        });

        return $link;
    }

    private function assertInvalid(string $link): void
    {
        $this->get($link)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/InvitationUnavailable')
                ->where('reason', 'invalid'));
    }

    /**
     * @return array<string, string>
     */
    private function setPasswordInput(string $link, string $name = 'Invited Person', string $password = self::NEW_PASSWORD): array
    {
        return [
            'token' => basename($link),
            'name' => $name,
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }
}
