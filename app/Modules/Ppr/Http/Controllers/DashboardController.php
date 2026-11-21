<?php

namespace App\Modules\Ppr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ppr\Dashboard\MyProjects;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A person's own view: what they must act on next across their projects, and one row of numbers per
 * project (MyProjects). The home page shows the start of the same view.
 */
class DashboardController extends Controller
{
    // A longer list wouldn't be read; the page says how many more there are.
    public const int ACTIONS_SHOWN = 50;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $myProjects = MyProjects::of($user);

        return Inertia::render('ppr/Dashboard', [
            'projects' => $myProjects->summaries(),
            'notificationDays' => $user->notification_days,
            'can' => ['seeAllProjects' => $user->isAdmin()],
            // Deferred: it reads every package of every project, so the page opens with the names first.
            'attention' => Inertia::defer(fn () => [
                'tiles' => $myProjects->tiles(),
                'actions' => array_slice($myProjects->actions(), 0, self::ACTIONS_SHOWN),
                'actionsTotal' => count($myProjects->actions()),
            ]),
        ]);
    }
}
