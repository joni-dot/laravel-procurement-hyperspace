<?php

namespace App\Rules;

use App\Models\Company;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A company's address: /{slug}/... It must look right, must not be a path the app itself uses, and
 * must never have belonged to another company (old addresses keep forwarding to their company).
 */
final class CompanySlug implements ValidationRule
{
    /**
     * First path segments that are not a company. Every first segment of a route outside a company
     * must be here (CompanySlugTest checks the route list), plus a few kept for later or because
     * they would confuse people.
     */
    public const array RESERVED = [
        // Routes and files of the app.
        '.well-known', '_inertia', 'api', 'build', 'email', 'forgot-password', 'health', 'hot', 'invitation', 'invitations',
        'login', 'logout', 'oauth', 'passkeys', 'password', 'platform', 'register', 'reset-password',
        'settings', 'storage', 'two-factor-challenge', 'up', 'user',
        // Kept for later, or easy to mistake for a page of the service.
        'account', 'admin', 'app', 'companies', 'dashboard', 'docs', 'help', 'status', 'support', 'www',
    ];

    public function __construct(private readonly ?Company $company = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^'.Company::SLUG_PATTERN.'$/D', $value) !== 1) {
            $fail(__('The :attribute may have only lowercase letters, digits and dashes, must start and end with a letter or digit, and may be at most 40 characters long.'));

            return;
        }

        if (self::isReserved($value)) {
            $fail(__('The :attribute ":input" is used by the service itself. Choose another one.'));

            return;
        }

        if (Company::slugBelongsToAnotherCompany($value, $this->company)) {
            $fail(__('The :attribute ":input" belongs, or used to belong, to another company. Choose another one.'));
        }
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true);
    }
}
