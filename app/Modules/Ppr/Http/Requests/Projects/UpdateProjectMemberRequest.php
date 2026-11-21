<?php

namespace App\Modules\Ppr\Http\Requests\Projects;

use App\Companies\CurrentCompany;
use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\ProjectMember;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectMemberRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(ProjectRole::class)],
        ];
    }

    /**
     * Someone who has left the company can still be made a viewer (or removed), but not be given a role
     * that changes anything, such as project manager.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->enum('role', ProjectRole::class) === ProjectRole::Viewer) {
                return;
            }

            if (! $this->member()->user->isActiveIn(app(CurrentCompany::class)->id())) {
                $validator->errors()->add('role', __('Only active members of the company can get this role.'));
            }
        }];
    }

    private function member(): ProjectMember
    {
        /** @var ProjectMember */
        return $this->route('member');
    }
}
