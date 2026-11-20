<?php

namespace App\Providers;

use App\Companies\CurrentCompany;
use App\Companies\RunJobsInTheirCompany;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Wires up "the current company" for requests, queued jobs and commands.
 */
class CompanyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh, empty one for every request and every queued job.
        $this->app->scoped(CurrentCompany::class);
        $this->app->singleton(RunJobsInTheirCompany::class);
    }

    public function boot(): void
    {
        Queue::createPayloadUsing(fn (): array => $this->app->make(RunJobsInTheirCompany::class)->payload());

        Event::listen(JobProcessing::class, [RunJobsInTheirCompany::class, 'starting']);
        Event::listen([JobProcessed::class, JobExceptionOccurred::class], [RunJobsInTheirCompany::class, 'finished']);
    }
}
