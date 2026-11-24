<?php

namespace App\Concerns;

trait DateValidationRules
{
    /**
     * A day typed as YYYY-MM-DD. Days before 1900 are refused: none is real here, and the database has
     * no year 0, which the format alone would allow.
     *
     * @return list<string>
     */
    protected function dateRules(): array
    {
        return ['date_format:Y-m-d', 'after_or_equal:1900-01-01'];
    }

    /**
     * Dates arrive as text. A number or a list would reach the date checks (and the comparisons with
     * other dates) as something they can't read and fail with a server error, so it becomes text no
     * date matches, and the usual "not a valid date" message follows.
     */
    protected function keepDatesText(string ...$keys): void
    {
        foreach ($keys as $key) {
            $value = $this->input($key);

            if ($value !== null && ! is_string($value)) {
                $this->merge([$key => 'not a date']);
            }
        }
    }
}
