<?php

namespace App\Enums;

/**
 * Whether a person also gets their notifications by email. The choice belongs to the account, so it is the
 * same in every company; each company's notifications come in an email of their own.
 */
enum NotificationEmail: string
{
    // One email each morning per company, listing the unread notifications that arrived since the last one.
    case Daily = 'daily';
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Daily => __('Daily summary'),
            self::Off => __('Off'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $choice) => ['value' => $choice->value, 'label' => $choice->label()],
            self::cases(),
        );
    }
}
