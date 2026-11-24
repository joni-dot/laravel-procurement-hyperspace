<?php

namespace App\Http\Requests\Platform;

use App\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A search among the members of a company whose admins have all left, for the one to make its admin.
 * Platform admins never see a company's people otherwise, so a company with an admin is refused.
 */
class SearchMembersToPromoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ! $company->hasActiveAdmin();
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function search(): string
    {
        return trim((string) $this->validated('search'));
    }
}
