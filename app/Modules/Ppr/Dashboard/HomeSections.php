<?php

namespace App\Modules\Ppr\Dashboard;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The project module's part of the company's home page: the start of the person's project dashboard.
 * Both sections arrive in one request and share one MyProjects, kept on the request, so each project is
 * worked out once.
 */
final readonly class HomeSections
{
    // The home page shows the most urgent ones; the project dashboard lists more.
    public const int ACTIONS_SHOWN = 10;

    public function __construct(private Request $request) {}

    public function showsNextActions(User $user): bool
    {
        return ! $this->myProjects($user)->isEmpty();
    }

    // Admins without a project get a link to every project instead.
    public function showsProjects(User $user): bool
    {
        return $user->isAdmin() || ! $this->myProjects($user)->isEmpty();
    }

    /**
     * @return array{actions: list<array<string, mixed>>, actionsTotal: int, notificationDays: int}
     */
    public function nextActions(User $user): array
    {
        $actions = $this->myProjects($user)->actions();

        return [
            'actions' => array_slice($actions, 0, self::ACTIONS_SHOWN),
            'actionsTotal' => count($actions),
            'notificationDays' => $user->notification_days,
        ];
    }

    /**
     * @return array{projects: list<array<string, mixed>>, canSeeAllProjects: bool}
     */
    public function projects(User $user): array
    {
        $myProjects = $this->myProjects($user);

        return [
            'projects' => array_map(
                fn (array $project, array $tiles) => [...$project, 'tiles' => $tiles['tiles']],
                $myProjects->summaries(),
                $myProjects->tiles(),
            ),
            'canSeeAllProjects' => $user->isAdmin(),
        ];
    }

    private function myProjects(User $user): MyProjects
    {
        // On the request, so it never outlives it: tests and long-running servers reuse the app.
        $key = MyProjects::class.':'.$user->id;

        if (! $this->request->attributes->has($key)) {
            $this->request->attributes->set($key, MyProjects::of($user));
        }

        return $this->request->attributes->get($key);
    }
}
