<?php

namespace App\Modules\Srm\Http\Requests\Suppliers;

use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Suppliers\Import\ImportFileRefused;
use App\Modules\Srm\Suppliers\Import\SupplierImportPlan;
use App\Modules\Srm\Suppliers\Import\SupplierImportPlanner;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * A supplier file to preview or import. The file is read straight from the upload and never stored.
 */
class SupplierImportRequest extends FormRequest
{
    public const int MAX_KILOBYTES = 5 * 1024;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'extensions:csv,txt,xlsx'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => __('The file may be at most 5 MB.'),
            'file.extensions' => __('The file must be a CSV file or an Excel workbook (.xlsx).'),
        ];
    }

    /**
     * @throws ValidationException when the file as a whole can't be imported
     */
    public function plan(SupplierImportPlanner $planner): SupplierImportPlan
    {
        try {
            return $planner->plan($this->importFile(), $this->user()->can('manage', Category::class));
        } catch (ImportFileRefused $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }
    }

    public function importFile(): UploadedFile
    {
        $file = $this->file('file');

        assert($file instanceof UploadedFile);

        return $file;
    }
}
