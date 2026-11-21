<?php

namespace App\Notifications;

use App\Models\Notification as StoredNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The daily summary of one person's new notifications in one company (notifications:send-summaries), newest
 * first. The sentences and links are made when it is queued, inside the company; the links come from APP_URL,
 * never from a request, and open each notification through the app, which checks again that the person may see it.
 */
class NotificationSummary extends Notification implements ShouldQueue
{
    use Queueable;

    /** More than this are counted, not listed. */
    public const int MAX_LISTED = 20;

    /**
     * @var list<array{message: string, source: string, url: string}>
     */
    private readonly array $items;

    private readonly string $listUrl;

    /**
     * @param  Collection<int, StoredNotification>  $notifications  newest first, at most MAX_LISTED
     * @param  array<string, NotificationDescription>  $descriptions  by notification id
     * @param  int  $total  how many new notifications there are, listed or not
     */
    public function __construct(private readonly string $companyName, Collection $notifications, array $descriptions, private readonly int $total)
    {
        $this->afterCommit();

        $this->items = array_values($notifications
            ->filter(fn (StoredNotification $notification) => isset($descriptions[$notification->id]))
            ->map(fn (StoredNotification $notification) => [
                // Typed texts (package titles, project names...) on one line each: a blank line would end the
                // template's HTML block and let the rest be read as Markdown, links included.
                'message' => Str::squish($descriptions[$notification->id]->message->plain()),
                'source' => Str::squish($descriptions[$notification->id]->source),
                'url' => self::appUrl(route('notifications.show', $notification->id, absolute: false)),
            ])
            ->all());

        $this->listUrl = self::appUrl(route('notifications.index', absolute: false));
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
        return (new MailMessage)
            ->subject(trans_choice(':company: :count new notification|:company: :count new notifications', $this->total, ['company' => $this->companyName]))
            ->markdown('mail.notification-summary', [
                'intro' => trans_choice('You have :count new notification in :company:|You have :count new notifications in :company:', $this->total, ['company' => $this->companyName]),
                'items' => $this->items,
                'more' => $this->total - count($this->items),
                'url' => $this->listUrl,
            ]);
    }

    /**
     * @return list<array{message: string, source: string, url: string}>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function listUrl(): string
    {
        return $this->listUrl;
    }

    private static function appUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
