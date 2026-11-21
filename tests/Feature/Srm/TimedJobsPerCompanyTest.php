<?php

namespace Tests\Feature\Srm;

use App\Companies\CurrentCompany;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Notification as BellNotification;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Srm\Enums\SettingKey;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\ContractReminder;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Notifications\CertificatesExpiring;
use App\Modules\Srm\Notifications\ContractsNeedAction;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The timed jobs serve every company, but each email belongs to one company: its contracts or
 * certificates, its people and links into its own address.
 */
class TimedJobsPerCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Company $first;

    private Company $second;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->first = $this->company;
        $this->second = Company::factory()->create();
    }

    public function test_contract_reminders_of_two_companies_never_mix()
    {
        $firstContract = $this->contractWithReminderDueToday($this->first, ownerEmail: 'first@example.com');
        $secondContract = $this->contractWithReminderDueToday($this->second, ownerEmail: 'second@example.com');

        $this->artisan('app:send-contract-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame([[(int) $firstContract->owner_user_id, $firstContract->id]], $this->bellIn($this->first, 'contract_id'));
        $this->assertSame([[(int) $secondContract->owner_user_id, $secondContract->id]], $this->bellIn($this->second, 'contract_id'));
    }

    public function test_each_company_uses_its_own_reminder_email()
    {
        $this->inCompany($this->first, fn () => Setting::put(SettingKey::ReminderEmail, 'first-team@example.com'));
        $this->inCompany($this->second, fn () => Setting::put(SettingKey::ReminderEmail, 'second-team@example.com'));
        $firstContract = $this->contractWithReminderDueToday($this->first);
        $secondContract = $this->contractWithReminderDueToday($this->second);

        $this->artisan('app:send-contract-reminders');

        $this->assertContractEmail('first-team@example.com', $this->first, [$firstContract]);
        $this->assertContractEmail('second-team@example.com', $this->second, [$secondContract]);
    }

    public function test_a_company_without_a_reminder_email_does_not_borrow_another_companys()
    {
        $this->inCompany($this->first, fn () => Setting::put(SettingKey::ReminderEmail, 'first-team@example.com'));
        $this->contractWithReminderDueToday($this->second);

        $this->artisan('app:send-contract-reminders');

        Notification::assertNothingSent();
        $this->assertSame(1, $this->inCompany($this->second, fn () => ContractReminder::whereNull('sent_at')->count()));
    }

    public function test_the_role_that_counts_is_the_one_in_the_contracts_company()
    {
        $person = User::factory()->buyer()->create(['email' => 'person@example.com']);
        Membership::factory()->create(['company_id' => $this->second->id, 'user_id' => $person->id, 'role' => Role::Viewer]);
        $this->inCompany($this->second, fn () => Setting::put(SettingKey::ReminderEmail, 'second-team@example.com'));

        $firstContract = $this->contractWithReminderDueToday($this->first, owner: $person);
        $secondContract = $this->contractWithReminderDueToday($this->second, owner: $person);

        $this->artisan('app:send-contract-reminders');

        $this->assertSame([[$person->id, $firstContract->id]], $this->bellIn($this->first, 'contract_id'));
        Notification::assertSentOnDemandTimes(ContractsNeedAction::class, 1);
        $this->assertContractEmail('second-team@example.com', $this->second, [$secondContract]);
        $this->assertSame([], $this->bellIn($this->second, 'contract_id'));
    }

    public function test_suspended_companies_get_no_reminders_until_reopened()
    {
        $this->contractWithReminderDueToday($this->second, ownerEmail: 'second@example.com');
        $this->second->forceFill(['suspended_at' => now()])->save();

        $this->artisan('app:send-contract-reminders');
        $this->assertSame([], $this->bellIn($this->second, 'contract_id'));

        $this->second->forceFill(['suspended_at' => null])->save();
        $this->artisan('app:send-contract-reminders');
        $this->assertCount(1, $this->bellIn($this->second, 'contract_id'));
    }

    public function test_the_number_of_queries_per_company_does_not_grow_with_its_reminders()
    {
        $owner = $this->inCompany($this->first, fn () => User::factory()->buyer()->create());
        $this->contractWithReminderDueToday($this->first, owner: $owner);
        $queriesForOne = $this->countQueries(fn () => $this->artisan('app:send-contract-reminders')->run());

        foreach (range(1, 5) as $ignored) {
            $this->contractWithReminderDueToday($this->first, owner: $owner);
        }
        $queriesForFive = $this->countQueries(fn () => $this->artisan('app:send-contract-reminders')->run());

        $this->assertSame($queriesForOne, $queriesForFive);
    }

    public function test_certificate_reminders_of_two_companies_never_mix_even_for_the_same_person()
    {
        $person = User::factory()->buyer()->create(['email' => 'person@example.com']);
        Membership::factory()->create(['company_id' => $this->second->id, 'user_id' => $person->id, 'role' => Role::Buyer]);
        $firstCertificate = $this->certificateWithReminderDueToday($this->first, $person);
        $secondCertificate = $this->certificateWithReminderDueToday($this->second, $person);

        $this->artisan('app:send-certificate-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame([[$person->id, $firstCertificate->id]], $this->bellIn($this->first, 'certificate_id'));
        $this->assertSame([[$person->id, $secondCertificate->id]], $this->bellIn($this->second, 'certificate_id'));
    }

    public function test_certificate_reminders_to_the_reminder_email_link_into_their_own_company()
    {
        $this->inCompany($this->first, fn () => Setting::put(SettingKey::ReminderEmail, 'first-team@example.com'));
        $this->inCompany($this->second, fn () => Setting::put(SettingKey::ReminderEmail, 'second-team@example.com'));
        $gone = User::factory()->buyer()->deactivated()->create();
        Membership::factory()->create(['company_id' => $this->second->id, 'user_id' => $gone->id, 'role' => Role::Buyer]);
        $firstCertificate = $this->certificateWithReminderDueToday($this->first, $gone);
        $secondCertificate = $this->certificateWithReminderDueToday($this->second, $gone);

        $this->artisan('app:send-certificate-reminders')->assertSuccessful();

        Notification::assertSentOnDemandTimes(CertificatesExpiring::class, 2);
        $this->assertCertificateEmail('first-team@example.com', $this->first, $firstCertificate);
        $this->assertCertificateEmail('second-team@example.com', $this->second, $secondCertificate);
    }

    public function test_someone_removed_from_a_company_no_longer_gets_its_certificate_reminders()
    {
        $person = User::factory()->buyer()->create(['email' => 'person@example.com']);
        $this->certificateWithReminderDueToday($this->first, $person);
        Setting::put(SettingKey::ReminderEmail, 'first-team@example.com');
        $person->memberships()->update(['deactivated_at' => now()]);

        $this->artisan('app:send-certificate-reminders');

        Notification::assertSentOnDemand(
            CertificatesExpiring::class,
            fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'first-team@example.com',
        );
        Notification::assertSentOnDemandTimes(CertificatesExpiring::class, 1);
    }

    public function test_contracts_are_renewed_in_every_active_company()
    {
        $first = $this->inCompany($this->first, fn () => Contract::factory()->autoRenewing(termMonths: 12)->create(['ends_on' => today()->subDay()]));
        $second = $this->inCompany($this->second, fn () => Contract::factory()->autoRenewing(termMonths: 12)->create(['ends_on' => today()->subDay()]));
        $suspended = Company::factory()->create();
        $inSuspended = $this->inCompany($suspended, fn () => Contract::factory()->autoRenewing(termMonths: 12)->create(['ends_on' => today()->subDay()]));
        $suspended->forceFill(['suspended_at' => now()])->save();

        $this->artisan('app:renew-contracts')->assertSuccessful();

        $renewedEnd = today()->subDay()->addMonthsNoOverflow(12)->toDateString();
        $this->assertSame($renewedEnd, $this->inCompany($this->first, fn () => $first->refresh()->ends_on?->toDateString()));
        $this->assertSame($renewedEnd, $this->inCompany($this->second, fn () => $second->refresh()->ends_on?->toDateString()));
        $this->assertSame(today()->subDay()->toDateString(), $this->inCompany($suspended, fn () => $inSuspended->refresh()->ends_on?->toDateString()));
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function inCompany(Company $company, callable $callback): mixed
    {
        return app(CurrentCompany::class)->runAs($company, fn () => $callback());
    }

    private function contractWithReminderDueToday(Company $company, ?string $ownerEmail = null, ?User $owner = null): Contract
    {
        return $this->inCompany($company, function () use ($ownerEmail, $owner) {
            $owner ??= $ownerEmail === null ? null : User::factory()->buyer()->create(['email' => $ownerEmail]);
            $contract = Contract::factory()->for(Supplier::factory())->endsInDays(14)->create(['owner_user_id' => $owner?->id]);
            $contract->reminders()->create(['days_before' => 14, 'remind_on' => today()]);

            return $contract;
        });
    }

    private function certificateWithReminderDueToday(Company $company, User $responsibleUser): Certificate
    {
        return $this->inCompany($company, function () use ($responsibleUser) {
            $supplier = Supplier::factory()->create(['responsible_user_id' => $responsibleUser->id]);
            $certificate = Certificate::factory()->for($supplier)->for(CertificateType::factory(), 'type')->expiresInDays(14)->create();
            $certificate->reminders()->create(['days_before' => 14, 'remind_on' => today()]);

            return $certificate;
        });
    }

    /**
     * @param  list<Contract>  $contracts
     */
    private function assertContractEmail(string $email, Company $company, array $contracts): void
    {
        $expectedUrls = array_map(
            fn (Contract $contract) => config('app.url')."/{$company->slug}/suppliers/{$contract->supplier_id}/contracts/{$contract->id}",
            $contracts,
        );

        Notification::assertSentOnDemand(
            ContractsNeedAction::class,
            fn (ContractsNeedAction $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === $email
                && array_column($notification->items(), 'url') === $expectedUrls
                && $this->mailBelongsTo($notification->toMail($notifiable), $company),
        );
    }

    private function assertCertificateEmail(string $email, Company $company, Certificate $certificate): void
    {
        $expectedUrl = config('app.url')."/{$company->slug}/suppliers/{$certificate->supplier_id}";

        Notification::assertSentOnDemand(
            CertificatesExpiring::class,
            fn (CertificatesExpiring $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === $email
                && array_column($notification->items(), 'url') === [$expectedUrl]
                && $this->mailBelongsTo($notification->toMail($notifiable), $company),
        );
    }

    /**
     * The subject names the company and the button leads to that company's supplier list, even
     * when the email is sent later by the queue.
     */
    private function mailBelongsTo(MailMessage $mail, Company $company): bool
    {
        return str_starts_with((string) $mail->subject, $company->name.': ')
            && $mail->viewData['url'] === config('app.url')."/{$company->slug}/suppliers";
    }

    /**
     * @return list<array{int, int}> person and record of each bell notification in the company
     */
    private function bellIn(Company $company, string $recordKey): array
    {
        return $this->inCompany($company, fn () => BellNotification::query()
            ->get()
            ->filter(fn (BellNotification $notification) => isset($notification->data[$recordKey]))
            ->map(fn (BellNotification $notification) => [$notification->notifiable_id, $notification->data[$recordKey]])
            ->values()
            ->all());
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
