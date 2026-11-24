<?php

namespace App\Modules\Srm\Http\Requests\Suppliers;

use App\Concerns\DateValidationRules;
use App\Concerns\ShortensLongLists;
use App\Modules\Srm\Models\CertificateType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class CertificateRequest extends FormRequest
{
    use DateValidationRules;
    use ShortensLongLists;

    public const int MAX_FILE_SIZE_KB = 10 * 1024;

    public const int MAX_REMINDERS = 5;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'certificate_type_id' => ['required', 'integer', Rule::exists(CertificateType::class, 'id')],
            'reference' => ['nullable', 'string', 'max:100'],
            'issued_on' => ['nullable', ...$this->dateRules()],
            'expires_on' => ['nullable', ...$this->dateRules(), 'after_or_equal:issued_on'],
            'reminder_days' => ['array', 'max:'.self::MAX_REMINDERS],
            'reminder_days.*' => ['integer', 'between:1,365', 'distinct'],
            // The name's extension is checked too, because the file is downloaded under its name.
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:'.self::MAX_FILE_SIZE_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'certificate_type_id' => __('certificate type'),
            'issued_on' => __('issue date'),
            'expires_on' => __('expiry date'),
            'reminder_days.*' => __('reminder'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->shortenLongLists(['reminder_days' => self::MAX_REMINDERS]);
        $this->keepDatesText('issued_on', 'expires_on');
    }

    /**
     * @return array<string, mixed>
     */
    public function certificateAttributes(): array
    {
        return $this->safe()->only(['certificate_type_id', 'reference', 'issued_on', 'expires_on']);
    }

    /**
     * @return list<int>
     */
    public function reminderDays(): array
    {
        return array_values(array_map(intval(...), $this->validated('reminder_days', [])));
    }

    public function uploadedFile(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }
}
