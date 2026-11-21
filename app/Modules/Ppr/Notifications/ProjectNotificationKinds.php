<?php

namespace App\Modules\Ppr\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Modules\Ppr\Models\Project;
use App\Notifications\NotificationDescription;
use App\Notifications\NotificationKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The project notifications, for Core's notifications page, bell and summary email. People see them only
 * while they can see the project (admins see all of theirs), and opening one goes to its package, or to
 * the project's package list when it is about the whole project.
 */
final class ProjectNotificationKinds implements NotificationKind
{
    /** @var list<class-string<ProjectNotification>> */
    private const array KINDS = [
        PackageAssigned::class,
        DateComingUp::class,
        DateOverdue::class,
        RiskLevelChanged::class,
        RiskChangesSummary::class,
    ];

    public function types(): array
    {
        return array_map(fn (string $kind) => $kind::kind(), self::KINDS);
    }

    public function onlyVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('project_id', Project::query()->visibleTo($user)->select('projects.id'));
    }

    public function describe(Collection $notifications): array
    {
        $projectNames = Project::query()
            ->whereKey($notifications->map(fn (Notification $notification) => self::projectIdOf($notification))->unique()->values())
            ->pluck('name', 'id');

        $descriptions = [];

        foreach ($notifications as $notification) {
            $projectName = (string) $projectNames->get(self::projectIdOf($notification), '');
            /** @var array{package_id?: int, package_number?: string, package_title?: string, params: array<string, mixed>} $data */
            $data = $notification->data;

            $descriptions[$notification->id] = new NotificationDescription(
                $this->kindClass($notification->type)::message($data, $projectName),
                $projectName,
            );
        }

        return $descriptions;
    }

    public function destination(Notification $notification): string
    {
        $project = self::projectIdOf($notification);
        $package = $notification->data['package_id'] ?? null;

        return $package === null
            ? route('projects.packages.index', ['project' => $project], absolute: false)
            : route('projects.packages.show', ['project' => $project, 'package' => $package], absolute: false);
    }

    private static function projectIdOf(Notification $notification): int
    {
        return (int) $notification->getAttribute('project_id');
    }

    /**
     * @return class-string<ProjectNotification>
     */
    private function kindClass(string $type): string
    {
        foreach (self::KINDS as $kind) {
            if ($kind::kind() === $type) {
                return $kind;
            }
        }

        throw new LogicException("[{$type}] is not a project notification.");
    }
}
