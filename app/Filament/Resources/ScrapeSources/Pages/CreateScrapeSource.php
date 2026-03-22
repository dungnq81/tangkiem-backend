<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeSources\Pages;

use App\Filament\Resources\ScrapeSources\ScrapeSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScrapeSource extends CreateRecord
{
    protected static string $resource = ScrapeSourceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['render_type'] = ! empty($data['is_spa']) ? 'spa' : 'ssr';
        unset($data['is_spa']);

        return $data;
    }
}
