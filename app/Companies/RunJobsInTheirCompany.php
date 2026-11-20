<?php

namespace App\Companies;

use App\Models\Company;
use Illuminate\Queue\Events\JobProcessing;

/**
 * A queued job runs in the company it was queued from. The company id travels in the job's
 * payload and is opened before the job is unpacked, so the models it carries can be loaded.
 *
 * Jobs can also run inside a request (the sync queue), so the company before the job is put back
 * afterwards rather than cleared.
 */
final class RunJobsInTheirCompany
{
    /** @var list<Company|null> */
    private array $before = [];

    /**
     * @return array{company_id?: int}
     */
    public function payload(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return $companyId === null ? [] : ['company_id' => $companyId];
    }

    public function starting(JobProcessing $event): void
    {
        $currentCompany = app(CurrentCompany::class);
        $this->before[] = $currentCompany->get();

        $companyId = $event->job->payload()['company_id'] ?? null;
        $company = is_int($companyId) ? Company::query()->find($companyId) : null;

        $company === null ? $currentCompany->clear() : $currentCompany->set($company);
    }

    public function finished(): void
    {
        $currentCompany = app(CurrentCompany::class);
        $before = array_pop($this->before);

        $before === null ? $currentCompany->clear() : $currentCompany->set($before);
    }
}
