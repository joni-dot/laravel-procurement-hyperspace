<?php

namespace App\Modules\Srm\Http\Requests\Suppliers;

use App\Modules\Srm\Models\Supplier;
use App\Modules\Srm\Suppliers\SupplierFields;
use App\Rules\ActiveBuyerOrAdmin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used both for creating and for updating a supplier. The fields' own rules live in SupplierFields,
 * shared with the file import; this adds the checks that look at the database.
 */
class SupplierRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $supplier = $this->route('supplier');
        $supplierId = $supplier instanceof Supplier ? $supplier->id : null;
        $rules = SupplierFields::rules();

        $rules['supplier_number'][] = Rule::unique(Supplier::class)->ignore($supplierId);
        $rules['responsible_user_id'][] = new ActiveBuyerOrAdmin;
        $rules['business_id'][] = Rule::unique(Supplier::class)->where('country_code', $this->input('country_code'))->ignore($supplierId);

        return [
            ...$rules,
            'category_ids' => ['array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return SupplierFields::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return SupplierFields::attributes();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(SupplierFields::tidy($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierAttributes(): array
    {
        return $this->safe()->except('category_ids');
    }

    /**
     * @return list<int>
     */
    public function categoryIds(): array
    {
        return array_values(array_map(intval(...), $this->validated('category_ids', [])));
    }
}
