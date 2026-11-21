<?php

namespace App\Modules\Srm;

use App\Companies\CompanyHomePages;
use App\Companies\CurrentCompany;
use App\Models\Company;
use App\Modules\Srm\Actions\AddStartingLists;
use App\Modules\Srm\Console\Commands\RenewContracts;
use App\Modules\Srm\Console\Commands\SendCertificateReminders;
use App\Modules\Srm\Console\Commands\SendContractReminders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Plugs the supplier relationship management module into the app: its routes, tables,
 * commands and timed jobs.
 */
class SrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->startNewCompanies();
        $this->offerHomePage();
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

    /** The supplier dashboard suits everyone, so it is the last choice. */
    private function offerHomePage(): void
    {
        $this->app->make(CompanyHomePages::class)->offer(100, fn (): string => 'dashboard');
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
