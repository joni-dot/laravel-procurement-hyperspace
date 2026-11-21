<?php

namespace App\Modules\Srm\Enums;

enum SupplierStatus: string
{
    case Prospect = 'prospect';
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Prospect => __('Prospect'),
            self::Active => __('Active'),
            self::Inactive => __('Inactive'),
            self::Blocked => __('Blocked'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
