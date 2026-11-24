<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SignInLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_sign_in_takes_as_long_for_a_known_address_as_for_an_unknown_one()
    {
        config(['auth.timebox_duration' => 300_000]);
        $user = User::factory()->create();

        foreach ([$user->email, 'nobody@example.com'] as $email) {
            $started = microtime(true);
            $this->post(route('login.store'), ['email' => $email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');

            $this->assertGreaterThanOrEqual(0.3, microtime(true) - $started, $email);
        }
        $this->assertGuest();
    }

    public function test_a_correct_password_is_not_held_back()
    {
        config(['auth.timebox_duration' => 2_000_000]);
        $user = User::factory()->create();

        $started = microtime(true);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    public function test_sign_in_with_a_list_instead_of_an_address_is_an_ordinary_failure()
    {
        $this->post(route('login.store'), ['email' => ['a@example.com'], 'password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_confirming_the_password_is_limited_per_account()
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong'])
                ->assertSessionHasErrors('password');
        }

        $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertSessionHasErrors('password');
        $this->assertStringStartsWith('Too many attempts.', session('errors')->first('password'));
        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_confirming_the_password_is_also_limited_per_hour()
    {
        $user = User::factory()->create();

        foreach (range(1, 4) as $ignored) {
            foreach (range(1, 5) as $ignoredToo) {
                $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong']);
            }
            $this->travel(61)->seconds();
        }

        $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertSessionHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_two_factor_codes_are_also_limited_per_hour()
    {
        // A real secret, so a wrong code is checked and refused like at a real sign-in.
        $user = User::factory()->withTwoFactor()->create([
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
        ]);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        foreach (range(1, 4) as $ignored) {
            foreach (range(1, 5) as $ignoredToo) {
                $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertRedirect();
            }
            $this->travel(61)->seconds();
        }

        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertTooManyRequests();
    }

    public function test_passkey_sign_in_is_limited_per_session_whatever_key_is_named()
    {
        $browser = $this->browserSession();

        foreach (range(1, 10) as $attempt) {
            $browser->postJson(route('passkey.login'), ['credential' => ['id' => "made-up-{$attempt}"]]);
        }

        $browser->postJson(route('passkey.login'), ['credential' => ['id' => 'one-more']])->assertTooManyRequests();
    }

    public function test_passkey_requests_are_counted_per_person_so_an_office_on_one_address_is_not_blocked()
    {
        foreach (range(1, 12) as $colleague) {
            $this->browserSession()->getJson(route('passkey.login-options'))->assertOk();
        }

        $browser = $this->browserSession();
        foreach (range(1, 10) as $attempt) {
            $browser->getJson(route('passkey.login-options'))->assertOk();
        }
        $browser->getJson(route('passkey.login-options'))->assertTooManyRequests();
    }

    public function test_new_sessions_from_one_address_still_meet_a_limit()
    {
        foreach (range(1, 100) as $session) {
            $this->getJson(route('passkey.login-options'))->assertOk();
        }

        $this->getJson(route('passkey.login-options'))->assertTooManyRequests();
    }

    public function test_failed_sign_ins_from_one_visitor_are_limited_whatever_addresses_they_try()
    {
        config(['auth.timebox_duration' => 1_000]);

        foreach (range(1, FortifyServiceProvider::FAILED_SIGN_INS_PER_VISITOR_PER_MINUTE) as $attempt) {
            $this->post(route('login.store'), ['email' => "made-up-{$attempt}@example.com", 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => __('These credentials do not match our records.')]);
        }

        // Refused at once, without holding the server for the usual wait.
        config(['auth.timebox_duration' => 2_000_000]);
        $started = microtime(true);
        $this->post(route('login.store'), ['email' => 'one-more@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertStringStartsWith('Too many login attempts.', session('errors')->first('email'));
        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    public function test_successful_sign_ins_do_not_count_towards_the_visitor_limit()
    {
        config(['auth.timebox_duration' => 1_000]);

        foreach (User::factory()->count(FortifyServiceProvider::FAILED_SIGN_INS_PER_VISITOR_PER_MINUTE + 5)->create() as $colleague) {
            $this->post(route('login.store'), ['email' => $colleague->email, 'password' => 'password']);
            $this->assertAuthenticatedAs($colleague);
            $this->post(route('logout'));
        }

        $this->post(route('login.store'), ['email' => 'typo@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => __('These credentials do not match our records.')]);
    }

    public function test_the_two_factor_secret_is_shown_only_while_setting_it_up()
    {
        $confirmed = User::factory()->withTwoFactor()->create();
        $settingUp = User::factory()->withTwoFactor()->create(['two_factor_confirmed_at' => null]);

        foreach (['two-factor.secret-key', 'two-factor.qr-code'] as $route) {
            $this->actingAs($confirmed)->withSession(['auth.password_confirmed_at' => time()])
                ->getJson(route($route))->assertNotFound();
            $this->actingAs($settingUp)->withSession(['auth.password_confirmed_at' => time()])
                ->getJson(route($route))->assertOk();
        }
    }

    public function test_each_limit_counts_only_its_own_requests()
    {
        foreach (range(1, 20) as $ignored) {
            $this->get(route('invitation.show', ['token' => 'unknown']));
        }
        $this->get(route('invitation.show', ['token' => 'unknown']))->assertTooManyRequests();

        $this->post(route('invitation.store'), ['token' => 'unknown'])->assertStatus(302);
    }

    public function test_sign_in_guesses_from_one_ipv6_block_share_one_limit()
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "2001:db8:1:2::{$attempt}"])
                ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::99'])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertTooManyRequests();
        $this->assertGuest();
    }

    /**
     * Sends the same session cookie with every request, as a browser does. (Without it, each test
     * request starts a new session.)
     */
    private function browserSession(): static
    {
        return $this->withCredentials()->withCookie(config('session.cookie'), Str::random(40));
    }
}
