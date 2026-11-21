<?php

namespace App\Modules\Ppr\Http\Requests\Admin;

use App\Modules\Ppr\Concerns\RiskWarningRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SettingsRequest extends FormRequest
{
    use RiskWarningRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->riskWarningRules();
    }
}
