<?php

namespace App\Companies;

use App\Models\Company;
use Throwable;

/**
 * Runs work once inside each company that isn't suspended, for timed jobs that serve every company.
 *
 * Companies are read in small batches, so the number of companies doesn't affect memory use. A
 * failure in one company is reported and the others still get their turn: one company's broken
 * data must not stop everyone else's reminders.
 */
final class EveryActiveCompany
{
    private const int BATCH_SIZE = 100;

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * @param  callable(Company): void  $callback
     * @return bool whether the work succeeded in every company
     */
    public function run(callable $callback): bool
    {
        $succeeded = true;

        Company::query()
            ->whereNull('suspended_at')
            ->lazyById(self::BATCH_SIZE)
            ->each(function (Company $company) use ($callback, &$succeeded): void {
                try {
                    $this->currentCompany->runAs($company, $callback);
                } catch (Throwable $exception) {
                    report($exception);
                    $succeeded = false;
                }
            });

        return $succeeded;
    }
}
