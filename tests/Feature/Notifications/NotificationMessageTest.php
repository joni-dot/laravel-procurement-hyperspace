<?php

namespace Tests\Feature\Notifications;

use App\Notifications\NotificationMessage;
use Tests\TestCase;

class NotificationMessageTest extends TestCase
{
    public function test_dates_stay_dates_so_the_page_can_show_them_in_the_readers_format()
    {
        $message = NotificationMessage::make(__(':package: :milestone was due on :date and isn\'t marked done.'), [
            'package' => 'P1 Pump',
            'milestone' => 'Order placed',
            'date' => NotificationMessage::date('2026-11-03'),
        ]);

        $this->assertSame(['P1 Pump: Order placed was due on ', ['date' => '2026-11-03'], " and isn't marked done."], $message->parts);
        $this->assertSame("P1 Pump: Order placed was due on 2026-11-03 and isn't marked done.", $message->plain());
        $this->assertSame('["P1 Pump: Order placed was due on ",{"date":"2026-11-03"}," and isn\'t marked done."]', json_encode($message));
    }

    public function test_a_message_can_hold_other_messages_with_their_dates()
    {
        $reasons = NotificationMessage::join('; ', [
            NotificationMessage::make('Overdue: :milestone was due on :date', ['milestone' => 'Delivery', 'date' => NotificationMessage::date('2026-11-03')]),
            NotificationMessage::text('No responsible buyer'),
        ]);

        $message = NotificationMessage::make(':package: risk is now :to: :reasons.', ['package' => 'P1', 'to' => 'Red', 'reasons' => $reasons]);

        $this->assertSame(['P1: risk is now Red: Overdue: Delivery was due on ', ['date' => '2026-11-03'], '; No responsible buyer.'], $message->parts);
    }

    public function test_placeholders_fill_like_laravels_and_never_twice()
    {
        $message = NotificationMessage::make(':Name and :NAME and :name, :other', ['name' => 'anna :other', 'other' => 'x']);

        $this->assertSame(['Anna :other and ANNA :OTHER and anna :other, x'], $message->parts);
    }

    public function test_typed_text_cannot_pretend_to_be_a_date()
    {
        $message = NotificationMessage::make('Hello :name, :date', ['name' => "\u{E000}0\u{E001}", 'date' => NotificationMessage::date('2026-11-03')]);

        $this->assertSame(['Hello 0, ', ['date' => '2026-11-03']], $message->parts);
    }
}
