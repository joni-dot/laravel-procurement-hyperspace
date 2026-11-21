<?php

namespace App\Modules\Sourcing\Http\Requests;

use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Enums\SupplierStatus;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePackageSupplierRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(QuoteStatus::class)],
            'note' => ['nullable', 'string', 'max:'.StorePackageSupplierRequest::MAX_NOTE_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.max' => __('Keep the note to :max characters or fewer.', ['max' => StorePackageSupplierRequest::MAX_NOTE_LENGTH]),
        ];
    }

    /**
     * A supplier archived or blocked after it was added stays listed, but can't become the chosen one.
     * One already chosen keeps its status, so its note can still be changed.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $candidate = $this->candidate();

            if ($validator->errors()->has('status')
                || $this->enum('status', QuoteStatus::class) !== QuoteStatus::Chosen
                || $candidate->status === QuoteStatus::Chosen) {
                return;
            }

            $supplier = $candidate->supplier;

            if ($supplier->isArchived()) {
                $validator->errors()->add('status', __('Archived suppliers cannot be chosen.'));
            } elseif ($supplier->status === SupplierStatus::Blocked) {
                $validator->errors()->add('status', __('Blocked suppliers cannot be chosen.'));
            }
        }];
    }

    private function candidate(): PackageSupplier
    {
        /** @var PackageSupplier */
        return $this->route('packageSupplier');
    }
}
