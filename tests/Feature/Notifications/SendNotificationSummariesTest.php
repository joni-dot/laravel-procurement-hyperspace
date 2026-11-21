<?php

namespace Tests\Feature\Notifications;

use App\Companies\CurrentCompany;
use App\Enums\NotificationEmail;
use App\Enums\Role;
use App\Models\Company;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Srm\Notifications\CertificateExpiringNotifications;
use App\Modules\Srm\Notifications\ContractNeedsActionNotifications;
use App\Notifications\NotificationKind;
use App\Notifications\NotificationKinds;
use App\Notifications\NotificationSummary;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * The daily summary email: one per person and company, listing the unread notifications they may still see
 * that arrived since their last summary there.
 */
class SendNotificationSummariesTest extends TestCase
{
    use RefreshDatabase;

    private User $person;

    protected function setUp(): void
    {
        parent::setUp();

        NotificationFacade::fake();
        $this->travelTo('2027-03-10 06:30:00');
        $this->person = User::factory()->buyer()->create();
    }

    public function test_a_person_gets_one_email_naming_the_company_and_listing_new_notifications_newest_first()
    {
        $older = $this->certificateNotification($this->person, 'Older Oy', minutesAgo: 120);
        $newer = $this->certificateNotification($this->person, 'Newer Oy', minutesAgo: 60);

        $this->artisan('notifications:send-summaries')->assertSuccessful();

        NotificationFacade::assertSentToTimes($this->person, NotificationSummary::class, 1);
        $summary = $this->summaryOf($this->person);
        $mail = $summary->toMail($this->person);
        $this->assertSame("{$this->company->name}: 2 new notifications", $mail->subject);
        $this->assertSame([
            'Newer Oy: the ISO 9001 certificate expires on 2027-04-01.',
            'Older Oy: the ISO 9001 certificate expires on 2027-04-01.',
        ], array_column($summary->items(), 'message'));
        $this->assertSame(['Suppliers', 'Suppliers'], array_column($summary->items(), 'source'));
        $this->assertSame([$this->showUrl($newer), $this->showUrl($older)], array_column($summary->items(), 'url'));
    }

    public function test_links_come_from_app_url_and_lead_into_the_company()
    {
        config(['app.url' => 'https://procurement.example']);
        $notification = $this->certificateNotification($this->person, 'Acme Oy');

        $this->artisan('notifications:send-summaries');

        $summary = $this->summaryOf($this->person);
        $this->assertSame(["https://procurement.example/{$this->company->slug}/notifications/{$notification->id}"], array_column($summary->items(), 'url'));
        $this->assertSame("https://procurement.example/{$this->company->slug}/notifications", $summary->listUrl());
    }

    public function test_each_notification_is_summarized_once_and_later_ones_come_the_next_day()
    {
        $this->certificateNotification($this->person, 'First Oy');
        $this->artisan('notifications:send-summaries');
        $this->artisan('notifications:send-summaries');
        NotificationFacade::assertSentToTimes($this->person, NotificationSummary::class, 1);

        $this->travel(1)->days();
        $this->certificateNotification($this->person, 'Second Oy');
        NotificationFacade::fake();
        $this->artisan('notifications:send-summaries');

        $this->assertSame(['Second Oy: the ISO 9001 certificate expires on 2027-04-01.'], array_column($this->summaryOf($this->person)->items(), 'message'));
    }

    public function test_a_notification_that_just_arrived_waits_for_the_next_summary()
    {
        $this->certificateNotification($this->person, 'Brand New Oy', minutesAgo: 0);

        $this->artisan('notifications:send-summaries');
        NotificationFacade::assertNothingSent();

        $this->travel(1)->days();
        $this->artisan('notifications:send-summaries');
        NotificationFacade::assertSentToTimes($this->person, NotificationSummary::class, 1);
    }

