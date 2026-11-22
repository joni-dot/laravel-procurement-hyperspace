<?php

namespace App\Providers;

use App\Monitoring\ReportedCompany;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Error reports (docs/monitoring.md). Sending them is wired up in bootstrap/app.php; this only keeps
 * track of which company a queued job ran for, and only when reports are switched on.
 */
class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportedCompany::class);
    }

    public function boot(): void
    {
        if (blank(config('sentry.dsn'))) {
            return;
        }

        Event::listen(JobProcessing::class, [ReportedCompany::class, 'jobStarting']);
        Event::listen(JobProcessed::class, [ReportedCompany::class, 'jobFinished']);
    }
}
