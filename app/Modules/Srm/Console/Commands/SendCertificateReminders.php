<?php

namespace App\Modules\Srm\Console\Commands;

use App\Companies\EveryActiveCompany;
use App\Models\Company;
use App\Models\Notification as BellNotification;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Srm\Enums\SettingKey;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateReminder;
use App\Modules\Srm\Notifications\CertificateExpiringNotifications;
use App\Modules\Srm\Notifications\CertificatesExpiring;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use LogicException;

/**
 * Goes through the companies one at a time. A certificate whose supplier has a responsible buyer in the
 * company becomes a notification in that person's bell; the others go in one email to the company's
 * reminder email, which lists that company's certificates and links into it.
 */
class SendCertificateReminders extends Command
{
    protected $signature = 'app:send-certificate-reminders';

    protected $description = 'Remind about certificates that are about to expire';

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
            fn (CertificateReminder $reminder) => $this->responsiblePersonFor($company, $reminder->certificate) !== null,
        );

        $this->notifyInTheBell($company, $forPeople);
        $this->emailTheReminderAddress($company, $forTheCompany);
    }

    /**
     * Unsent reminders whose day has come, for certificates that have not expired yet.
     *
     * @return Collection<int, CertificateReminder>
     */
    private function dueReminders(): Collection
    {
        return CertificateReminder::query()
            ->whereNull('sent_at')
            ->where('remind_on', '<=', today())
            ->whereHas('certificate', fn ($certificate) => $certificate
                ->where('expires_on', '>=', today())
                ->whereRelation('supplier', 'archived_at', null))
            ->with([
                'certificate.type:id,name',
                'certificate.supplier:id,name,responsible_user_id',
                'certificate.supplier.responsibleUser:id,email,deactivated_at',
            ])
            ->get();
    }

    /**
     * @param  Collection<int, CertificateReminder>  $reminders
     */
    private function loadRecipientsMemberships(Company $company, Collection $reminders): void
    {
        User::loadMembershipsIn($company->id, $reminders
            ->map(fn (CertificateReminder $reminder) => $reminder->certificate->supplier->responsibleUser)
            ->filter());
    }

    /**
     * Someone removed from the company keeps being named on old suppliers, but no longer gets its reminders.
     */
    private function responsiblePersonFor(Company $company, Certificate $certificate): ?User
    {
        $responsibleUser = $certificate->supplier->responsibleUser;

        return $responsibleUser && ! $responsibleUser->isDeactivated() && $responsibleUser->activeMembershipIn($company->id) !== null
            ? $responsibleUser
            : null;
    }

    /**
     * One bell notification per person and certificate, stored with one query for the whole company. The
     * person's daily summary email (if they want one) lists them with their other notifications.
     *
     * @param  Collection<int, CertificateReminder>  $reminders
     */
    private function notifyInTheBell(Company $company, Collection $reminders): void
    {
        if ($reminders->isEmpty()) {
            return;
        }

        $notifications = $reminders
            ->unique(fn (CertificateReminder $reminder) => $reminder->certificate_id)
            ->map(fn (CertificateReminder $reminder) => [
                'user' => $this->responsiblePersonFor($company, $reminder->certificate) ?? throw new LogicException('The reminder has no responsible person.'),
                'type' => CertificateExpiringNotifications::TYPE,
                'data' => CertificateExpiringNotifications::about($reminder->certificate),
            ])
            ->values()
            ->all();

        DB::transaction(function () use ($reminders, $notifications) {
            CertificateReminder::query()->whereKey($reminders->pluck('id'))->update(['sent_at' => now()]);
            BellNotification::storeMany($notifications);
        });

        $this->components->info('Notified '.count($notifications)." responsible buyer(s) of {$company->name} about expiring certificates.");
    }

    /**
     * Certificates whose supplier has no responsible buyer in the company go to the company's reminder email.
     *
     * @param  Collection<int, CertificateReminder>  $reminders
     */
    private function emailTheReminderAddress(Company $company, Collection $reminders): void
    {
        if ($reminders->isEmpty()) {
            return;
        }

        $recipient = Setting::get(SettingKey::ReminderEmail);

        if ($recipient === null || $recipient === '') {
            Log::warning('Certificate reminders could not be sent: no responsible buyer and no reminder email is set.', [
                'company_id' => $company->id,
                'reminder_ids' => $reminders->pluck('id')->all(),
            ]);

            return;
        }

        $this->send($company, $recipient, $reminders);
    }

    /**
     * @param  Collection<int, CertificateReminder>  $reminders
     */
    private function send(Company $company, string $recipient, Collection $reminders): void
    {
        $certificates = $reminders->map(fn (CertificateReminder $reminder) => $reminder->certificate)->unique('id');

        DB::transaction(function () use ($company, $recipient, $reminders, $certificates) {
            CertificateReminder::query()->whereKey($reminders->pluck('id'))->update(['sent_at' => now()]);

            Notification::route('mail', $recipient)->notify(new CertificatesExpiring($company->name, $certificates));
        });

        $this->components->info("Sent {$certificates->count()} certificate reminder(s) of {$company->name} to {$recipient}.");
    }
}
