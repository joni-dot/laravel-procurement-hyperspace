<?php

namespace App\Modules\Srm\Console\Commands;

use App\Companies\EveryActiveCompany;
use App\Models\Company;
use App\Models\Notification as BellNotification;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Srm\Enums\ContractStatus;
use App\Modules\Srm\Enums\SettingKey;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\ContractReminder;
use App\Modules\Srm\Notifications\ContractNeedsActionNotifications;
use App\Modules\Srm\Notifications\ContractsNeedAction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use LogicException;

/**
 * Goes through the companies one at a time. A contract whose owner (else the supplier's responsible buyer)
 * may see contract details in the company becomes a notification in that person's bell; the others go in
 * one email to the company's reminder email, which lists that company's contracts and links into it.
 */
class SendContractReminders extends Command
{
    protected $signature = 'app:send-contract-reminders';

    protected $description = 'Remind about contracts that are about to end or must be cancelled soon';

    public function handle(EveryActiveCompany $everyActiveCompany): int
    {
        $succeeded = $everyActiveCompany->run(fn (Company $company) => $this->sendFor($company));

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    private function sendFor(Company $company): void
    {
        $dueReminders = $this->dueReminders();

        if ($dueReminders->isEmpty()) {
            return;
        }

        $this->loadRecipientsMemberships($company, $dueReminders);

        [$forPeople, $forTheCompany] = $dueReminders->partition(
            fn (ContractReminder $reminder) => $this->personFor($reminder->contract) !== null,
        );

        $this->notifyInTheBell($company, $forPeople);
        $this->emailTheReminderAddress($company, $forTheCompany);
    }

    /**
     * Unsent reminders whose day has come, for active contracts whose deadline has not passed.
     *
     * @return Collection<int, ContractReminder>
     */
    private function dueReminders(): Collection
    {
        return ContractReminder::query()
            ->whereNull('sent_at')
            ->where('remind_on', '<=', today())
            ->whereHas('contract', fn ($contract) => $contract
                ->where('status', ContractStatus::Active)
                ->where('action_by', '>=', today())
                ->whereRelation('supplier', 'archived_at', null))
            ->with([
                'contract.owner:id,email,deactivated_at',
                'contract.supplier:id,name,responsible_user_id',
                'contract.supplier.responsibleUser:id,email,deactivated_at',
            ])
            ->get();
    }

    /**
     * The permission check below needs each person's role in this company; one query finds them all.
     *
     * @param  Collection<int, ContractReminder>  $reminders
     */
    private function loadRecipientsMemberships(Company $company, Collection $reminders): void
    {
        User::loadMembershipsIn($company->id, $reminders
            ->flatMap(fn (ContractReminder $reminder) => [$reminder->contract->owner, $reminder->contract->supplier->responsibleUser])
            ->filter());
    }

    /**
     * The contract owner, else the supplier's responsible buyer. Only people allowed to see contract details
     * in this company are told about contracts.
     */
    private function personFor(Contract $contract): ?User
    {
        foreach ([$contract->owner, $contract->supplier->responsibleUser] as $user) {
            if ($user instanceof User && ! $user->isDeactivated() && $user->can('viewDetails', Contract::class)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * One bell notification per person and contract, stored with one query for the whole company. The
     * person's daily summary email (if they want one) lists them with their other notifications.
     *
     * @param  Collection<int, ContractReminder>  $reminders
     */
    private function notifyInTheBell(Company $company, Collection $reminders): void
    {
        if ($reminders->isEmpty()) {
            return;
        }

        $notifications = $reminders
            ->unique(fn (ContractReminder $reminder) => $reminder->contract_id)
            ->map(fn (ContractReminder $reminder) => [
                'user' => $this->personFor($reminder->contract) ?? throw new LogicException('The reminder has no person to tell.'),
                'type' => ContractNeedsActionNotifications::TYPE,
                'data' => ContractNeedsActionNotifications::about($reminder->contract),
            ])
            ->values()
            ->all();

        DB::transaction(function () use ($reminders, $notifications) {
            ContractReminder::query()->whereKey($reminders->pluck('id'))->update(['sent_at' => now()]);
            BellNotification::storeMany($notifications);
        });

        $this->components->info('Notified about '.count($notifications)." contract(s) of {$company->name} in the bell.");
    }

    /**
     * Contracts nobody in the company may be told about go to the company's reminder email.
     *
     * @param  Collection<int, ContractReminder>  $reminders
     */
    private function emailTheReminderAddress(Company $company, Collection $reminders): void
    {
        if ($reminders->isEmpty()) {
            return;
        }

        $recipient = Setting::get(SettingKey::ReminderEmail);

        if ($recipient === null || $recipient === '') {
            Log::warning('Contract reminders could not be sent: no contract owner, no responsible buyer and no reminder email is set.', [
                'company_id' => $company->id,
                'reminder_ids' => $reminders->pluck('id')->all(),
            ]);

            return;
        }

        $this->send($company, $recipient, $reminders);
    }

    /**
     * @param  Collection<int, ContractReminder>  $reminders
     */
    private function send(Company $company, string $recipient, Collection $reminders): void
    {
        $contracts = $reminders->map(fn (ContractReminder $reminder) => $reminder->contract)->unique('id');

        DB::transaction(function () use ($company, $recipient, $reminders, $contracts) {
            ContractReminder::query()->whereKey($reminders->pluck('id'))->update(['sent_at' => now()]);

            Notification::route('mail', $recipient)->notify(new ContractsNeedAction($company->name, $contracts));
        });

        $this->components->info("Sent {$contracts->count()} contract reminder(s) of {$company->name} to {$recipient}.");
    }
}
