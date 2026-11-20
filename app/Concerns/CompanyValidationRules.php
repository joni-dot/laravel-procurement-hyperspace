<?php

namespace App\Concerns;

use App\Models\Company;
use App\Rules\CompanySlug;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The rules for a company and its first admin, shared by the platform pages and the
 * app:create-company command.
 */
trait CompanyValidationRules
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    protected function companyRules(?Company $company = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', new CompanySlug($company)],
        ];
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    protected function firstAdminRules(): array
    {
        return [
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function companyAttributes(): array
    {
        return [
            'slug' => 'address',
            'admin_name' => "admin's name",
            'admin_email' => "admin's email",
        ];
    }
}
