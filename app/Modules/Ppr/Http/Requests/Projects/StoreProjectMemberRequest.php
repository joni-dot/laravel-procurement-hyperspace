<?php

namespace App\Modules\Ppr\Http\Requests\Projects;

use App\Modules\Ppr\Enums\ProjectRole;
use App\Modules\Ppr\Models\Project;
use App\Rules\ActiveMember;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectMemberRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Project $project */
        $project = $this->route('project');

        return [
            'user_id' => [
                'required',
                'integer',
                new ActiveMember,
                Rule::unique('project_members', 'user_id')->where('project_id', $project->id),
            ],
            'role' => ['required', Rule::enum(ProjectRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.unique' => __('This person is already a member of the project.'),
        ];
    }
}
