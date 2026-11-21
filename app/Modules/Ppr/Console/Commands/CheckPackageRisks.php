<?php

namespace App\Modules\Ppr\Console\Commands;

use App\Companies\EveryActiveCompany;
use App\Modules\Ppr\Jobs\CheckProjectRisks;
use App\Modules\Ppr\Models\Project;
use Illuminate\Console\Command;

/**
 * The daily check: risk changes caused by time passing, and reminders of coming and overdue dates.
 * One background job per project, so a big project doesn't hold up the others.
 */
class CheckPackageRisks extends Command
{
    protected $signature = 'packages:check-risks';

    protected $description = 'Queue the daily risk and date reminder check of every project';

    public function handle(EveryActiveCompany $everyActiveCompany): int
    {
        $queued = 0;

        // Each job carries the company it was queued in, so it checks the project inside that company.
        $succeeded = $everyActiveCompany->run(function () use (&$queued): void {
            // toBase(): only the ids, without building a model for every project.
            foreach (Project::query()->orderBy('id')->toBase()->pluck('id') as $projectId) {
                CheckProjectRisks::dispatch($projectId, sendDateReminders: true);
                $queued++;
            }
        });

        $this->info("Queued the check of {$queued} projects.");

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }
}
