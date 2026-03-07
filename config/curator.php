<?php

declare(strict_types=1);

use App\Support\Curator\WordPressPathGenerator;

return [
    'curation_formats' => Awcodes\Curator\Enums\PreviewableExtensions::toArray(),
    'default_disk' => env('CURATOR_DEFAULT_DISK', 'public'),
    'default_directory' => 'uploads',
    'default_visibility' => 'public',
    'features' => [
        'curations' => true,
        'file_swap' => true,
        'directory_restriction' => false,
        'preserve_file_names' => false,
        'tenancy' => [
            'enabled' => false,
            'relationship_name' => null,
        ],
    ],
    'glide_token' => env('CURATOR_GLIDE_TOKEN'),
    'model' => Awcodes\Curator\Models\Media::class,

    /*
    |--------------------------------------------------------------------------
    | Path Generator
    |--------------------------------------------------------------------------
    | Custom path generator for WordPress-style directory structure.
    | Generates: uploads/YYYY/MM/filename.ext
    */
    'path_generator' => WordPressPathGenerator::class,

    'resource' => [
        'label' => 'Media',
        'plural_label' => 'Media',
        'default_layout' => 'grid',
        'navigation' => [
            'group' => 'Thư viện',
            'icon' => 'heroicon-o-photo',
            'sort' => 99,
            'should_register' => true,
            'should_show_badge' => true,
        ],
        'resource' => \App\Filament\Resources\Media\MediaResource::class,
        'pages' => [
            'create' => Awcodes\Curator\Resources\Media\Pages\CreateMedia::class,
            'edit' => Awcodes\Curator\Resources\Media\Pages\EditMedia::class,
            'index' => \App\Filament\Resources\Media\Pages\ListMedia::class,
        ],
        'schemas' => [
            'form' => Awcodes\Curator\Resources\Media\Schemas\MediaForm::class,
        ],
        'tables' => [
            'table' => \App\Filament\Resources\Media\Tables\MediaTable::class,
        ],
    ],
    'url_provider' => \App\Support\Curator\SafeGlideUrlProvider::class,
];
