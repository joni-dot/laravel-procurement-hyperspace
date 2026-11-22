<?php

namespace App\Companies;

use App\Models\Company;
use App\Models\User;
use Closure;
use InvalidArgumentException;

/**
 * Fills a demo company with made-up data. Each module adds a filler for its own data while booting, so Core
 * never needs to know what the modules hold. A filler that builds on another module's data (Sourcing on
 * suppliers and packages) takes a higher order number.
 *
 * Fillers must work in production, where the development-only test data tools are not installed, and must never
 * create user accounts: every person in demo data is the company's first admin, or nobody.
 */
final class DemoData
{
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    /** @var array<string, array{order: int, fill: Closure(Company, User): void}> */
    private array $fillers = [];

    /**
     * @param  string  $key  unique, lower case with underscores, such as "srm_suppliers"
     * @param  int  $order  lowest first
     * @param  Closure(Company, User): void  $fill  adds the module's data; runs inside the company
     */
    public function add(string $key, int $order, Closure $fill): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Demo data key [{$key}] must be lower case letters, digits and underscores.");
        }

        if (isset($this->fillers[$key])) {
            throw new InvalidArgumentException("Demo data [{$key}] is already registered.");
        }

        $this->fillers[$key] = ['order' => $order, 'fill' => $fill];
    }

    /**
     * The registered keys in the order they fill.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $fillers = $this->fillers;
        uasort($fillers, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_keys($fillers);
    }

    /**
     * Runs every filler inside the company, so their rows belong to it and nothing else is in reach.
     *
     * @param  User  $admin  the company's first admin: the only person demo data may name
     */
    public function fill(Company $company, User $admin): void
    {
        // CurrentCompany is renewed for every request and job, so it is looked up now rather than kept.
        app(CurrentCompany::class)->runAs($company, function (Company $company) use ($admin): void {
            foreach ($this->keys() as $key) {
                ($this->fillers[$key]['fill'])($company, $admin);
            }
        });
    }
}
