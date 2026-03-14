<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Pages;

use App\Filament\Resources\ScrapeJobs\ScrapeJobResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScrapeJob extends CreateRecord
{
    protected static string $resource = ScrapeJobResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['status'] = 'draft';

        return $data;
    }

    /**
     * After creating, redirect to View page so user can immediately
     * start scraping without navigating manually.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
