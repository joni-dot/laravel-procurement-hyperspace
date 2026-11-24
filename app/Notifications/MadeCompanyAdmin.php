<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a member that the service's support made them their company's admin, because the company had
 * none left.
 */
class MadeCompanyAdmin extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        private readonly string $companySlug,
    ) {
        $this->afterCommit();
    }

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
        // From APP_URL, never from a request's host name.
        $link = rtrim((string) config('app.url'), '/').route('company.home', ['company' => $this->companySlug], false);

        return (new MailMessage)
            ->subject(__('You are now the admin of :company in :app', $replace))
            ->line(__(':company had no admin left, so the :app support team made you its admin. You can now invite people and manage the company\'s settings.', $replace))
            ->action(__('Open :company', $replace), $link)
            ->line(__('If you didn\'t expect this, tell your colleagues.'));
    }
}
