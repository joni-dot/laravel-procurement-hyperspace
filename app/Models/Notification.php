<?php

namespace App\Models;

use App\Companies\BelongsToCompany;
use App\Companies\CurrentCompany;
use App\Notifications\NotificationKinds;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * A stored in-app notification of any module. "type" is the kind, such as "package_assigned"; the module
 * that registered the kind in App\Notifications\NotificationKinds decides who may still see it, what it
 * says and where opening it leads. Read them only through visibleTo().
 *
 * @property string $id
 * @property string $type
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Notification extends DatabaseNotification
{
    use BelongsToCompany;

    /**
     * A person's own notifications that they may still see, such as only those of projects they can see.
     * Kinds no module registers are never visible.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id);

        app(NotificationKinds::class)->onlyVisibleTo($query, $user);
    }

    /**
     * Found only among the signed-in person's visible notifications, so anyone else's id answers "not found".
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): self
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 404);

        return self::query()->visibleTo($user)->whereKey($value)->firstOrFail();
    }

    /**
     * Where opening the notification leads: a path inside the current company.
     */
    public function destination(): string
    {
        $kind = app(NotificationKinds::class)->for($this->type)
            ?? throw new LogicException("No module registers notifications of type [{$this->type}].");

        return $kind->destination($this);
    }

    /**
     * Stores many notifications with one query, for timed jobs that tell many people about many records.
     * Each is stored in the current company.
     *
     * @param  array<array{user: User, type: string, data: array<string, mixed>}>  $notifications
     */
    public static function storeMany(array $notifications): void
    {
        if ($notifications === []) {
            return;
        }

        $companyId = app(CurrentCompany::class)->id() ?? throw new LogicException('Notifications can only be stored inside a company.');
        $now = now();

        self::query()->insert(array_values(array_map(fn (array $notification) => [
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'type' => $notification['type'],
            'notifiable_type' => $notification['user']->getMorphClass(),
            'notifiable_id' => $notification['user']->id,
            'data' => json_encode($notification['data'], JSON_THROW_ON_ERROR),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $notifications)));
    }
}
