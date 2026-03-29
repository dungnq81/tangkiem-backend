<?php

declare(strict_types=1);

namespace App\Filament\Resources\Authors\Pages;

use App\Filament\Pages\ListRecords;
use App\Filament\Resources\Authors\AuthorResource;
use App\Models\Author;
use Filament\Actions\CreateAction;

class ListAuthors extends ListRecords
{
    protected static string $resource = AuthorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::emptyTrashAction(Author::class, 'tác giả'),
            CreateAction::make(),
        ];
    }
}
