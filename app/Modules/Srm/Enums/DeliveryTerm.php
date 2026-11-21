<?php

namespace App\Modules\Srm\Enums;

/**
 * Incoterms 2020: the standard rules for who pays for and carries the risk of a delivery.
 */
enum DeliveryTerm: string
{
    case Exw = 'EXW';
    case Fca = 'FCA';
    case Cpt = 'CPT';
    case Cip = 'CIP';
    case Dap = 'DAP';
    case Dpu = 'DPU';
    case Ddp = 'DDP';
    case Fas = 'FAS';
    case Fob = 'FOB';
    case Cfr = 'CFR';
    case Cif = 'CIF';

    public function label(): string
    {
        $description = match ($this) {
            self::Exw => __('Ex Works'),
            self::Fca => __('Free Carrier'),
            self::Cpt => __('Carriage Paid To'),
            self::Cip => __('Carriage and Insurance Paid To'),
            self::Dap => __('Delivered at Place'),
            self::Dpu => __('Delivered at Place Unloaded'),
            self::Ddp => __('Delivered Duty Paid'),
            self::Fas => __('Free Alongside Ship'),
            self::Fob => __('Free on Board'),
            self::Cfr => __('Cost and Freight'),
            self::Cif => __('Cost, Insurance and Freight'),
        };

        return "{$this->value} – {$description}";
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $term) => ['value' => $term->value, 'label' => $term->label()],
            self::cases(),
        );
    }
}
