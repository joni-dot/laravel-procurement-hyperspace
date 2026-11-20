<?php

namespace App\Modules\Srm;

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
        $this->registerRoutes();
        $this->registerCommands();
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
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
