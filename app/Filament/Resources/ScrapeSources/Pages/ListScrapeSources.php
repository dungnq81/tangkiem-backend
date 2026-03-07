<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeSources\Pages;

use App\Filament\Pages\ListRecords;
use App\Filament\Resources\ScrapeSources\ScrapeSourceResource;
use App\Models\ScrapeSource;
use Filament\Actions\CreateAction;

class ListScrapeSources extends ListRecords
{
    protected static string $resource = ScrapeSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::emptyTrashAction(ScrapeSource::class, 'nguồn thu thập'),
            CreateAction::make(),
        ];
    }
}
