<?php

namespace App\Modules\Srm\Http\Requests\Suppliers;

use App\Modules\Srm\Suppliers\ContactFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ContactFields::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ContactFields::messages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ContactFields::tidy($this->all()));
    }
}
