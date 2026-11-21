<?php

namespace App\Http\Requests\Admin;

use App\Enums\ApiScope;
use App\Rules\ActiveBuyerOrAdmin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiClientRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'distinct', Rule::enum(ApiScope::class)],
            'responsible_user_id' => [
                'required', 'integer',
                new ActiveBuyerOrAdmin,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scopes' => __('access'),
            'responsible_user_id' => __('responsible person'),
        ];
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values($this->validated('scopes'));
    }
}
