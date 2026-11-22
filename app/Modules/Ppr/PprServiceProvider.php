<?php

namespace App\Modules\Ppr;

use App\Companies\DemoData;
use App\Events\MembershipDeactivated;
use App\Events\MembershipReactivated;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ppr\Access\ProjectRoles;
use App\Modules\Ppr\Console\Commands\CheckPackageRisks;
use App\Modules\Ppr\Dashboard\HomeSections;
use App\Modules\Ppr\Demo\DemoProject;
use App\Modules\Ppr\Http\Requests\Settings\NotificationDaysUpdateRequest;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Project;
use App\Modules\Ppr\Notifications\ProjectNotificationKinds;
use App\Modules\Ppr\Risk\OutsideRiskReasons;
use App\Notifications\NotificationKinds;
use App\Onboarding\OnboardingSteps;
use App\Pages\PageSections;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Plugs the project procurement register module into the app: its routes, tables, commands,
 * timed jobs, shared page data and its sections on the company's home page.
 */
class PprServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ProjectRoles::class);
        // Other modules add their checks while booting.
        $this->app->singleton(OutsideRiskReasons::class);
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
        $this->addHomeSections();
        $this->addDemoData();
        $this->addOnboardingSteps();
        $this->checkRisksWhenMembershipsChange();
        $this->plugInNotifications();
        $this->registerRoutes();
        $this->registerCommands();
    }

    /** The start of the person's project dashboard, for project members and admins. */
    private function addHomeSections(): void
    {
        $sections = $this->app->make(PageSections::class);
        $home = fn (): HomeSections => $this->app->make(HomeSections::class);

        // Deferred: they read every package of the person's projects, so the page opens first.
        $sections->add('home', 'ppr_next_actions', 10, 'ppr/NextActions',
            fn (User $user): bool => $home()->showsNextActions($user),
            fn (User $user): array => $home()->nextActions($user),
            deferred: true);
        $sections->add('home', 'ppr_projects', 20, 'ppr/MyProjects',
            fn (User $user): bool => $home()->showsProjects($user),
            fn (User $user): array => $home()->projects($user),
            deferred: true);
    }

    /** The project module's part of the getting-started checklist on Home. */
    private function addOnboardingSteps(): void
    {
        $this->callAfterResolving(OnboardingSteps::class, function (OnboardingSteps $steps): void {
            // The Projects page, where "New project" opens the form.
            $steps->add('create_project', 40,
                title: fn (): string => __('Create your first project'),
                description: fn (): string => __('Plan a project’s procurement packages and follow their dates and risks.'),
                link: fn (): string => route('projects.index', absolute: false),
                done: fn (): bool => Project::query()->exists());
        });
    }

    /** A made-up project for a new demo company, after its suppliers. */
    private function addDemoData(): void
    {
        $this->callAfterResolving(DemoData::class, function (DemoData $demoData): void {
            $demoData->add('ppr_project', 20, fn (Company $company, User $admin) => $this->app->make(DemoProject::class)->fill($company, $admin));
        });
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

    /** Project notifications in Core's bell and summary, and the reminder period on Settings → Notifications. */
    private function plugInNotifications(): void
    {
        $this->callAfterResolving(NotificationKinds::class, function (NotificationKinds $kinds): void {
            $kinds->register(new ProjectNotificationKinds);
        });

        $this->app->make(PageSections::class)->add('settings.notifications', 'ppr_notification_days', 10, 'ppr/NotificationDays',
            fn (): bool => true,
            fn (User $user): array => [
                'notificationDays' => $user->notification_days,
                'maxNotificationDays' => NotificationDaysUpdateRequest::MAX_DAYS,
            ]);
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
