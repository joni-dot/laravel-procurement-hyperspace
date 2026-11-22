<?php

namespace App\Modules\Srm\Suppliers;

use Illuminate\Support\Str;

/**
 * How a supplier contact's fields are tidied and checked, for the contact form and the file import alike.
 */
final class ContactFields
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> only the tidied fields; merge them over the input
     */
    public static function tidy(array $input): array
    {
        $email = $input['email'] ?? null;

        return [
            // Anything that isn't text is left for the rules to refuse.
            'email' => is_string($email) ? (trim($email) === '' ? null : Str::lower($email)) : $email,
            'is_primary' => filter_var($input['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[0-9+()\-\s]+$/'],
            'is_primary' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'phone.regex' => __('The phone number may only contain digits, spaces and + ( ) -.'),
        ];
    }
}
