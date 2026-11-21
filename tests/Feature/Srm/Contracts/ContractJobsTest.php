<?php

namespace Tests\Feature\Srm\Contracts;

use App\Models\Notification as BellNotification;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Srm\Enums\ContractStatus;
use App\Modules\Srm\Enums\SettingKey;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\ContractReminder;
use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Notifications\ContractsNeedAction;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ContractJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_reminders_arrive_in_the_contract_owners_bell_one_per_contract_instead_of_an_email()
    {
        $owner = User::factory()->buyer()->create(['email' => 'owner@example.com']);
        $first = $this->contractWithReminderDueToday(['owner_user_id' => $owner->id, 'title' => 'Cleaning']);
        $second = $this->contractWithReminderDueToday(['owner_user_id' => $owner->id, 'title' => 'Catering']);
        $second->reminders()->create(['days_before' => 30, 'remind_on' => today()->subDays(16)]);

        $this->artisan('app:send-contract-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame([[$owner->id, $first->id], [$owner->id, $second->id]], $this->bellNotifications());
        $this->assertSame(0, ContractReminder::whereNull('sent_at')->count());

        $this->artisan('app:send-contract-reminders');
        $this->assertCount(2, $this->bellNotifications());
    }

    public function test_the_bell_notification_tells_the_supplier_the_title_and_the_key_date_but_no_price()
    {
        $owner = User::factory()->buyer()->create();
        $supplier = Supplier::factory()->create(['name' => 'Acme Oy']);
        $contract = $this->contractWithReminderDueToday(['owner_user_id' => $owner->id, 'title' => 'Cleaning', 'value' => 987654], $supplier);

        $this->artisan('app:send-contract-reminders');

        $stored = BellNotification::query()->sole();
        $this->assertSame('contract_needs_action', $stored->type);
        $this->assertEquals([
            'supplier_id' => $supplier->id,
            'supplier_name' => 'Acme Oy',
            'contract_id' => $contract->id,
            'contract_title' => 'Cleaning',
            'auto_renews' => false,
            'action_by' => today()->addDays(14)->toDateString(),
        ], $stored->data);
        $this->assertStringNotContainsString('987654', json_encode($stored->data, JSON_THROW_ON_ERROR));
    }

    public function test_without_an_owner_reminders_go_to_the_responsible_buyer_then_the_reminder_email()
    {
        $buyer = User::factory()->buyer()->create(['email' => 'buyer@example.com']);
        $contract = $this->contractWithReminderDueToday([], Supplier::factory()->create(['responsible_user_id' => $buyer->id]));

        $this->artisan('app:send-contract-reminders');
        Notification::assertNothingSent();
        $this->assertSame([[$buyer->id, $contract->id]], $this->bellNotifications());

        Setting::put(SettingKey::ReminderEmail, 'procurement@example.com');
        $this->contractWithReminderDueToday();

        $this->artisan('app:send-contract-reminders');
        $this->assertSentOnceTo('procurement@example.com', 1);
        $this->assertCount(1, $this->bellNotifications());
    }

    public function test_people_who_may_not_see_contracts_never_get_the_email()
    {
        Setting::put(SettingKey::ReminderEmail, 'procurement@example.com');
        $viewer = User::factory()->create(['email' => 'viewer@example.com']);
        $deactivated = User::factory()->buyer()->deactivated()->create(['email' => 'gone@example.com']);
        $this->contractWithReminderDueToday(['owner_user_id' => $viewer->id]);
        $this->contractWithReminderDueToday(['owner_user_id' => $deactivated->id]);

        $this->artisan('app:send-contract-reminders');

        $this->assertSentOnceTo('procurement@example.com', 2);
        $this->assertSame([], $this->bellNotifications());
    }

    public function test_reminders_without_a_recipient_are_kept_and_logged()
    {
        Log::spy();
        $this->contractWithReminderDueToday();

        $this->artisan('app:send-contract-reminders');

        Notification::assertNothingSent();
        $this->assertSame(1, ContractReminder::whereNull('sent_at')->count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_only_due_reminders_of_active_contracts_are_sent_and_only_once()
    {
        Setting::put(SettingKey::ReminderEmail, 'procurement@example.com');

        $this->contractWithReminderDueToday(['status' => ContractStatus::Draft]);
        $this->contractWithReminderDueToday(['status' => ContractStatus::Terminated]);
        $this->contractWithReminderDueToday([], Supplier::factory()->archived()->create());

        $future = Contract::factory()->endsInDays(100)->create();
        $future->reminders()->create(['days_before' => 14, 'remind_on' => today()->addDays(86)]);

        $past = Contract::factory()->endsInDays(-1)->create();
        $past->reminders()->create(['days_before' => 14, 'remind_on' => today()->subDays(15)]);

        $this->artisan('app:send-contract-reminders');
        Notification::assertNothingSent();

        $this->contractWithReminderDueToday();
        $this->artisan('app:send-contract-reminders');
        $this->artisan('app:send-contract-reminders');

        $this->assertSentOnceTo('procurement@example.com', 1);
    }

    public function test_the_email_shows_titles_as_typed_and_leaves_out_prices()
    {
        $contract = $this->contractWithReminderDueToday(['title' => '[Click](https://evil.example) <b>deal</b>', 'value' => 987654]);

        $html = (string) (new ContractsNeedAction($this->company->name, collect([$contract->refresh()->load('supplier')])))
            ->toMail(new AnonymousNotifiable)
            ->render();

        $this->assertStringContainsString('[Click](https://evil.example) &lt;b&gt;deal&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('href="https://evil.example"', $html);
        $this->assertStringNotContainsString('987654', $html);
    }

    public function test_a_title_with_blank_lines_cannot_add_a_link_to_the_email()
    {
        $contract = $this->contractWithReminderDueToday(['title' => "Deal\n\n[Sign in again](https://evil.example/login)"]);

        $html = (string) (new ContractsNeedAction($this->company->name, collect([$contract->refresh()->load('supplier')])))
            ->toMail(new AnonymousNotifiable)
            ->render();

        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringContainsString('Deal [Sign in again](https://evil.example/login)', $html);
    }

    public function test_an_uncancelled_auto_renewing_contract_rolls_forward_and_gets_new_reminders()
    {
        $contract = Contract::factory()->autoRenewing(termMonths: 12, noticeDays: 30)->create(['ends_on' => today()->subDay()]);
        $contract->reminders()->create(['days_before' => 14, 'remind_on' => today()->subDays(45), 'sent_at' => now()]);

        $this->artisan('app:renew-contracts')->assertSuccessful();

        $contract->refresh();
        $reminder = $contract->reminders()->sole();

        $this->assertSame(today()->subDay()->addMonthsNoOverflow(12)->toDateString(), $contract->ends_on?->toDateString());
        $this->assertNull($reminder->sent_at);
        $this->assertSame($contract->action_by?->subDays(14)->toDateString(), $reminder->remind_on->toDateString());
    }

    public function test_a_contract_that_ended_several_terms_ago_catches_up()
    {
        $contract = Contract::factory()->autoRenewing(termMonths: 1)->create(['ends_on' => today()->subMonthsNoOverflow(3)->subDay()]);

        $this->artisan('app:renew-contracts');

        $this->assertFalse($contract->refresh()->ends_on?->isBefore(today()));
        $this->assertTrue($contract->ends_on?->isBefore(today()->addMonthNoOverflow()->addDay()));
    }

    public function test_other_contracts_are_not_renewed()
    {
        $ended = Contract::factory()->endsInDays(-1)->create();
        $terminated = Contract::factory()->autoRenewing()->endsInDays(-1)->create(['status' => ContractStatus::Terminated]);
        $running = Contract::factory()->autoRenewing()->endsInDays(10)->create();

        $this->artisan('app:renew-contracts');

        $this->assertSame(today()->subDay()->toDateString(), $ended->refresh()->ends_on?->toDateString());
        $this->assertSame(today()->subDay()->toDateString(), $terminated->refresh()->ends_on?->toDateString());
        $this->assertSame(today()->addDays(10)->toDateString(), $running->refresh()->ends_on?->toDateString());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contractWithReminderDueToday(array $attributes = [], ?Supplier $supplier = null): Contract
    {
        $contract = Contract::factory()
            ->for($supplier ?? Supplier::factory())
            ->endsInDays(14)
            ->create($attributes);

        $contract->reminders()->create(['days_before' => 14, 'remind_on' => today()]);

        return $contract;
    }

    /**
     * @return list<array{int, int}> person and contract of each bell notification
     */
    private function bellNotifications(): array
    {
        return BellNotification::query()
            ->where('type', 'contract_needs_action')
            ->get()
            ->map(fn (BellNotification $notification) => [$notification->notifiable_id, $notification->data['contract_id']])
            ->sortBy(fn (array $row) => $row[1])
            ->values()
            ->all();
    }

    private function assertSentOnceTo(string $email, int $contractCount): void
    {
        Notification::assertSentOnDemandTimes(ContractsNeedAction::class, 1);
        Notification::assertSentOnDemand(
            ContractsNeedAction::class,
            fn (ContractsNeedAction $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === $email
                && count($notification->items()) === $contractCount,
        );
    }
}
