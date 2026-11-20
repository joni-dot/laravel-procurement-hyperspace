<?php

namespace App\Companies;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * The company the current request, job or command works for.
 *
 * Setting it does three things: the database session only sees that company's rows (see
 * CompanyIsolation), new company rows belong to it, and route() fills in its address.
 * With no company set, company data is out of reach.
 */
final class CurrentCompany
{
    private ?Company $company = null;

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function set(Company $company): void
    {
        $this->company = $company;
        $this->applyToDatabase((string) $company->id);
        URL::defaults(['company' => $company->slug]);
    }

    public function clear(): void
    {
        $this->company = null;
        $this->applyToDatabase('');
        URL::defaults(['company' => null]);
    }

    /**
     * Runs the callback inside another company and returns to the previous one afterwards, for
     * work that goes through several companies (timed jobs, platform tasks).
     *
     * @template TResult
     *
     * @param  callable(Company): TResult  $callback
     * @return TResult
     */
    public function runAs(Company $company, callable $callback): mixed
    {
        $previous = $this->company;
        $this->set($company);

        try {
            return $callback($company);
        } finally {
            $previous === null ? $this->clear() : $this->set($previous);
        }
    }

    private function applyToDatabase(string $companyId): void
    {
        DB::select('SELECT set_config(?, ?, false)', [CompanyIsolation::SETTING, $companyId]);
    }
}
