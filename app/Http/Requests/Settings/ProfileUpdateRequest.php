<?php

namespace App\Http\Requests\Settings;

use App\Concerns\LowercasesInput;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use LowercasesInput, PasswordValidationRules, ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // No "already taken" check on the email: that answer would tell who has an account.
        // RequestEmailChange sends no link to a taken address instead.
        $rules = [
            'name' => $this->nameRules(),
            'email' => ['required', 'string', 'email', 'max:255'],
        ];

        // Whoever controls the email address can reset the password, so changing it needs the
        // password too: a few minutes at someone's unlocked computer must not be enough.
        if ($this->changesEmail()) {
            $rules['current_password'] = $this->currentPasswordRules();
        }

        return $rules;
    }

    public function changesEmail(): bool
    {
        return $this->input('email') !== mb_strtolower($this->user()->email);
    }

    protected function prepareForValidation(): void
    {
        $this->lowercaseInput('email');
    }
}
