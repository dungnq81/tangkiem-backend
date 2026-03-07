<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Pages;

use App\Filament\Pages\ListRecords;
use App\Filament\Resources\ScrapeJobs\ScrapeJobResource;
use App\Filament\Resources\ScrapeJobs\Widgets\ScrapeCompletionWidget;
use Filament\Actions\CreateAction;

class ListScrapeJobs extends ListRecords
{
    protected static string $resource = ScrapeJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ScrapeCompletionWidget::class,
        ];
    }
}
