<?php

namespace App\Modules\Srm\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reminder_email' => ['nullable', 'string', 'email', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reminder_email' => $this->tidyEmail($this->input('reminder_email')),
        ]);
    }

    /**
     * Anything other than text is left as it is, for the rules to refuse.
     */
    private function tidyEmail(mixed $email): mixed
    {
        if (! is_string($email)) {
            return $email;
        }

        return trim($email) === '' ? null : Str::lower($email);
    }
}
