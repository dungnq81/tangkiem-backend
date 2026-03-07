<?php

declare(strict_types=1);

namespace App\Support\Curator;

use Awcodes\Curator\Concerns\UrlProvider;
use Awcodes\Curator\Glide\GlideBuilder;
use Illuminate\Support\Facades\Storage;

/**
 * Custom URL provider that skips Glide transformations for SVG files.
 * SVG files cannot be converted to WebP or other raster formats.
 */
class SafeGlideUrlProvider implements UrlProvider
{
    /**
     * Extensions that should NOT be processed by Glide.
     */
    private const SKIP_EXTENSIONS = ['svg', 'gif'];

    /**
     * Get a Glide URL with custom dimensions.
     * Use this when you need a specific aspect ratio different from the presets.
     *
     * Example: SafeGlideUrlProvider::getUrl($path, 200, 300) // 2:3 portrait
     */
    public static function getUrl(
        string $path,
        int $width,
        int $height,
        string $fit = 'crop',
        string $format = 'webp',
    ): string {
        if (self::shouldSkipGlide($path)) {
            return self::getDirectUrl($path);
        }

        return GlideBuilder::make()
            ->width($width)
            ->height($height)
            ->format($format)
            ->fit($fit)
            ->toUrl($path);
    }

    public static function getThumbnailUrl(string $path): string
    {
        if (self::shouldSkipGlide($path)) {
            return self::getDirectUrl($path);
        }

        return GlideBuilder::make()->width(200)->height(200)->format('webp')->fit('crop')->toUrl($path);
    }

    public static function getMediumUrl(string $path): string
    {
        if (self::shouldSkipGlide($path)) {
            return self::getDirectUrl($path);
        }

        return GlideBuilder::make()->width(640)->height(640)->format('webp')->fit('crop')->toUrl($path);
    }

    public static function getLargeUrl(string $path): string
    {
        if (self::shouldSkipGlide($path)) {
            return self::getDirectUrl($path);
        }

        return GlideBuilder::make()->width(1024)->height(1024)->format('webp')->fit('contain')->toUrl($path);
    }

    /**
     * Check if the file extension should skip Glide processing.
     */
    private static function shouldSkipGlide(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::SKIP_EXTENSIONS, true);
    }

    /**
     * Get direct URL to the file without Glide processing.
     */
    private static function getDirectUrl(string $path): string
    {
        $diskName = config('curator.default_disk', 'public');

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        return $disk->url($path);
    }
}
