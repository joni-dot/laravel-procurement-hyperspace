<?php

namespace App\Modules\Srm\Enums;

enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public const float HIGH_RISK_RATING_BELOW = 2.5;

    public const float MEDIUM_RISK_RATING_BELOW = 3.5;

    public function label(): string
    {
        return match ($this) {
            self::Low => __('Low'),
            self::Medium => __('Medium'),
            self::High => __('High'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $level) => ['value' => $level->value, 'label' => $level->label()],
            self::cases(),
        );
    }
}
