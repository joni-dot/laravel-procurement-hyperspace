<?php

namespace App\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One or more kinds of in-app notification that a module stores, registered in NotificationKinds. Core shows,
 * counts, opens and emails every notification through it, without knowing the module's code.
 */
interface NotificationKind
{
    /**
     * The stored types (notifications.type) this kind owns, such as "package_assigned".
     *
     * @return list<string>
     */
    public function types(): array;

    /**
     * Narrows a query of this kind's notifications to those the person may still see now, inside the
     * current company. Their own notifications only is already taken care of. Use only SQL and the person:
     * the unread count runs this on every page.
     *
     * @param  Builder<Notification>  $query
     */
    public function onlyVisibleTo(Builder $query, User $user): void;

    /**
     * The sentence and its source (such as the project's name) of each notification, by id. Called once per
     * page or email with all of this kind's notifications there, so it can load what it needs in one query.
     *
     * @param  Collection<int, Notification>  $notifications
     * @return array<string, NotificationDescription>
     */
    public function describe(Collection $notifications): array;

    /**
     * Where opening the notification leads: a path inside the current company, without the host.
     */
    public function destination(Notification $notification): string;
}
