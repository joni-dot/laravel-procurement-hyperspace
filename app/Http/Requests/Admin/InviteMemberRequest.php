<?php

namespace App\Http\Requests\Admin;

use App\Companies\CurrentCompany;
use App\Concerns\LowercasesInput;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\Membership;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
{
    use LowercasesInput, ProfileValidationRules;

    /**
     * Whether the address has an account is never checked: inviting someone with an account and
     * someone without must look the same to the inviter.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => ['required', 'string', 'email', 'max:255', $this->notAlreadyAMember(...)],
            'role' => ['required', Rule::enum(Role::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->lowercaseInput('email');
    }

    /**
     * Someone with a pending invitation simply gets a new one. Someone who has joined (even if
     * deactivated since) is already on the company's list, so saying so reveals nothing new.
     */
    private function notAlreadyAMember(string $attribute, mixed $email, Closure $fail): void
    {
        $isMember = Membership::query()
            ->where('company_id', app(CurrentCompany::class)->id())
            ->whereNotNull('joined_at')
            ->whereHas('user', fn ($users) => $users->where('email', $email))
            ->exists();

        if ($isMember) {
            $fail(__('This person is already a member of this company.'));
        }
    }
}
