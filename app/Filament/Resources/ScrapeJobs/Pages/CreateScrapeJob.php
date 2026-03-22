<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Pages;

use App\Filament\Resources\ScrapeJobs\ScrapeJobResource;
use Filament\Resources\Pages\CreateRecord;

class CreateScrapeJob extends CreateRecord
{
    protected static string $resource = ScrapeJobResource::class;

    /**
     * Pre-fill entity_type from query param when creating from filtered list.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $entityType = request()->query('entity_type');
        $defaults = [];

        if ($entityType && array_key_exists($entityType, config('scrape.entity_types', []))) {
            $defaults['entity_type'] = $entityType;
        }

        $this->form->fill($defaults);

        $this->callHook('afterFill');
    }

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

