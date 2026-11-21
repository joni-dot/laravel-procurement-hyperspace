<?php

namespace App\Modules\Srm;

use App\Companies\CurrentCompany;
use App\Models\Company;
use App\Models\User;
use App\Modules\Srm\Actions\AddStartingLists;
use App\Modules\Srm\Console\Commands\RenewContracts;
use App\Modules\Srm\Console\Commands\SendCertificateReminders;
use App\Modules\Srm\Console\Commands\SendContractReminders;
use App\Modules\Srm\Dashboard\SupplierAttention;
use App\Modules\Srm\Notifications\CertificateExpiringNotifications;
use App\Modules\Srm\Notifications\ContractNeedsActionNotifications;
use App\Notifications\NotificationKinds;
use App\Pages\PageSections;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Plugs the supplier relationship management module into the app: its routes, tables,
 * commands, timed jobs and its sections on the company's home page.
 */
class SrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->startNewCompanies();
        $this->addHomeSections();
        $this->registerNotificationKinds();
        $this->registerRoutes();
        $this->registerCommands();
    }

    private function startNewCompanies(): void
    {
        Company::created(function (Company $company): void {
            $this->app->make(CurrentCompany::class)->runAs(
                $company,
                fn () => $this->app->make(AddStartingLists::class)->handle(),
            );
        });
    }

    /** Certificate and contract reminders addressed to a person arrive in Core's bell and summary email. */
    private function registerNotificationKinds(): void
    {
        $this->callAfterResolving(NotificationKinds::class, function (NotificationKinds $kinds): void {
            $kinds->register(new CertificateExpiringNotifications);
            $kinds->register(new ContractNeedsActionNotifications);
        });
    }

    /** The supplier dashboard's attention tiles and soonest dates, for every member: everyone can see suppliers. */
    private function addHomeSections(): void
    {
        $sections = $this->app->make(PageSections::class);

        // Deferred: the counts read every supplier, so the page opens first.
        $sections->add('home', 'srm_attention', 30, 'srm/SupplierAttention',
            fn (): bool => true,
            fn (User $user): array => SupplierAttention::for($user)->counts(),
            deferred: true);
        $sections->add('home', 'srm_upcoming', 40, 'srm/UpcomingDates',
            fn (): bool => true,
            fn (User $user): array => SupplierAttention::for($user)->upcoming(),
            deferred: true);
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'company'])
            ->prefix('{company}')
            ->where(['company' => Company::routePattern()])
            ->group(__DIR__.'/routes/web.php');
        Route::middleware('api')->prefix('api')->group(__DIR__.'/routes/api.php');
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([RenewContracts::class, SendContractReminders::class, SendCertificateReminders::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('app:renew-contracts')
                ->dailyAt('05:30')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('app:send-contract-reminders')
                ->dailyAt('06:00')
                ->withoutOverlapping()
                ->onOneServer();

            $schedule->command('app:send-certificate-reminders')
                ->dailyAt('06:00')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
