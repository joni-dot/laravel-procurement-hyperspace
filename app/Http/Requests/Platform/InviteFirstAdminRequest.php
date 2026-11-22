<?php

namespace App\Http\Requests\Platform;

use App\Concerns\CompanyValidationRules;
use App\Enums\Role;
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

                if ($company instanceof Company && $company->is_demo && ! $this->isDemoFirstAdmin($company)) {
                    $validator->errors()->add('admin_email', __("A demo company's made-up data names its first admin, so only they can be invited again. Create a new demo company for someone else."));
                }
            },
        ];
    }

    /**
     * Whether the address is the one the demo company was made for: its data names that person as project
     * manager, package owner and so on, so another first admin would find it naming a stranger.
     */
    private function isDemoFirstAdmin(Company $company): bool
    {
        $firstAdmin = $company->memberships()->where('role', Role::Admin)->oldest('id')->first();

        return $firstAdmin !== null
            && mb_strtolower((string) ($firstAdmin->known_email ?? $firstAdmin->user?->email)) === $this->input('admin_email');
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
