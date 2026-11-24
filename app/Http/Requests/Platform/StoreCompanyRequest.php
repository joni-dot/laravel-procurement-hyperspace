<?php

namespace App\Http\Requests\Platform;

use App\Concerns\CompanyValidationRules;
use App\Concerns\LowercasesInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    use CompanyValidationRules, LowercasesInput;

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [...$this->companyRules(), ...$this->firstAdminRules()];
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
        $this->lowercaseInput('slug', 'admin_email');
    }
}
