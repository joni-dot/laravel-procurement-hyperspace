<?php

namespace App\Modules\Ppr;

use App\Companies\CompanyHomePages;
use App\Companies\NavigationCompany;
use App\Events\MembershipDeactivated;
use App\Events\MembershipReactivated;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ppr\Access\ProjectRoles;
use App\Modules\Ppr\Console\Commands\CheckPackageRisks;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Notification;
use App\Modules\Ppr\Models\ProjectMember;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

/**
 * Plugs the project procurement register module into the app: its routes, tables, commands,
 * timed jobs, shared page data and the company's home page.
 */
class PprServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ProjectRoles::class);
    }

    /**
     * Scoped instances are only renewed for queued jobs. Tests (and long-running servers) handle many
     * requests in one app, where a role remembered from an earlier request would be out of date.
     */
    private function forgetProjectRolesAfterEachRequest(): void
    {
        Event::listen(function (RequestHandled $event): void {
            $this->app->forgetInstance(ProjectRoles::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->forgetProjectRolesAfterEachRequest();
        $this->offerHomePage();
        $this->checkRisksWhenMembershipsChange();
        $this->shareUnreadNotificationCount();
        $this->registerRoutes();
        $this->registerCommands();
    }

    /** Admins and project members open the company on the project dashboard. */
    private function offerHomePage(): void
    {
        $this->app->make(CompanyHomePages::class)->offer(10, fn (User $user): ?string => $user->isAdmin()
            || ProjectMember::query()->where('user_id', $user->id)->exists() ? 'projects.dashboard' : null);
    }

    /** A package whose owner or buyer left the company is at risk, and becomes safe again when they return. */
    private function checkRisksWhenMembershipsChange(): void
    {
        Event::listen(function (MembershipDeactivated $event): void {
            CheckProjectRisks::dispatchForProjectsOf($event->membership->user, $event->changedBy->id);
        });

        Event::listen(function (MembershipReactivated $event): void {
            CheckProjectRisks::dispatchForProjectsOf($event->membership->user, $event->changedBy->id);
        });
    }

    /** For the bell on every page. Counted in the navigation company, so account pages show it too. */
    private function shareUnreadNotificationCount(): void
    {
        // A closure, so partial reloads that don't ask for it skip the query.
        Inertia::share('ppr', fn (): array => [
            'unreadNotificationCount' => $this->unreadNotificationCount(),
        ]);
    }

    private function unreadNotificationCount(): int
    {
        $request = request();
        $user = $request->user();

        // Platform pages never read company data, not even a person's own notifications.
        if (! $user instanceof User || $request->routeIs('platform.*')) {
            return 0;
        }

        $navigationCompany = $this->app->make(NavigationCompany::class);

        // Outside every company (a platform admin, someone not yet in one) there is nothing to count.
        if ($navigationCompany->for($user) === null) {
            return 0;
        }

        return $navigationCompany->runFor($user, fn (): int => Notification::query()
            ->visibleTo($user)
            ->whereNull('read_at')
            ->count());
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        // First, so /settings/notifications is not read as the notifications of a company called "settings".
        Route::middleware('web')->group(__DIR__.'/routes/account.php');
        Route::middleware(['web', 'company'])
            ->prefix('{company}')
            ->where(['company' => Company::routePattern()])
            ->group(__DIR__.'/routes/web.php');
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([CheckPackageRisks::class]);

        // Early morning in Finland (06:00 or 07:00), before the working day, so reminders are waiting when people start.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('packages:check-risks')
                ->dailyAt('04:00')
                ->timezone('UTC')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
