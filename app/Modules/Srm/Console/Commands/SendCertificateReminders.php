<?php

namespace App\Modules\Srm\Console\Commands;

use App\Companies\EveryActiveCompany;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Srm\Enums\SettingKey;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateReminder;
use App\Modules\Srm\Notifications\CertificatesExpiring;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Goes through the companies one at a time, so each email lists one company's certificates, links
 * into that company and goes to people of that company.
 */
class SendCertificateReminders extends Command
{
    protected $signature = 'app:send-certificate-reminders';

    protected $description = 'Email reminders about certificates that are about to expire';

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
        $fallbackEmail = Setting::get(SettingKey::ReminderEmail);

        $dueReminders
            ->groupBy(fn (CertificateReminder $reminder) => $this->recipientFor($company, $reminder->certificate, $fallbackEmail) ?? '')
            ->each(function (Collection $reminders, string $recipient) use ($company) {
                if ($recipient === '') {
                    Log::warning('Certificate reminders could not be sent: no responsible buyer and no reminder email is set.', [
                        'company_id' => $company->id,
                        'reminder_ids' => $reminders->pluck('id')->all(),
                    ]);

                    return;
                }

                $this->send($company, $recipient, $reminders);
            });
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
     * Someone removed from the company keeps being named on old suppliers, but no longer gets its emails.
     */
    private function recipientFor(Company $company, Certificate $certificate, ?string $fallbackEmail): ?string
    {
        $responsibleUser = $certificate->supplier->responsibleUser;

        if ($responsibleUser && ! $responsibleUser->isDeactivated() && $responsibleUser->activeMembershipIn($company->id) !== null) {
            return $responsibleUser->email;
        }

        return $fallbackEmail;
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
