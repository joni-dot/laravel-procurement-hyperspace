<?php

namespace App\Concerns;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    public const int MAX_PASSWORD_BYTES = 72;

    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Closure|Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed', $this->fitsTheHash(...)];
    }

    /**
     * bcrypt reads only the first 72 bytes, so the rest of a longer password would quietly not count.
     */
    private function fitsTheHash(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > self::MAX_PASSWORD_BYTES) {
            $fail(__('The password is too long. Use at most :bytes bytes (fewer characters if it has accents or other special letters).', ['bytes' => self::MAX_PASSWORD_BYTES]));
        }
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
