<?php

namespace Tests\Feature\Auth;

use App\Auth\StrongSignIn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Tests\Concerns\ReadsSecurityLog;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SignInChangesTest extends TestCase
{
    use ReadsSecurityLog;
    use RefreshDatabase;

    public function test_other_sessions_end_when_the_ways_to_sign_in_change()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        // Another session of the same person turns on two-factor sign-in.
        DB::table('users')->where('id', $user->id)->increment('sign_in_version');

        // Each request reads the account afresh.
        $this->actingAs($user->fresh())->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_session_making_the_change_stays_and_old_remember_me_cookies_stop_working()
    {
        $user = User::factory()->create(['remember_token' => 'old-token']);
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $changes = [
            fn () => event(new TwoFactorAuthenticationConfirmed($user)),
            function () use ($user): void {
                $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
                app(DisableTwoFactorAuthentication::class)($user);
            },
        ];

        foreach ($changes as $version => $change) {
            $change();

            $user->refresh();
            $this->assertSame($version + 1, $user->sign_in_version);
            $this->assertNotSame('old-token', $user->remember_token);
            $this->actingAs($user)->get(route('profile.edit'))->assertOk();
        }
    }

    public function test_clearing_a_two_factor_setup_that_was_never_confirmed_ends_no_sessions()
    {
        // Started on another device and left unconfirmed; Fortify clears it when the security page opens.
        $user = User::factory()->create([
            'remember_token' => 'old-token',
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => null,
        ]);
        $this->captureLog();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time(), 'two_factor_confirming_at' => now()->subHour()->timestamp])
            ->get(route('security.edit'))
            ->assertOk();

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertSame(0, $user->sign_in_version);
        $this->assertSame('old-token', $user->remember_token);
        $this->assertNotContains('account.two_factor_disabled', $this->securityEvents());
    }

    public function test_with_two_factor_sign_in_only_a_session_that_proved_it_changes_how_to_sign_in()
    {
        $user = User::factory()->withTwoFactor()->create();
        $confirmed = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->withSession($confirmed)->getJson(route('passkey.registration-options'))->assertUnauthorized();
        $this->assertGuest();

        $this->actingAs($user)
            ->withSession([...$confirmed, StrongSignIn::SESSION_KEY => $user->id])
            ->getJson(route('two-factor.recovery-codes'))
            ->assertOk();
    }

    public function test_a_remembered_session_can_open_the_security_page_but_not_see_the_recovery_codes()
    {
        $user = User::factory()->withTwoFactor()->create();
        $confirmed = ['auth.password_confirmed_at' => time()];

        // The page tells the person to sign in again instead of offering the codes.
        $this->actingAs($user)->withSession($confirmed)->get(route('security.edit'))
            ->assertInertia(fn ($page) => $page->where('needsTwoFactorSignIn', true));

        // Looking at the codes is refused, but the person stays signed in.
        $this->actingAs($user)->withSession($confirmed)->getJson(route('two-factor.recovery-codes'))
            ->assertForbidden()
            ->assertJson(['message' => 'Sign in again with your two-factor code to see your recovery codes.']);
        $this->assertAuthenticatedAs($user);

        $this->actingAs($user)->withSession([...$confirmed, StrongSignIn::SESSION_KEY => $user->id])->get(route('security.edit'))
            ->assertInertia(fn ($page) => $page->where('needsTwoFactorSignIn', false));
    }

    public function test_every_form_that_checks_the_current_password_shares_one_limit()
    {
        $user = User::factory()->create();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($user)->put(route('user-password.update'), ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password']);
        }
        foreach (range(1, 2) as $ignored) {
            $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com', 'current_password' => 'wrong']);
        }

        $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertSessionHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));

        $this->actingAs($user)->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertSessionHasErrors('current_password');
    }
}
