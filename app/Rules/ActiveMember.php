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
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false || ! User::query()->activeInCurrentCompany()->whereKey($id)->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}
