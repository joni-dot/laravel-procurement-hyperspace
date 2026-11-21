<?php

namespace App\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Every kind of in-app notification, registered by the modules while they boot. A notification is shown only
 * when its type belongs to a registered kind and that kind's rule lets the person see it, so a kind whose
 * module is gone, or a person who lost the right to see it, never gets it.
 */
final class NotificationKinds
{
    /** @var array<string, NotificationKind> by stored type */
    private array $kinds = [];

    public function register(NotificationKind $kind): void
    {
        foreach ($kind->types() as $type) {
            if (isset($this->kinds[$type])) {
                throw new InvalidArgumentException("Notification type [{$type}] is already registered.");
            }

            $this->kinds[$type] = $kind;
        }
    }

    public function for(string $type): ?NotificationKind
    {
        return $this->kinds[$type] ?? null;
    }

    /**
     * Keeps the notifications of registered kinds that the person may still see: one OR per kind, so the
     * query stays one query however many modules there are.
     *
     * @param  Builder<Notification>  $query
     */
    public function onlyVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $visible) use ($user): void {
            // No kinds means nothing is visible, never everything.
            $visible->whereRaw('false');

            foreach ($this->distinctKinds() as $kind) {
                $visible->orWhere(function (Builder $ofKind) use ($kind, $user): void {
                    $ofKind->whereIn('type', $kind->types());
                    $kind->onlyVisibleTo($ofKind, $user);
                });
            }
        });
    }

    /**
     * Each notification's sentence and source, by id. One call per kind, so each can load what it needs at once.
     *
     * @param  Collection<int, Notification>  $notifications
     * @return array<string, NotificationDescription>
     */
    public function describe(Collection $notifications): array
    {
        $descriptions = [];

        foreach ($this->distinctKinds() as $kind) {
            $ofKind = $notifications->filter(fn (Notification $notification) => $this->for($notification->type) === $kind)->values();

            if ($ofKind->isNotEmpty()) {
                $descriptions += $kind->describe($ofKind);
            }
        }

        return $descriptions;
    }

    /**
     * @return list<NotificationKind>
     */
    private function distinctKinds(): array
    {
        $distinct = [];

        foreach ($this->kinds as $kind) {
            $distinct[spl_object_id($kind)] = $kind;
        }

        return array_values($distinct);
    }
}
