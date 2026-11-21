<?php

namespace App\Modules\Sourcing\Http\Requests;

use App\Modules\Ppr\Models\Package;
use App\Modules\Sourcing\Enums\QuoteStatus;
use App\Modules\Sourcing\Models\PackageSupplier;
use App\Modules\Srm\Enums\SupplierStatus;
use App\Modules\Srm\Models\Supplier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePackageSupplierRequest extends FormRequest
{
    public const int MAX_NOTE_LENGTH = 500;

    private ?Supplier $supplier = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::enum(QuoteStatus::class)],
            'note' => ['nullable', 'string', 'max:'.self::MAX_NOTE_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.*' => __('Choose a supplier from the list.'),
            'note.max' => __('Keep the note to :max characters or fewer.', ['max' => self::MAX_NOTE_LENGTH]),
        ];
    }

    /**
     * Only the company's own suppliers that may be bought from, each once per package. Another company's
     * supplier gets the same answer as one that doesn't exist, so nothing about it is revealed.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('supplier_id')) {
                return;
            }

            $this->supplier = Supplier::query()->find($this->integer('supplier_id'));
            $errors = $validator->errors();

            if ($this->supplier === null) {
                $errors->add('supplier_id', __('Choose a supplier from the list.'));
            } elseif ($this->supplier->isArchived()) {
                $errors->add('supplier_id', __('Archived suppliers cannot be added.'));
            } elseif ($this->supplier->status === SupplierStatus::Blocked) {
                $errors->add('supplier_id', __('Blocked suppliers cannot be added.'));
            } elseif ($this->isAlreadyOnThePackage($this->supplier)) {
                $errors->add('supplier_id', __('This supplier is already on the package.'));
            }
        }];
    }

    /**
     * The validated supplier.
     */
    public function supplier(): Supplier
    {
        return $this->supplier ??= Supplier::query()->findOrFail($this->integer('supplier_id'));
    }

    private function isAlreadyOnThePackage(Supplier $supplier): bool
    {
        /** @var Package $package */
        $package = $this->route('package');

        return PackageSupplier::query()->where('package_id', $package->id)->where('supplier_id', $supplier->id)->exists();
    }
}
