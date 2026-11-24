<?php

namespace App\Concerns;

/**
 * For form requests with list fields. Laravel checks every item of a list even when the list is too
 * long, and a "distinct" item rule compares each item with every other one, so the work grows with the
 * square of the list's length. Lists are limited before their items are checked.
 */
trait ShortensLongLists
{
    /**
     * Call from prepareForValidation(). A list longer than allowed is cut to one item over the limit:
     * its own "max" rule (which the field must also have) still refuses it with the usual message, and
     * the other fields are checked as usual, but the extra items are never looked at.
     *
     * @param  array<string, int>  $maxItems  the most items each list field may have
     */
    protected function shortenLongLists(array $maxItems): void
    {
        foreach ($maxItems as $field => $max) {
            $value = $this->input($field);

            if (is_array($value) && count($value) > $max) {
                $this->merge([$field => array_slice($value, 0, $max + 1, preserve_keys: true)]);
            }
        }
    }
}
