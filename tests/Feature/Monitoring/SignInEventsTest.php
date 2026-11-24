<?php

namespace Tests\Feature\Monitoring;

use App\Companies\CurrentCompany;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\ReadsSecurityLog;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SignInEventsTest extends TestCase
{
    use ReadsSecurityLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sign-in pages open no company.
        app(CurrentCompany::class)->clear();
        $this->captureLog();
    }

    public function test_a_password_sign_in_is_logged_with_the_user_and_the_request()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
        $line = $this->securityLine('auth.signed_in');
        $this->assertSame('INFO', $line['level_name']);
        $this->assertSame(['event' => 'auth.signed_in', 'user_id' => $user->id, 'company_id' => null, 'method' => 'password'], $line['context']);
        $this->assertSame([
            'request_id' => $response->headers->get('X-Request-Id'),
            'ip' => '127.0.0.1',
            'method' => 'POST',
            'route' => 'login.store',
        ], $line['extra']);
        $this->assertSecurityLogLeaves([$user->email]);
    }

    public function test_a_failed_sign_in_reads_the_same_for_a_known_and_an_unknown_address()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password-1'])->assertSessionHasErrors('email');
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-password-2'])->assertSessionHasErrors('email');

        [$known, $unknown] = $this->securityLines();
        $this->assertSame(['auth.sign_in_failed', 'auth.sign_in_failed'], $this->securityEvents());
        $this->assertSame('WARNING', $known['level_name']);
        $this->assertNull($known['context']['user_id']);
        $this->assertNotSame($known['extra']['request_id'], $unknown['extra']['request_id']);
        $this->assertSame($this->withoutTimeAndRequestId($known), $this->withoutTimeAndRequestId($unknown));
        $this->assertSecurityLogLeaves(['wrong-password', 'nobody']);
    }

    public function test_too_many_failed_sign_ins_are_logged_as_a_lockout_without_naming_anyone()
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $ignored) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->assertContains('auth.lockout', $this->securityEvents());
        $lockout = $this->securityLines()[array_search('auth.lockout', $this->securityEvents(), true)];
        $this->assertSame('WARNING', $lockout['level_name']);
        $this->assertNull($lockout['context']['user_id']);
        $this->assertSame('login.store', $lockout['extra']['route']);
        $this->assertSecurityLogLeaves(['wrong-password']);
    }

    public function test_a_two_factor_sign_in_a_wrong_code_and_a_used_recovery_code_are_logged()
    {
        $user = $this->userWithTwoFactor();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), ['code' => '000000']);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1']);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(['auth.two_factor_failed', 'auth.recovery_code_used', 'auth.signed_in'], $this->securityEvents());
        $this->assertSame('WARNING', $this->securityLine('auth.two_factor_failed')['level_name']);
        $this->assertSame($user->id, $this->securityLine('auth.two_factor_failed')['context']['user_id']);
        $this->assertSame($user->id, $this->securityLine('auth.recovery_code_used')['context']['user_id']);
        $this->assertSame('two_factor', $this->securityLine('auth.signed_in')['context']['method']);
        $this->assertSecurityLogLeaves(['recovery-code-1', '000000']);
    }

    public function test_too_many_two_factor_codes_are_logged_as_a_lockout_naming_the_account()
    {
        $user = $this->userWithTwoFactor();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        foreach (range(1, 5) as $ignored) {
            $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertRedirect();
        }
        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertTooManyRequests();

        $lockout = $this->securityLine('auth.lockout');
        $this->assertSame($user->id, $lockout['context']['user_id']);
        $this->assertSame('two-factor.login.store', $lockout['extra']['route']);
    }

    public function test_a_passkey_sign_in_is_logged_as_one()
    {
        $user = User::factory()->create();
        $request = Request::create('/passkeys/login', 'POST');
        $request->setRouteResolver(fn () => Route::getRoutes()->getByName('passkey.login'));
        $this->app->instance('request', $request);

        event(new Login('web', $user, false));

        $this->assertSame(['event' => 'auth.signed_in', 'user_id' => $user->id, 'company_id' => null, 'method' => 'passkey'], $this->securityLine('auth.signed_in')['context']);
    }

    public function test_signing_out_is_logged()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'));

        $this->assertGuest();
        $this->assertSame($user->id, $this->securityLine('auth.signed_out')['context']['user_id']);
    }

    /** With a secret a real code can be checked against (the factory's is too short for that). */
    private function userWithTwoFactor(): User
    {
        return User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey())]);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function withoutTimeAndRequestId(array $line): array
    {
        unset($line['datetime'], $line['extra']['request_id']);

        return $line;
    }
}
