<?php

namespace App\Modules\Srm\Http\Requests\Suppliers;

use App\Concerns\DateValidationRules;
use App\Concerns\ShortensLongLists;
use App\Modules\Srm\Enums\ContractStatus;
use App\Modules\Srm\Enums\ContractValuePeriod;
use App\Modules\Srm\Enums\Currency;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\ContractType;
use App\Rules\ActiveBuyerOrAdmin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractRequest extends FormRequest
{
    use DateValidationRules;
    use ShortensLongLists;

    public const int MAX_REMINDERS = 5;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $contract = $this->route('contract');

        return [
            'title' => ['required', 'string', 'max:255'],
            'contract_number' => ['nullable', 'string', 'max:50', Rule::unique(Contract::class)->ignore($contract instanceof Contract ? $contract->id : null)],
            'contract_type_id' => ['required', 'integer', Rule::exists(ContractType::class, 'id')],
            'status' => ['required', Rule::enum(ContractStatus::class)],
            'owner_user_id' => [
                'nullable', 'integer',
                new ActiveBuyerOrAdmin,
            ],
            'starts_on' => ['nullable', ...$this->dateRules()],
            'ends_on' => ['nullable', ...$this->dateRules(), 'after_or_equal:starts_on', 'required_if_accepted:auto_renews'],
            'auto_renews' => ['boolean'],
            'renewal_term_months' => ['nullable', 'integer', 'between:1,120', 'required_if_accepted:auto_renews'],
            'notice_period_days' => ['nullable', 'integer', 'between:0,730'],
            'value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'currency_code' => ['nullable', Rule::enum(Currency::class), 'required_with:value'],
            'value_period' => ['nullable', Rule::enum(ContractValuePeriod::class), 'required_with:value'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'reminder_days' => ['array', 'max:'.self::MAX_REMINDERS],
            'reminder_days.*' => ['integer', 'between:1,365', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'contract_type_id' => __('contract type'),
            'owner_user_id' => __('contract owner'),
            'starts_on' => __('start date'),
            'ends_on' => __('end date'),
            'renewal_term_months' => __('renewal term'),
            'notice_period_days' => __('notice period'),
            'currency_code' => __('currency'),
            'value_period' => __('value period'),
            'reminder_days.*' => __('reminder'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->shortenLongLists(['reminder_days' => self::MAX_REMINDERS]);
        $this->keepDatesText('starts_on', 'ends_on');

        $autoRenews = $this->boolean('auto_renews');

        $this->merge([
            'auto_renews' => $autoRenews,
            'renewal_term_months' => $autoRenews ? $this->input('renewal_term_months') : null,
            'notice_period_days' => $autoRenews ? $this->input('notice_period_days') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function contractAttributes(): array
    {
        return $this->safe()->except('reminder_days');
    }

    /**
     * @return list<int>
     */
    public function reminderDays(): array
    {
        return array_values(array_map(intval(...), $this->validated('reminder_days', [])));
    }
}
