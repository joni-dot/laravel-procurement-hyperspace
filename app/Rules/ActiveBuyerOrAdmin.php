<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The value must be a person who can be made responsible for something in the current company:
 * an active admin or buyer there, with an active account.
 */
class ActiveBuyerOrAdmin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! User::query()->activeBuyersAndAdmins()->whereKey((int) $value)->exists()) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
