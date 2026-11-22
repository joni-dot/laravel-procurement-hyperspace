<?php

namespace App\Modules\Srm\Suppliers\Import;

use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\ValidationRuleParser;

/**
 * Checks the rows of a file with a form's own rules (SupplierFields, ContactFields), for thousands of
 * rows at a time. Laravel's validator spends about a tenth of a millisecond on each field, so it is spared
 * the fields whose answer is already known: an empty optional field passes every rule, and a value that
 * was checked once gets the same answer again, unless one of its rules looks at other fields.
 */
final class FieldChecker
{
    /** String rules that look only at the field's own value. Any other rule makes the field's answers uncached. */
    private const array SELF_CONTAINED_RULES = ['Required', 'Nullable', 'String', 'Max', 'Integer', 'Email', 'Url', 'Regex', 'In', 'Boolean'];

    /** @var array<string, bool> */
    private readonly array $cacheable;

    /** @var array<string, string|null> field and value => its first error, null when it passed */
    private array $answers = [];

    /**
     * @param  array<string, array<mixed>>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly array $attributes = [],
    ) {
        $this->cacheable = array_map($this->isSelfContained(...), $this->rules);
    }

    /**
     * @param  array<string, mixed>  $input  tidied like the form's input
     * @return array<string, string> field => its first error; empty when the row passes
     */
    public function errors(array $input): array
    {
        $errors = [];
        $toCheck = [];

        foreach ($this->rules as $field => $rules) {
            $value = $input[$field] ?? null;

            if ($value === null && in_array('nullable', $rules, true)) {
                continue;
            }

            $key = $this->answerKey($field, $value);

            if (! $this->cacheable[$field] || ! array_key_exists($key, $this->answers)) {
                $toCheck[$field] = $rules;
            } elseif ($this->answers[$key] !== null) {
                $errors[$field] = $this->answers[$key];
            }
        }

        return [...$errors, ...$this->check($input, $toCheck)];
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return array_keys($this->rules);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, array<mixed>>  $rules
     * @return array<string, string>
     */
    private function check(array $input, array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $messages = Validator::make($input, $rules, $this->messages, $this->attributes)->errors()->messages();
        $errors = [];

        foreach (array_keys($rules) as $field) {
            $error = $messages[$field][0] ?? null;

            if ($this->cacheable[$field]) {
                $this->answers[$this->answerKey($field, $input[$field] ?? null)] = $error;
            }

            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        return $errors;
    }

    private function answerKey(string $field, mixed $value): string
    {
        return $field."\0".get_debug_type($value)."\0".(is_scalar($value) ? $value : serialize($value));
    }

    /**
     * @param  array<mixed>  $rules
     */
    private function isSelfContained(array $rules): bool
    {
        foreach ($rules as $rule) {
            $selfContained = match (true) {
                is_string($rule) => in_array(ValidationRuleParser::parse($rule)[0], self::SELF_CONTAINED_RULES, true),
                $rule instanceof In => true,
                $rule instanceof ValidationRule => ! $rule instanceof DataAwareRule,
                default => false,
            };

            if (! $selfContained) {
                return false;
            }
        }

        return true;
    }
}
