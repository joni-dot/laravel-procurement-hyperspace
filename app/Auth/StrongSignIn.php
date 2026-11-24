<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Passkeys\Events\PasskeyVerified;

/**
 * Remembers in the session that its person proved a second factor here: a two-factor code at sign-in,
 * confirming two-factor sign-in, or a passkey. Two-factor sign-in being switched on for the account is
 * not enough for the platform pages: a session signed in with the password alone, before it was
 * switched on, would otherwise get in. The session keeps the account's id, so it can't carry over to
 * another account that signs in later in the same browser.
 */
final class StrongSignIn
{
    public const string SESSION_KEY = 'auth.second_factor_passed_by';

    public static function listen(): void
    {
        Event::listen(
            [ValidTwoFactorAuthenticationCodeProvided::class, TwoFactorAuthenticationConfirmed::class, PasskeyVerified::class],
            fn (ValidTwoFactorAuthenticationCodeProvided|TwoFactorAuthenticationConfirmed|PasskeyVerified $event) => self::remember($event->user),
        );
    }

    public static function remember(Authenticatable $user): void
    {
        session()->put(self::SESSION_KEY, $user->getAuthIdentifier());
    }

    public static function passedBy(Authenticatable $user): bool
    {
        return session(self::SESSION_KEY) === $user->getAuthIdentifier();
    }
}
