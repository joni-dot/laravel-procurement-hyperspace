<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Ends a person's other sessions when the ways to sign in to their account change: two-factor sign-in
 * turned on or off, a passkey added or removed. A session signed in before then, perhaps with a
 * password someone else had learned, would otherwise stay signed in and could add a sign-in method of
 * its own. (A new password ends other sessions through Laravel's AuthenticateSession.)
 *
 * Each session remembers the account's version from when it signed in; EndOutdatedSessions ends a
 * session whose version is older. The session that made the change moves to the new version, and
 * "remember me" cookies issued before it stop working.
 */
final class SignInVersion
{
    public const string SESSION_KEY = 'auth.sign_in_version';

    public static function listen(): void
    {
        Event::listen(Login::class, fn (Login $event) => self::rememberFor($event->user));

        Event::listen(
            [TwoFactorAuthenticationConfirmed::class, TwoFactorAuthenticationDisabled::class, PasskeyRegistered::class, PasskeyDeleted::class],
            function (TwoFactorAuthenticationConfirmed|TwoFactorAuthenticationDisabled|PasskeyRegistered|PasskeyDeleted $event): void {
                if (! $event instanceof TwoFactorAuthenticationDisabled || self::endedTwoFactorInUse($event)) {
                    self::raise($event->user);
                }
            },
        );
    }

    /**
     * Fortify also sends "disabled" when it clears a setup that was started but never confirmed (each
     * visit to the security page does that). Nobody could sign in with that, so nothing changes.
     */
    public static function endedTwoFactorInUse(TwoFactorAuthenticationDisabled $event): bool
    {
        return $event->user->wasChanged('two_factor_confirmed_at');
    }

    public static function isCurrent(User $user): bool
    {
        $version = session(self::SESSION_KEY);

        // Sessions from before this check existed carry no version yet: they take the current one.
        if ($version === null) {
            self::rememberFor($user);

            return true;
        }

        return $version === (int) $user->sign_in_version;
    }

    private static function raise(mixed $user): void
    {
        if (! $user instanceof User) {
            return;
        }

        $changes = [
            'sign_in_version' => (int) $user->sign_in_version + 1,
            'remember_token' => Str::random(60),
        ];

        // Written with a query rather than save(), so the event's other listeners (the security log)
        // can still tell what the change itself changed (wasChanged).
        $user->newQuery()->whereKey($user->getKey())->update($changes);
        $user->forceFill($changes)->syncOriginalAttributes(array_keys($changes));

        self::rememberFor($user);
    }

    private static function rememberFor(mixed $user): void
    {
        if ($user instanceof User) {
            session()->put(self::SESSION_KEY, (int) $user->sign_in_version);
        }
    }
}
