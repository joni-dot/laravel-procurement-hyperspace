<?php

namespace Tests\Feature\Settings;

use App\Actions\Companies\InviteMember;
use App\Actions\Settings\RequestEmailChange;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\EmailAddressChanged;
use App\Notifications\EmailChangeLink;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_a_new_email_address_waits_until_its_link_is_opened()
    {
        Notification::fake();
        config(['app.url' => 'https://hyperspace.example.com']);
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Test User', 'email' => 'NEW@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('Test User', $user->name);
        $this->assertSame('old@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('new@example.com', $user->pending_email);

        $link = $this->sentLinkFor('new@example.com');
        $this->assertStringStartsWith('https://hyperspace.example.com/settings/profile/email?', $link);
        Notification::assertNotSentTo(new AnonymousNotifiable, EmailAddressChanged::class);
    }

    public function test_opening_the_link_changes_the_address_and_tells_the_old_one()
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'old@example.com']);
        $this->requestChange($user, 'new@example.com');

        $this->actingAs($user)->get($this->relative($this->sentLinkFor('new@example.com')))
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->pending_email);
        Notification::assertSentOnDemand(
            EmailAddressChanged::class,
            fn (EmailAddressChanged $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'old@example.com'
                && $notification->newEmail === 'new@example.com',
        );
    }

    public function test_the_link_works_only_for_the_account_that_asked_and_only_unchanged()
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->requestChange($user, 'new@example.com');
        $link = $this->relative($this->sentLinkFor('new@example.com'));

        // Another account waiting for the same address can't use this account's link.
        $other = User::factory()->create();
        $other->forceFill(['pending_email' => 'new@example.com'])->save();
        $this->actingAs($other)->get($link)->assertRedirect(route('profile.edit'));
        $this->assertNotSame('new@example.com', $other->refresh()->email);
        $this->actingAs($user)->get(str_replace('new%40example.com', 'other%40example.com', $link))->assertForbidden();

        $this->travel(RequestEmailChange::LINK_LIFETIME_MINUTES + 1)->minutes();
        $this->actingAs($user)->get($link)->assertForbidden();

        $this->assertSame('old@example.com', $user->refresh()->email);
    }

    public function test_a_newer_request_makes_the_older_link_useless()
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->requestChange($user, 'first@example.com');
        $this->requestChange($user, 'second@example.com');

        $this->actingAs($user)->get($this->relative($this->sentLinkFor('first@example.com')));

        $this->assertSame('old@example.com', $user->refresh()->email);
    }

    public function test_a_taken_address_gets_the_same_answer_but_no_link()
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'taken@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertInertiaFlash('toast.message', 'We sent a link to taken@example.com. Your email address changes when you open it.');

        Notification::assertNothingSent();
        $this->assertSame('old@example.com', $user->refresh()->email);
    }

    public function test_typing_an_invited_address_does_not_collect_its_invitations()
    {
        Notification::fake();
        $attacker = User::factory()->create();
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->requestChange($attacker, 'new.hire@example.com');

        app(InviteMember::class)->handle($company, 'New Hire', 'new.hire@example.com', Role::Admin, $admin);

        $this->assertSame(0, $attacker->memberships()->where('company_id', $company->id)->count());
        $this->assertSame('new.hire@example.com', Membership::query()->where('company_id', $company->id)->whereNull('joined_at')->sole()->user->email);
    }

    private function requestChange(User $user, string $email): void
    {
        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => $email, 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
    }

    private function sentLinkFor(string $email): string
    {
        $link = null;
        Notification::assertSentOnDemand(EmailChangeLink::class, function (EmailChangeLink $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$link) {
            if ($notifiable->routes['mail'] !== $email) {
                return false;
            }
            $link = $notification->confirmationUrl;

            return true;
        });

        return (string) $link;
    }

    private function relative(string $url): string
    {
        return (string) strstr(substr($url, (int) strpos($url, '//') + 2), '/');
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_the_name_can_be_changed_without_the_password()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'New Name', 'email' => strtoupper($user->email)])
            ->assertSessionHasNoErrors();

        $this->assertSame('New Name', $user->refresh()->name);
    }

    public function test_changing_the_email_needs_the_current_password()
    {
        $user = User::factory()->create();
        $email = $user->email;

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'outsider@example.com'])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'outsider@example.com',
                'current_password' => 'not-the-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($email, $user->refresh()->email);
    }

    public function test_nobody_is_told_when_the_email_stays_the_same()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Other Name', 'email' => $user->email]);

        Notification::assertNothingSent();
    }

    public function test_profile_saves_have_their_own_limit()
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $ignored) {
            $this->actingAs($user)->put(route('user-password.update'), [
                'current_password' => 'wrong',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);
        }

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'Still Allowed', 'email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));
    }
}
