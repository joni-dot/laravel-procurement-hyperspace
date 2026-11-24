<?php

namespace App\Files;

use Illuminate\Http\UploadedFile;

/**
 * The name an uploaded file is kept, shown and downloaded under.
 *
 * Invisible formatting and control characters are removed, so the name reads as it really is: a
 * right-to-left mark, for example, would show the end of a name backwards. A name too long for the
 * database is shortened before its extension, so the file keeps its type.
 */
final class UploadedFileName
{
    public const int MAX_LENGTH = 255;

    /** Longer endings after the last dot are part of the name, not an extension. */
    private const int MAX_EXTENSION_LENGTH = 10;

    private const string FALLBACK = 'file';

    public static function of(UploadedFile $file): string
    {
        return self::clean($file->getClientOriginalName());
    }

    public static function clean(string $name): string
    {
        $visible = (string) preg_replace('/[\p{Cf}\p{Cc}]+/u', '', mb_scrub($name, 'UTF-8'));
        $visible = $visible === '' ? self::FALLBACK : $visible;

        if (mb_strlen($visible) <= self::MAX_LENGTH) {
            return $visible;
        }

        $extension = self::extension($visible);

        return mb_substr($visible, 0, self::MAX_LENGTH - mb_strlen($extension)).$extension;
    }

    /**
     * The extension with its dot, or nothing.
     */
    private static function extension(string $name): string
    {
        $dot = mb_strrpos($name, '.');

        if ($dot === false || $dot === 0) {
            return '';
        }

        $extension = mb_substr($name, $dot);

        return mb_strlen($extension) <= self::MAX_EXTENSION_LENGTH + 1 ? $extension : '';
    }
}
