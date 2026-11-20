<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Encrypted in the queue because the job holds a working invitation token.
class UserInvitation extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $companyName,
        private readonly bool $hasAccount,
        private readonly int $expiresInDays,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $replace = ['app' => config('app.name'), 'company' => $this->companyName];

        // The link comes from APP_URL, never from a request's host name, even if the queue runs
        // this right away during the inviter's request.
        $link = rtrim((string) config('app.url'), '/').route('invitation.show', ['token' => $this->token], false);

        return (new MailMessage)
            ->subject(__('You are invited to :company in :app', $replace))
            ->line($this->hasAccount
                ? __('You have been invited to join :company in :app. Sign in with your account to accept.', $replace)
                : __('You have been invited to join :company in :app. Set your password to get started.', $replace))
            ->action($this->hasAccount ? __('Accept invitation') : __('Set password'), $link)
            ->line(__('This link expires in :days days.', ['days' => $this->expiresInDays]));
    }
}
