<?php

namespace App\Http\Requests\Platform;

use App\Concerns\CompanyValidationRules;
use App\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    use CompanyValidationRules;

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $company = $this->route('company');

        // The company may keep its address, or go back to one it had before.
        return $this->companyRules($company instanceof Company ? $company : null);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->companyAttributes();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => mb_strtolower(trim((string) $this->input('slug')))]);
    }
}
