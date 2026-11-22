<?php

namespace App\Modules\Srm\Demo;

use App\Companies\DemoRandom;

/**
 * Made-up business IDs and VAT numbers in each country's usual format. Finnish ones carry a correct
 * check digit, so editing a demo supplier passes the FinnishBusinessId rule. Each ID is given once.
 */
final class DemoBusinessIds
{
    private const array FINNISH_WEIGHTS = [7, 9, 10, 5, 8, 4, 2];

    /** @var array<string, true> */
    private array $given = [];

    public function __construct(private readonly DemoRandom $random) {}

    /**
     * @return array{business_id: string, vat_number: string}
     */
    public function next(string $countryCode): array
    {
        do {
            $ids = match ($countryCode) {
                'FI' => $this->finnish(),
                'SE' => $this->swedish(),
                'DE' => ['business_id' => 'HRB '.$this->digits(6), 'vat_number' => 'DE'.$this->digits(9)],
                'EE' => ['business_id' => '1'.$this->digits(7), 'vat_number' => 'EE10'.$this->digits(7)],
                'NL' => ['business_id' => $this->digits(8), 'vat_number' => 'NL'.$this->digits(9).'B01'],
                default => ['business_id' => '0000'.$this->digits(6), 'vat_number' => $countryCode.$this->digits(10)],
            };
        } while (isset($this->given["{$countryCode}:{$ids['business_id']}"]));

        $this->given["{$countryCode}:{$ids['business_id']}"] = true;

        return $ids;
    }

    /**
     * A Finnish business ID (Y-tunnus), such as 0112038-9. Seven digits whose weighted sum leaves a
     * remainder of 1 have no valid check digit, so another number is drawn.
     *
     * @return array{business_id: string, vat_number: string}
     */
    private function finnish(): array
    {
        do {
            $digits = $this->int(0, 3).$this->digits(6);
            $checkDigit = self::finnishCheckDigit($digits);
        } while ($checkDigit === null);

        return ['business_id' => "{$digits}-{$checkDigit}", 'vat_number' => "FI{$digits}{$checkDigit}"];
    }

    public static function finnishCheckDigit(string $sevenDigits): ?int
    {
        $sum = 0;

        foreach (str_split($sevenDigits) as $index => $digit) {
            $sum += (int) $digit * self::FINNISH_WEIGHTS[$index];
        }

        return match ($remainder = $sum % 11) {
            0 => 0,
            1 => null,
            default => 11 - $remainder,
        };
    }

    /**
     * A Swedish organisation number of a limited company (55…), whose last digit is a Luhn check digit.
     *
     * @return array{business_id: string, vat_number: string}
     */
    private function swedish(): array
    {
        $digits = '55'.$this->digits(7);
        $number = $digits.self::luhnCheckDigit($digits);

        return ['business_id' => substr($number, 0, 6).'-'.substr($number, 6), 'vat_number' => "SE{$number}01"];
    }

    private static function luhnCheckDigit(string $digits): int
    {
        $sum = 0;

        foreach (str_split(strrev($digits)) as $index => $digit) {
            $value = (int) $digit * ($index % 2 === 0 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return (10 - $sum % 10) % 10;
    }

    private function digits(int $count): string
    {
        $digits = '';

        for ($index = 0; $index < $count; $index++) {
            $digits .= $this->int(0, 9);
        }

        return $digits;
    }

    private function int(int $min, int $max): int
    {
        return $this->random->int($min, $max);
    }
}
