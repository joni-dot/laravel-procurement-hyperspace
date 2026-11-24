<?php

namespace App\Notifications;

use App\Actions\Settings\RequestEmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the new address when someone asks to change their email. The change happens only when the
 * link is opened, which proves the person controls that address. Encrypted in the queue because the
 * job holds a working link.
 */
class EmailChangeLink extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $confirmationUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        $appName = config('app.name');

        return (new MailMessage)
            ->subject(__('Confirm your new :app email address', ['app' => $appName]))
            ->line(__('Open the link below to use this address for your :app account.', ['app' => $appName]))
            ->action(__('Confirm email address'), $this->confirmationUrl)
            ->line(__('The link works for :minutes minutes. If you didn\'t ask for this, ignore this email.', ['minutes' => RequestEmailChange::LINK_LIFETIME_MINUTES]));
    }
}