    public function test_read_notifications_and_those_the_person_may_no_longer_see_are_left_out()
    {
        $this->certificateNotification($this->person, 'Read Oy')->markAsRead();
        $this->contractNotification($this->person);
        $this->certificateNotification($this->person, 'Unread Oy');
        $this->stored($this->person, 'mystery_kind', []);
        $this->person->memberships()->update(['role' => Role::Viewer]);

        $this->artisan('notifications:send-summaries');

        $summary = $this->summaryOf($this->person);
        $this->assertSame(['Unread Oy: the ISO 9001 certificate expires on 2027-04-01.'], array_column($summary->items(), 'message'));
        $this->assertSame("{$this->company->name}: 1 new notification", $summary->toMail($this->person)->subject);
    }

    public function test_nobody_is_emailed_about_notifications_they_may_not_see()
    {
        $viewer = User::factory()->create();
        $this->contractNotification($viewer);

        $this->artisan('notifications:send-summaries')->assertSuccessful();

        NotificationFacade::assertNothingSent();
    }

    public function test_people_who_chose_no_email_get_none_and_turning_it_on_brings_only_newer_notifications()
    {
        $this->person->forceFill(['notification_email' => NotificationEmail::Off])->save();
        $this->certificateNotification($this->person, 'Old Oy');

        $this->artisan('notifications:send-summaries');
        NotificationFacade::assertNothingSent();

        $this->person->forceFill(['notification_email' => NotificationEmail::Daily])->save();
        $this->travel(1)->days();
        $this->certificateNotification($this->person, 'New Oy');
        $this->artisan('notifications:send-summaries');

        $this->assertSame(['New Oy: the ISO 9001 certificate expires on 2027-04-01.'], array_column($this->summaryOf($this->person)->items(), 'message'));
    }

    public function test_deactivated_people_get_none()
    {
        $leftCompany = User::factory()->buyer()->create();
        $closedAccount = User::factory()->buyer()->create();
        $this->certificateNotification($leftCompany, 'Acme Oy');
        $this->certificateNotification($closedAccount, 'Acme Oy');
        $leftCompany->memberships()->update(['deactivated_at' => now()]);
        $closedAccount->forceFill(['deactivated_at' => now()])->save();

        $this->artisan('notifications:send-summaries');

        NotificationFacade::assertNothingSent();
    }

    public function test_each_company_sends_its_own_summary()
    {
        $other = Company::factory()->create(['name' => 'Other Oy']);
        Membership::factory()->create(['company_id' => $other->id, 'user_id' => $this->person->id, 'role' => Role::Buyer]);
        $this->certificateNotification($this->person, 'Here Oy');
        app(CurrentCompany::class)->runAs($other, fn () => $this->certificateNotification($this->person, 'There Oy'));

        $this->artisan('notifications:send-summaries')->assertSuccessful();

        NotificationFacade::assertSentToTimes($this->person, NotificationSummary::class, 2);
        $subjects = [];
        NotificationFacade::assertSentTo($this->person, NotificationSummary::class, function (NotificationSummary $summary) use (&$subjects) {
            $subjects[(string) $summary->toMail($this->person)->subject] = array_column($summary->items(), 'message');

            return true;
        });
        $this->assertEquals([
            "{$this->company->name}: 1 new notification" => ['Here Oy: the ISO 9001 certificate expires on 2027-04-01.'],
            'Other Oy: 1 new notification' => ['There Oy: the ISO 9001 certificate expires on 2027-04-01.'],
        ], $subjects);
    }

    public function test_a_long_list_is_cut_short_and_the_rest_counted()
    {
        foreach (range(1, NotificationSummary::MAX_LISTED + 5) as $number) {
            $this->certificateNotification($this->person, sprintf('Supplier %02d', $number), minutesAgo: 200 - $number);
        }

        $this->artisan('notifications:send-summaries');

        $summary = $this->summaryOf($this->person);
        $mail = $summary->toMail($this->person);
        $this->assertCount(NotificationSummary::MAX_LISTED, $summary->items());
        $this->assertStringStartsWith('Supplier 25:', $summary->items()[0]['message']);
        $this->assertSame(5, $mail->viewData['more']);
        $this->assertSame("{$this->company->name}: 25 new notifications", $mail->subject);
        $this->assertStringContainsString('And 5 more.', (string) $mail->render());
    }

