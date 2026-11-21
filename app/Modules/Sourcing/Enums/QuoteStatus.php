<?php

namespace App\Modules\Sourcing\Enums;

/**
 * Where a supplier stands on a package. At most one supplier per package is chosen: the one it is bought from.
 */
enum QuoteStatus: string
{
    case Asked = 'asked';
    case OfferReceived = 'offer_received';
    case Declined = 'declined';
    case Chosen = 'chosen';

    public function label(): string
    {
        return match ($this) {
            self::Asked => __('Asked for a quote'),
            self::OfferReceived => __('Offer received'),
            self::Declined => __('Declined'),
            self::Chosen => __('Chosen'),
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
