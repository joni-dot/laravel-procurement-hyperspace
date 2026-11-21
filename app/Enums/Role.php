<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Buyer = 'buyer';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Buyer => __('Buyer'),
            self::Viewer => __('Viewer'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }
}
