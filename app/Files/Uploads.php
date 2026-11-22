<?php

namespace App\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where uploaded files (certificates, documents) are kept: the server's private disk by default,
 * or S3-compatible storage (UPLOADS_DISK=s3) so files survive the server and are backed up apart
 * from it. Either way the files are private and only reach people through the app's own
 * download routes, which check permissions first.
 */
final class Uploads
{
    public static function diskName(): string
    {
        return (string) config('filesystems.uploads');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }
}
