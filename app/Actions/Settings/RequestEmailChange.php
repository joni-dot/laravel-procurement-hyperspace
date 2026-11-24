<?php

namespace App\Actions\Settings;

use App\Models\User;
use App\Monitoring\SecurityEvents;
use App\Notifications\EmailChangeLink;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Keeps a new email address waiting until its owner opens the link sent to it. Invitations are tied to
 * the account that holds the invited address, so an address that took effect at once would let anyone
 * collect invitations meant for someone else. The answer is the same whether or not another account
 * already uses the address, so the form doesn't tell who has an account; a taken address gets no link.
 */
class RequestEmailChange
{
    public const int LINK_LIFETIME_MINUTES = 60;

    public function handle(User $user, string $email): void
    {
        $user->forceFill(['pending_email' => $email])->save();
        SecurityEvents::record('account.email_change_requested', ['user_id' => $user->id]);

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        // Built from APP_URL, never from the request's host name.
        $url = rtrim((string) config('app.url'), '/').URL::temporarySignedRoute(
            'profile.email.confirm',
            now()->addMinutes(self::LINK_LIFETIME_MINUTES),
            ['user' => $user->id, 'email' => $email],
            absolute: false,
        );

        Notification::route('mail', $email)->notify(new EmailChangeLink($url));
    }
}
