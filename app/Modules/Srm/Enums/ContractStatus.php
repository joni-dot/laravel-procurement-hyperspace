<?php

namespace App\Modules\Srm\Enums;

/**
 * What people decide. "Expired" and "ending soon" are worked out from dates; see ContractHealth.
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Active => __('Active'),
            self::Terminated => __('Terminated'),
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
