<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use Awcodes\Curator\Resources\Media\Pages\ListMedia as BaseListMedia;

class ListMedia extends BaseListMedia
{
    protected static string $resource = MediaResource::class;
}
