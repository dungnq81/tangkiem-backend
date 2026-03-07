<?php

declare(strict_types=1);

namespace App\Support\Curator;

use App\Support\MediaHelper;
use Awcodes\Curator\Facades\Curator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Custom file namer for Curator.
 *
 * Sanitizes filenames using MediaHelper (Vietnamese support)
 * and ensures unique filenames.
 */
class SanitizedFileNamer
{
    public static function generate(TemporaryUploadedFile $file): string
    {
        $originalName = $file->getClientOriginalName();
        $extension = Str::lower($file->getClientOriginalExtension());
        $filename = pathinfo($originalName, PATHINFO_FILENAME);

        // Sanitize filename using MediaHelper
        $sanitizedName = MediaHelper::sanitizeFilename($filename);

        // Get path from Curator's path generator
        $pathGenerator = app(Curator::getPathGenerator());
        $directory = $pathGenerator->getPath();
        $disk = Curator::getDiskName();

        // Make unique filename
        $uniqueName = MediaHelper::makeUniqueFilename(
            $sanitizedName,
            $extension,
            $directory,
            $disk
        );

        return "{$uniqueName}.{$extension}";
    }
}
