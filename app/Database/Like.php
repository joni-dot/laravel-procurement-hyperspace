<?php

namespace App\Database;

/**
 * Patterns for SQL LIKE and ILIKE. In a pattern % and _ match any text, so the ones someone types are
 * escaped to match only themselves.
 */
final class Like
{
    public static function containing(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
