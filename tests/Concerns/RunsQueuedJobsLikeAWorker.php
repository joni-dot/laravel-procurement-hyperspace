<?php

namespace Tests\Concerns;

use App\Companies\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * For tests about background work in companies: jobs wait in the database queue and are then run
 * the way the queue worker runs them, outside every company, so each job has to open its own.
 * The sync queue used elsewhere runs a job straight away, still inside the test's company.
 */
trait RunsQueuedJobsLikeAWorker
{
    protected function queueJobsForTheWorker(): void
    {
        config(['queue.default' => 'database']);
    }

    /**
     * Runs the waiting jobs (one, or all of them and those they queue) and then enters the test's company again.
     */
    protected function runQueuedJobsLikeAWorker(bool $onlyOne = false): void
    {
        app(CurrentCompany::class)->clear();

        // The worker runs inside the test process, so its memory limit would count everything the whole
        // test run holds (tests with big import files included) and stop it with exit code 12.
        $this->artisan('queue:work', ['--sleep' => 0, '--memory' => 4096, ...($onlyOne ? ['--once' => true] : ['--stop-when-empty' => true])])->assertSuccessful();

        $this->enterCompany($this->company);
    }

    protected function assertNoJobFailed(): void
    {
        $this->assertSame([], DB::table('failed_jobs')->pluck('exception')->all());
    }
}
