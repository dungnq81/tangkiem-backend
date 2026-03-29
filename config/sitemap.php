<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | URL Patterns
    |--------------------------------------------------------------------------
    |
    | Define how frontend URLs are structured for each entity type.
    | The base URL is determined dynamically from the requesting ApiDomain.
    |
    | Available placeholders:
    |   - {slug}         : entity slug
    |   - {storySlug}    : parent story slug (for chapters)
    |   - {chapterSlug}  : chapter slug
    |
    */
    'url_patterns' => [
        'stories'    => '/truyen/{slug}',
        'chapters'   => '/truyen/{storySlug}/{chapterSlug}',
        'categories' => '/the-loai/{slug}',
        'authors'    => '/tac-gia/{slug}',
        'tags'       => '/tag/{slug}',
    ],

    /*
    |--------------------------------------------------------------------------
    | URL Priority & Change Frequency
    |--------------------------------------------------------------------------
    |
    | SEO hints for search engines. Priority: 0.0 → 1.0.
    | changefreq: always, hourly, daily, weekly, monthly, yearly, never.
    |
    */
    'priorities' => [
        'pages'      => '1.0',
        'stories'    => '0.8',
        'chapters'   => '0.6',
        'categories' => '0.7',
        'authors'    => '0.5',
        'tags'       => '0.4',
    ],

    'changefreq' => [
        'pages'      => 'daily',
        'stories'    => 'daily',
        'chapters'   => 'weekly',
        'categories' => 'weekly',
        'authors'    => 'monthly',
        'tags'       => 'monthly',
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | max_urls_per_file: Sitemap protocol allows max 50,000 per file.
    |
    */
    'max_urls_per_file' => 45000,

    /*
    |--------------------------------------------------------------------------
    | Static Pages
    |--------------------------------------------------------------------------
    |
    | Additional static pages to include in the sitemap.
    | Each entry: ['path' => '/about', 'priority' => '0.3', 'changefreq' => 'monthly']
    |
    */
    'static_pages' => [
        ['path' => '/', 'priority' => '1.0', 'changefreq' => 'daily'],
    ],
];
