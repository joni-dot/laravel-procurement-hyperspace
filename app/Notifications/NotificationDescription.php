<?php

namespace App\Notifications;

/**
 * What a person reads about a notification: the sentence and where it comes from, such as the project's name.
 */
final readonly class NotificationDescription
{
    public function __construct(
        public NotificationMessage $message,
        public string $source,
    ) {}
}
