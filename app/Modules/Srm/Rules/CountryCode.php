<?php

namespace App\Modules\Srm\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\Intl\Countries;

/**
 * A two-letter ISO country code. Same as Rule::in() with every code, but a list of 250 codes is parsed
 * again for every value, which made checking a file of thousands of suppliers slow.
 */
class CountryCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) !== 2 || ! Countries::exists($value)) {
            $fail('validation.in')->translate();
        }
    }
}
