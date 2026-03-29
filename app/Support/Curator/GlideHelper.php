<?php

declare(strict_types=1);

namespace App\Support\Curator;

use Awcodes\Curator\Models\Media;

/**
 * Helper class for generating Glide thumbnail URLs.
 *
 * Uses sizes defined in config/media.php
 */
class GlideHelper
{
    /**
     * Get Glide URL for a media item with a predefined size.
     *
     * @param Media|int|null $media Media model or ID
     * @param string $size Size key from config/media.php (icon, thumb, small, medium, large, xlarge, widescreen)
     * @return string|null
     */
    public static function url(Media|int|null $media, string $size = 'thumb'): ?string
    {
        if (is_null($media)) {
            return null;
        }

        if (is_int($media)) {
            $media = Media::find($media);
        }

        if (!$media) {
            return null;
        }

        $sizeConfig = config("media.sizes.{$size}");

        if (!$sizeConfig) {
            // Fallback to original if size not found
            return $media->url;
        }

        return $media->getSignedUrl($sizeConfig);
    }

    /**
     * Get all available size URLs for a media item.
     *
     * @param Media|int|null $media
     * @return array<string, string|null>
     */
    public static function allUrls(Media|int|null $media): array
    {
        if (is_null($media)) {
            return [];
        }

        if (is_int($media)) {
            $media = Media::find($media);
        }

        if (!$media) {
            return [];
        }

        $sizes = config('media.sizes', []);
        $urls = ['original' => $media->url];

        foreach ($sizes as $key => $config) {
            $urls[$key] = $media->getSignedUrl($config);
        }

        return $urls;
    }

    /**
     * Get srcset string for responsive images.
     *
     * @param Media|int|null $media
     * @param array $sizes Array of size keys to include (default: small, medium, large)
     * @return string|null
     */
    public static function srcset(Media|int|null $media, array $sizes = ['small', 'medium', 'large']): ?string
    {
        if (is_null($media)) {
            return null;
        }

        if (is_int($media)) {
            $media = Media::find($media);
        }

        if (!$media) {
            return null;
        }

        $srcsetParts = [];

        foreach ($sizes as $sizeKey) {
            $sizeConfig = config("media.sizes.{$sizeKey}");
            if ($sizeConfig && isset($sizeConfig['w'])) {
                $url = $media->getSignedUrl($sizeConfig);
                $srcsetParts[] = "{$url} {$sizeConfig['w']}w";
            }
        }

        return implode(', ', $srcsetParts);
    }

    /**
     * Check if media is a resizable image.
     *
     * @param Media|null $media
     * @return bool
     */
    public static function isResizable(?Media $media): bool
    {
        if (!$media) {
            return false;
        }

        $resizableTypes = config('media.resizable_types', [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ]);

        return in_array($media->type, $resizableTypes, true);
    }
}
