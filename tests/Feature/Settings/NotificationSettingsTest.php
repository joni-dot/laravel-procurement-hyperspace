<?php

namespace Tests\Feature\Settings;

use App\Enums\NotificationEmail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_shows_the_persons_email_choice_and_the_choices()
    {
        $user = User::factory()->create();
        $user->forceFill(['notification_email' => NotificationEmail::Off])->save();

        $this->actingAs($user)
            ->get(route('notification-settings.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Notifications')
                ->where('notificationEmail', 'off')
                ->where('emailOptions', [
                    ['value' => 'daily', 'label' => 'Daily summary'],
                    ['value' => 'off', 'label' => 'Off'],
                ])
            );
    }

    public function test_new_people_get_the_daily_summary()
    {
        $this->assertSame(NotificationEmail::Daily, User::factory()->create()->fresh()?->notification_email);
    }

    public function test_a_person_changes_only_their_own_choice()
    {
        $user = User::factory()->create();
        $someoneElse = User::factory()->create();

        $this->actingAs($user)
            ->put(route('notification-settings.update'), ['notification_email' => 'off'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('notification-settings.edit'));

        $this->assertSame(NotificationEmail::Off, $user->fresh()?->notification_email);
        $this->assertSame(NotificationEmail::Daily, $someoneElse->fresh()?->notification_email);

        $this->actingAs($user)
            ->put(route('notification-settings.update'), ['notification_email' => 'daily'])
            ->assertSessionHasNoErrors();

        $this->assertSame(NotificationEmail::Daily, $user->fresh()?->notification_email);
    }

    public function test_only_the_offered_choices_are_accepted()
    {
        $user = User::factory()->create();

        foreach (['weekly', '', null, ['daily']] as $invalid) {
            $this->actingAs($user)
                ->put(route('notification-settings.update'), ['notification_email' => $invalid])
                ->assertSessionHasErrors('notification_email');
        }

        $this->assertSame(NotificationEmail::Daily, $user->fresh()?->notification_email);
    }

    public function test_the_database_refuses_an_unknown_choice()
    {
        $this->expectException(QueryException::class);

        User::query()->whereKey(User::factory()->create()->id)->update(['notification_email' => 'hourly']);
    }

    public function test_the_page_works_outside_every_company()
    {
        $this->actingAs(User::factory()->withoutCompany()->create())
            ->get(route('notification-settings.edit'))
            ->assertOk();
    }

    public function test_guests_are_sent_to_sign_in()
    {
        $this->get(route('notification-settings.edit'))->assertRedirect(route('login'));
        $this->put(route('notification-settings.update'), ['notification_email' => 'off'])->assertRedirect(route('login'));
    }
}
