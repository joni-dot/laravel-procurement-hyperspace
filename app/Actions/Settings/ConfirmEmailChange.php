<?php

namespace App\Actions\Settings;

use App\Models\User;
use App\Monitoring\SecurityEvents;
use App\Notifications\EmailAddressChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Switches the account to the address its owner has just proved to control, and tells the old address.
 * Returns false when the link is for an address the person no longer wants, or one another account
 * has taken meanwhile.
 */
class ConfirmEmailChange
{
    public function handle(User $user, string $email): bool
    {
        $oldEmail = $user->email;

        $changed = DB::transaction(function () use ($user, $email): bool {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($user->pending_email !== $email || User::query()->where('email', $email)->exists()) {
                return false;
            }

            $user->forceFill([
                'email' => $email,
                'email_verified_at' => now(),
                'pending_email' => null,
            ])->save();

            return true;
        });

        if ($changed) {
            $user->refresh();
            SecurityEvents::record('account.email_changed', ['user_id' => $user->id]);
            Notification::route('mail', $oldEmail)->notify(new EmailAddressChanged($email));
        }

        return $changed;
    }
}
