<?php

namespace App\Providers;

use App\Monitoring\ReportedCompany;
use App\Monitoring\SecurityEvents;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Logs and error reports (docs/monitoring.md): the security event log, the route on every log line
 * (the rest of a request's context comes from AddRequestContext), and which company a queued job ran
 * for, for error reports when they are switched on. Sending reports is wired up in bootstrap/app.php.
 */
class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportedCompany::class);
    }

    public function boot(): void
    {
        Event::subscribe(SecurityEvents::class);

        // The route's name, or its pattern (/{company}/...) when it has none: never the values in the address.
        Event::listen(RouteMatched::class, fn (RouteMatched $event) => Context::add(
            'route',
            $event->route->getName() ?? '/'.ltrim($event->route->uri(), '/'),
        ));

        if (blank(config('sentry.dsn'))) {
            return;
        }

        Event::listen(JobProcessing::class, [ReportedCompany::class, 'jobStarting']);
        Event::listen(JobProcessed::class, [ReportedCompany::class, 'jobFinished']);
    }
}
