/**
 * A piece of a notification's sentence (App\Notifications\NotificationMessage): text, or a "YYYY-MM-DD"
 * date that the page shows in the reader's own format.
 */
export type MessagePart = string | { date: string };

/** One row of the notifications page; the server writes the sentence for every module's kinds. */
export type InAppNotification = {
    id: string;
    message: MessagePart[];
    /** Where it comes from, such as the project's name. */
    source: string;
    isRead: boolean;
    createdAt: string;
};

/** A choice on Settings → Notifications (App\Enums\NotificationEmail). */
export type NotificationEmail = 'daily' | 'off';
