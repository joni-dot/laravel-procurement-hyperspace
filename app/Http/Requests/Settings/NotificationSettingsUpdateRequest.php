<?php

namespace App\Http\Requests\Settings;

use App\Enums\NotificationEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationSettingsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notification_email' => ['required', Rule::enum(NotificationEmail::class)],
        ];
    }
}
