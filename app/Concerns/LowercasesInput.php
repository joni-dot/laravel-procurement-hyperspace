<?php

namespace App\Concerns;

/**
 * Tidies addresses and slugs before validation (trimmed, lower case). Only text is tidied: anything
 * else is left for the "string" rule to refuse, instead of failing here with a server error.
 */
trait LowercasesInput
{
    protected function lowercaseInput(string ...$keys): void
    {
        foreach ($keys as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $this->merge([$key => mb_strtolower(trim($value))]);
            }
        }
    }
}
