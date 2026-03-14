<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Media Image Sizes
    |--------------------------------------------------------------------------
    |
    | Define reusable media sizes for the application.
    | These sizes are used for generating thumbnails and responsive images.
    |
    | Usage: config('media.sizes.thumb')
    |        config('media.sizes.medium')
    |
    | Note: Thumbnails are generated on-demand via Glide (Curator).
    | Physical file pre-generation can be done via MediaObserver for
    | frequently used sizes to improve performance.
    |
    */

    'sizes' => [
        /*
        |----------------------------------------------------------------------
        | THUMBNAIL SIZES (Square, cropped)
        |----------------------------------------------------------------------
        */

        // Icon (Compact: avatars in lists, small previews - 50x50)
        'icon' => [
            'w' => 50,
            'h' => 50,
            'fit' => 'crop',
            'fm' => 'webp',
            'q' => 85,
        ],

        // Thumb (Avatar, Grid preview, Small covers - 150x150)
        'thumb' => [
            'w' => 150,
            'fit' => 'contain',
            'fm' => 'webp',
            'q' => 85,
        ],

        /*
        |----------------------------------------------------------------------
        | RESPONSIVE SIZES (Width only, maintain aspect ratio)
        |----------------------------------------------------------------------
        */

        // Small (Mobile thumbnails, Story card - Width 480px)
        'small' => [
            'w' => 480,
            'fit' => 'contain',
            'fm' => 'webp',
            'q' => 85,
        ],

        // Medium (Tablet, Card large, Story cover - Width 768px)
        'medium' => [
            'w' => 768,
            'fit' => 'contain',
            'fm' => 'webp',
            'q' => 85,
        ],

        // Large (Desktop, Banner small - Width 1024px)
        'large' => [
            'w' => 1024,
            'fit' => 'contain',
            'fm' => 'webp',
            'q' => 85,
        ],

        // XLarge (Banner, Slide - Width 1200px)
        'xlarge' => [
            'w' => 1200,
            'fit' => 'contain',
            'fm' => 'webp',
            'q' => 85,
        ],

        // Widescreen (Full background, Hero banner - Width 1920px)
        'widescreen' => [
            'w' => 1920,
            'fit' => 'contain',
            'fm' => 'webp',
            'q' => 80,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pre-Generation Settings
    |--------------------------------------------------------------------------
    |
    | Configure which sizes should be pre-generated on upload.
    | This improves performance for frequently accessed sizes.
    | Set to empty array to disable pre-generation (use Glide on-demand only).
    |
    */

    'pregenerate' => [
        'thumb',
        'small',
        'medium',
    ],

    /*
    |--------------------------------------------------------------------------
    | Skip Pre-Generation Threshold
    |--------------------------------------------------------------------------
    |
    | If the source image width/height is smaller than the target size,
    | skip generating that size (avoid upscaling).
    |
    */

    'skip_upscale' => true,

    /*
    |--------------------------------------------------------------------------
    | Supported Image Types for Resize
    |--------------------------------------------------------------------------
    */

    'resizable_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ],
];