    public function test_the_email_shows_typed_names_as_text()
    {
        $this->certificateNotification($this->person, '[Click here](https://evil.example) <b>Oy</b>');

        $this->artisan('notifications:send-summaries');

        $html = (string) $this->summaryOf($this->person)->toMail($this->person)->render();
        $this->assertStringContainsString('[Click here](https://evil.example) &lt;b&gt;Oy&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('href="https://evil.example"', $html);
    }

    public function test_a_failing_company_does_not_stop_the_others_and_loses_nothing()
    {
        app(NotificationKinds::class)->register(new class implements NotificationKind
        {
            public function types(): array
            {
                return ['exploding_kind'];
            }

            public function onlyVisibleTo(Builder $query, User $user): void {}

            public function describe(Collection $notifications): array
            {
                throw new RuntimeException('Broken data.');
            }

            public function destination(Notification $notification): string
            {
                return '/';
            }
        });
        $other = Company::factory()->create();
        $there = app(CurrentCompany::class)->runAs($other, fn () => User::factory()->buyer()->create());
        $this->stored($this->person, 'exploding_kind', [], minutesAgo: 60);
        app(CurrentCompany::class)->runAs($other, fn () => $this->certificateNotification($there, 'There Oy'));

        $this->withoutExceptionHandling();
        $this->artisan('notifications:send-summaries')->assertFailed();

        NotificationFacade::assertSentToTimes($there, NotificationSummary::class, 1);
        NotificationFacade::assertNotSentTo($this->person, NotificationSummary::class);
        $this->assertNull($this->person->memberships()->value('notifications_summarized_at'));
    }

    public function test_the_summary_is_queued_after_the_save_and_scheduled_after_the_morning_reminders()
    {
        $this->certificateNotification($this->person, 'Acme Oy');
        $this->artisan('notifications:send-summaries');

        $summary = $this->summaryOf($this->person);
        $this->assertInstanceOf(ShouldQueue::class, $summary);
        $this->assertTrue($summary->afterCommit);
        $this->assertSame(['mail'], $summary->via($this->person));

        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event) => str_contains((string) $event->command, 'notifications:send-summaries'));
        $this->assertCount(1, $events);
        $this->assertSame('30 6 * * *', $events->first()->expression);
        $this->assertSame('UTC', $events->first()->timezone);
    }

    private function summaryOf(User $user): NotificationSummary
    {
        $found = null;
        NotificationFacade::assertSentTo($user, NotificationSummary::class, function (NotificationSummary $summary) use (&$found) {
            $found = $summary;

            return true;
        });

        return $found ?? $this->fail('No summary was sent.');
    }

    private function showUrl(Notification $notification): string
    {
        return rtrim((string) config('app.url'), '/')."/{$this->company->slug}/notifications/{$notification->id}";
    }

    public function test_a_typed_name_with_blank_lines_cannot_add_a_link()
    {
        // A blank line would end the email's HTML block, and the rest would be read as Markdown.
        $this->certificateNotification($this->person, "Pump Oy\n\n[Your session expired, sign in again](https://evil.example/login)\n\n");

        $this->artisan('notifications:send-summaries');

        $html = (string) $this->summaryOf($this->person)->toMail($this->person)->render();
        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringContainsString('Pump Oy [Your session expired, sign in again](https://evil.example/login)', $html);
    }

    private function certificateNotification(User $user, string $supplier, int $minutesAgo = 30): Notification
    {
        return $this->stored($user, CertificateExpiringNotifications::TYPE, [
            'supplier_id' => 1,
            'supplier_name' => $supplier,
            'certificate_id' => 1,
            'certificate_type' => 'ISO 9001',
            'expires_on' => '2027-04-01',
        ], $minutesAgo);
    }

    private function contractNotification(User $user): Notification
    {
        return $this->stored($user, ContractNeedsActionNotifications::TYPE, [
            'supplier_id' => 1,
            'supplier_name' => 'Contract Oy',
            'contract_id' => 1,
            'contract_title' => 'Cleaning',
            'auto_renews' => false,
            'action_by' => '2027-04-01',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stored(User $user, string $type, array $data, int $minutesAgo = 30): Notification
    {
        $notification = new Notification;
        $notification->forceFill([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => $data,
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ])->save();

        return $notification;
    }
}
