<?php

namespace App\Http\Requests\Platform;

use App\Concerns\CompanyValidationRules;
use App\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InviteFirstAdminRequest extends FormRequest
{
    use CompanyValidationRules;

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return $this->firstAdminRules();
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $company = $this->route('company');

                if ($company instanceof Company && $company->hasActiveAdmin()) {
                    $validator->errors()->add('admin_email', __('This company already has an admin, who invites people.'));
                }
            },
        ];
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
        $this->merge(['admin_email' => mb_strtolower(trim((string) $this->input('admin_email')))]);
    }
}
