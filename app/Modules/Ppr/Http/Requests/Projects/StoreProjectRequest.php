<?php

namespace App\Modules\Ppr\Http\Requests\Projects;

use App\Modules\Ppr\Concerns\ProjectValidationRules;
use App\Rules\ActiveMember;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    use ProjectValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->projectDetailRules(),
            // Every project starts with a project manager, so someone besides the admins can run it.
            'manager_id' => ['required', 'integer', new ActiveMember],
        ];
    }
}
