<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The value must be a person who can work in the current company now: an active member there,
 * with an active account.
 */
class ActiveMember implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! User::query()->activeInCurrentCompany()->whereKey((int) $value)->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}
