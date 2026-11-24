<?php

namespace App\Http\Requests\Platform;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Which member becomes the admin of a company whose admins have all left. Whether they may is checked
 * while the company is locked (PromoteMemberToAdmin).
 */
class PromoteMemberRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'membership_id' => ['required', 'integer'],
        ];
    }
}
