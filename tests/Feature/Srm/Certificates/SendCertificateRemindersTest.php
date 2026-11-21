<?php

namespace Tests\Feature\Srm\Certificates;

use App\Models\Notification as BellNotification;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Srm\Enums\SettingKey;
use App\Modules\Srm\Models\Certificate;
use App\Modules\Srm\Models\CertificateReminder;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Notifications\CertificatesExpiring;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SendCertificateRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_due_reminders_arrive_in_the_responsible_buyers_bell_one_per_certificate_instead_of_an_email()
    {
        $buyer = User::factory()->buyer()->create(['email' => 'buyer@example.com']);
        $supplier = Supplier::factory()->create(['responsible_user_id' => $buyer->id]);
        $first = $this->certificateWithReminderDueToday($supplier);
        $second = $this->certificateWithReminderDueToday($supplier);
        $second->reminders()->create(['days_before' => 60, 'remind_on' => today()->subDays(46)]);

        $this->artisan('app:send-certificate-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame([[$buyer->id, $first->id], [$buyer->id, $second->id]], $this->bellNotifications());
        $this->assertSame(0, CertificateReminder::whereNull('sent_at')->count());
        $this->assertNotNull($first->reminders()->value('sent_at'));

        $this->artisan('app:send-certificate-reminders');
        $this->assertCount(2, $this->bellNotifications());
    }

    public function test_the_bell_notification_names_the_supplier_the_certificate_and_its_expiry_date()
    {
        $buyer = User::factory()->buyer()->create();
        $supplier = Supplier::factory()->create(['name' => 'Acme Oy', 'responsible_user_id' => $buyer->id]);
        $certificate = Certificate::factory()->for($supplier)->for(CertificateType::factory()->state(['name' => 'ISO 14001']), 'type')->expiresInDays(14)->create();
        $certificate->reminders()->create(['days_before' => 14, 'remind_on' => today()]);

        $this->artisan('app:send-certificate-reminders');

        $stored = BellNotification::query()->sole();
        $this->assertSame('certificate_expiring', $stored->type);
        $this->assertEquals([
            'supplier_id' => $supplier->id,
            'supplier_name' => 'Acme Oy',
            'certificate_id' => $certificate->id,
            'certificate_type' => 'ISO 14001',
            'expires_on' => today()->addDays(14)->toDateString(),
        ], $stored->data);
    }

    public function test_without_a_responsible_buyer_reminders_go_to_the_reminder_email()
    {
        Setting::put(SettingKey::ReminderEmail, 'procurement@example.com');
        $this->certificateWithReminderDueToday(Supplier::factory()->create());

        $this->artisan('app:send-certificate-reminders');

        $this->assertSentOnceTo('procurement@example.com', 1);
    }

    public function test_one_run_tells_responsible_buyers_in_the_bell_and_emails_the_rest_to_the_reminder_email()
    {
        Setting::put(SettingKey::ReminderEmail, 'procurement@example.com');
        $buyer = User::factory()->buyer()->create();
        $theirs = $this->certificateWithReminderDueToday(Supplier::factory()->create(['responsible_user_id' => $buyer->id]));
        $this->certificateWithReminderDueToday(Supplier::factory()->create());
        $this->certificateWithReminderDueToday(Supplier::factory()->create());

        $this->artisan('app:send-certificate-reminders')->assertSuccessful();

        $this->assertSame([[$buyer->id, $theirs->id]], $this->bellNotifications());
        $this->assertSentOnceTo('procurement@example.com', 2);
        $this->assertSame(0, CertificateReminder::whereNull('sent_at')->count());
    }

    public function test_a_deactivated_responsible_buyer_does_not_get_reminders()
    {
        Setting::put(SettingKey::ReminderEmail, 'procurement@example.com');
        $buyer = User::factory()->buyer()->deactivated()->create();
        $this->certificateWithReminderDueToday(Supplier::factory()->create(['responsible_user_id' => $buyer->id]));

        $this->artisan('app:send-certificate-reminders');

        $this->assertSentOnceTo('procurement@example.com', 1);
        $this->assertSame([], $this->bellNotifications());
    }

    public function test_reminders_without_any_recipient_are_kept_for_later_and_logged()
    {
        Log::spy();
        $this->certificateWithReminderDueToday(Supplier::factory()->create());

        $this->artisan('app:send-certificate-reminders');

        Notification::assertNothingSent();
        $this->assertSame(1, CertificateReminder::whereNull('sent_at')->count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_reminders_are_sent_only_once()
    {
        Setting::put(SettingKey::ReminderEmail, 'procurement@example.com');
        $this->certificateWithReminderDueToday(Supplier::factory()->create());

        $this->artisan('app:send-certificate-reminders');
        $this->artisan('app:send-certificate-reminders');

        $this->assertSentOnceTo('procurement@example.com', 1);
    }

    public function test_future_expired_and_archived_cases_are_skipped()
    {
        Setting::put(SettingKey::ReminderEmail, 'procurement@example.com');

        $future = Certificate::factory()->expiresInDays(100)->create();
        $future->reminders()->create(['days_before' => 14, 'remind_on' => today()->addDays(86)]);

        $expired = Certificate::factory()->expired()->create();
        $expired->reminders()->create(['days_before' => 14, 'remind_on' => today()->subDays(15)]);

        $this->certificateWithReminderDueToday(Supplier::factory()->archived()->create());

        $this->artisan('app:send-certificate-reminders');

        Notification::assertNothingSent();
    }

    public function test_the_email_shows_names_exactly_as_typed()
    {
        $supplier = Supplier::factory()->create(['name' => '[Click here](https://evil.example) <b>Oy</b>']);
        $certificate = $this->certificateWithReminderDueToday($supplier);

        $html = (string) (new CertificatesExpiring($this->company->name, collect([$certificate->load('supplier', 'type')])))
            ->toMail(new AnonymousNotifiable)
            ->render();

        $this->assertStringContainsString('[Click here](https://evil.example) &lt;b&gt;Oy&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('href="https://evil.example"', $html);
    }

    public function test_a_name_with_blank_lines_cannot_add_a_link_to_the_email()
    {
        $supplier = Supplier::factory()->create(['name' => "Pump Oy\n\n[Sign in again](https://evil.example/login)"]);
        $certificate = $this->certificateWithReminderDueToday($supplier);

        $html = (string) (new CertificatesExpiring($this->company->name, collect([$certificate->load('supplier', 'type')])))
            ->toMail(new AnonymousNotifiable)
            ->render();

        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringContainsString('Pump Oy [Sign in again](https://evil.example/login)', $html);
    }

    private function certificateWithReminderDueToday(Supplier $supplier): Certificate
    {
        $certificate = Certificate::factory()
            ->for($supplier)
            ->for(CertificateType::factory(), 'type')
            ->expiresInDays(14)
            ->create();

        $certificate->reminders()->create(['days_before' => 14, 'remind_on' => today()]);

        return $certificate;
    }

    /**
     * @return list<array{int, int}> person and certificate of each bell notification
     */
    private function bellNotifications(): array
    {
        return BellNotification::query()
            ->where('type', 'certificate_expiring')
            ->get()
            ->map(fn (BellNotification $notification) => [$notification->notifiable_id, $notification->data['certificate_id']])
            ->sortBy(fn (array $row) => $row[1])
            ->values()
            ->all();
    }

    private function assertSentOnceTo(string $email, int $certificateCount): void
    {
        Notification::assertSentOnDemandTimes(CertificatesExpiring::class, 1);
        Notification::assertSentOnDemand(
            CertificatesExpiring::class,
            fn (CertificatesExpiring $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === $email
                && count($notification->items()) === $certificateCount,
        );
    }
}
