<?php

namespace App\Modules\Srm\Console\Commands;

use App\Companies\EveryActiveCompany;
use App\Models\Company;
use App\Modules\Srm\Actions\Contracts\SaveContract;
use App\Modules\Srm\Enums\ContractStatus;
use App\Modules\Srm\Models\Contract;
use Illuminate\Console\Command;

/**
 * Goes through the companies one at a time; a contract can only be read and saved inside its own company.
 */
class RenewContracts extends Command
{
    protected $signature = 'app:renew-contracts';

    protected $description = 'Move the end date of auto-renewing contracts that were not cancelled';

    public function handle(EveryActiveCompany $everyActiveCompany, SaveContract $saveContract): int
    {
        $succeeded = $everyActiveCompany->run(fn (Company $company) => $this->renewFor($company, $saveContract));

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    private function renewFor(Company $company, SaveContract $saveContract): void
    {
        $renewedCount = 0;

        Contract::query()
            ->where('status', ContractStatus::Active)
            ->where('auto_renews', true)
            ->where('ends_on', '<', today())
            ->with('reminders:id,contract_id,days_before')
            ->lazyById()
            ->each(function (Contract $contract) use ($saveContract, &$renewedCount) {
                $saveContract->handle(
                    $contract,
                    ['ends_on' => $this->nextEndDate($contract)],
                    array_values($contract->reminders->map(fn ($reminder) => $reminder->days_before)->all()),
                );

                $renewedCount++;
            });

        if ($renewedCount > 0) {
            $this->components->info("Renewed {$renewedCount} contract(s) of {$company->name}.");
        }
    }

    /**
     * A contract may have ended more than one term ago, for example after downtime.
     */
    private function nextEndDate(Contract $contract): string
    {
        $endsOn = $contract->ends_on;

        while ($endsOn->isBefore(today())) {
            $endsOn = $endsOn->addMonthsNoOverflow((int) $contract->renewal_term_months);
        }

        return $endsOn->toDateString();
    }
}
