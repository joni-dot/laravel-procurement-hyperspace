<?php

namespace App\Http\Requests\Platform;

use App\Concerns\CompanyValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    use CompanyValidationRules;

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
        $this->merge([
            'slug' => mb_strtolower(trim((string) $this->input('slug'))),
            'admin_email' => mb_strtolower(trim((string) $this->input('admin_email'))),
        ]);
    }
}
