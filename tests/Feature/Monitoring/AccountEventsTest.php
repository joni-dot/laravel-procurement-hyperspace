<?php

namespace Tests\Feature\Monitoring;

use App\Actions\Settings\ConfirmEmailChange;
use App\Actions\Settings\RequestEmailChange;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\ReadsSecurityLog;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Changes to how someone signs in: password, email address, two-factor sign-in and passkeys.
 */
class AccountEventsTest extends TestCase
{
    use ReadsSecurityLog, RefreshDatabase;

    private const string NEW_PASSWORD = 'a-new-password-nobody-guesses';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureLog();
    }

    public function test_a_password_reset_by_link_is_logged_without_the_token_or_the_password()
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email]);
        $token = '';
        Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['event' => 'account.password_reset', 'user_id' => $user->id, 'company_id' => $this->company->id], $this->securityLine('account.password_reset')['context']);
        $this->assertSame('password.update', $this->securityLine('account.password_reset')['extra']['route']);
        $this->assertSecurityLogLeaves([$token, self::NEW_PASSWORD]);
    }

    public function test_a_password_changed_in_the_settings_is_logged()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        $this->assertSame($user->id, $this->securityLine('account.password_changed')['context']['user_id']);
        $this->assertSecurityLogLeaves([self::NEW_PASSWORD]);
    }

    public function test_an_email_change_is_logged_when_asked_for_and_when_confirmed_without_either_address()
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user);

        app(RequestEmailChange::class)->handle($user, 'new.address@example.com');
        app(ConfirmEmailChange::class)->handle($user, 'new.address@example.com');

        $this->assertSame(['account.email_change_requested', 'account.email_changed'], $this->securityEvents());
        $this->assertSame($user->id, $this->securityLine('account.email_changed')['context']['user_id']);
        $this->assertSecurityLogLeaves(['new.address']);
    }

    public function test_turning_two_factor_sign_in_on_and_off_is_logged_without_secrets_or_codes()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        app(EnableTwoFactorAuthentication::class)($user);
        $secret = decrypt($user->refresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        app(ConfirmTwoFactorAuthentication::class)($user, $code);
        $recoveryCodes = $user->refresh()->recoveryCodes();
        app(GenerateNewRecoveryCodes::class)($user);
        app(DisableTwoFactorAuthentication::class)($user);

        $this->assertSame([
            'account.two_factor_enabled',
            'account.two_factor_confirmed',
            'account.recovery_codes_regenerated',
            'account.two_factor_disabled',
        ], $this->securityEvents());
        foreach ($this->securityLines() as $line) {
            $this->assertSame('INFO', $line['level_name']);
            $this->assertSame($user->id, $line['context']['user_id']);
        }
        $this->assertSecurityLogLeaves([$secret, $code, ...$recoveryCodes]);
    }

    public function test_registering_and_deleting_a_passkey_is_logged_by_its_id()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $passkey = Passkey::query()->forceCreate([
            'user_id' => $user->id,
            'name' => 'Work laptop',
            'credential_id' => 'credential-id-secret',
            'credential' => ['made' => 'up'],
        ]);

        PasskeyRegistered::dispatch($user, $passkey);
        app(DeletePasskey::class)($user, $passkey);

        $this->assertSame(['event' => 'account.passkey_registered', 'user_id' => $user->id, 'company_id' => $this->company->id, 'passkey_id' => $passkey->id], $this->securityLine('account.passkey_registered')['context']);
        $this->assertSame($passkey->id, $this->securityLine('account.passkey_deleted')['context']['passkey_id']);
        $this->assertSecurityLogLeaves(['credential-id-secret', 'Work laptop']);
    }
}
