<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeSources\Pages;

use App\Filament\Resources\ScrapeSources\ScrapeSourceResource;
use Filament\Resources\Pages\EditRecord;

class EditScrapeSource extends EditRecord
{
    protected static string $resource = ScrapeSourceResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['is_spa'] = ($data['render_type'] ?? 'ssr') === 'spa';

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['render_type'] = ! empty($data['is_spa']) ? 'spa' : 'ssr';
        unset($data['is_spa']);

        return $data;
    }
}
