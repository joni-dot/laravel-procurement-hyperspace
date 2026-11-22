<?php

namespace App\Monitoring;

use App\Companies\CurrentCompany;
use Illuminate\Queue\Events\JobProcessing;

/**
 * The company an error report names: the open company. A failed queued job is reported after the
 * worker has already left the job's company (RunJobsInTheirCompany), so the job's company is
 * remembered from the moment the job starts until it finishes or the next job starts.
 */
final class ReportedCompany
{
    private ?int $jobCompanyId = null;

    public function id(): ?int
    {
        return app(CurrentCompany::class)->id() ?? $this->jobCompanyId;
    }

    public function jobStarting(JobProcessing $event): void
    {
        $companyId = $event->job->payload()['company_id'] ?? null;

        $this->jobCompanyId = is_int($companyId) ? $companyId : null;
    }

    public function jobFinished(): void
    {
        $this->jobCompanyId = null;
    }
}
