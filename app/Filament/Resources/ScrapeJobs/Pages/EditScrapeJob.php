<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Pages;

use App\Filament\Resources\ScrapeJobs\Concerns\HasScrapeActions;
use App\Filament\Resources\ScrapeJobs\ScrapeJobResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditScrapeJob extends EditRecord
{
    use HasScrapeActions;

    protected static string $resource = ScrapeJobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->startScrapeAction(),
            $this->stopScrapeAction(),
            $this->retryScrapeAction(),
            Actions\ViewAction::make()
                ->label('Xem')
                ->icon(Heroicon::OutlinedEye),
            Actions\DeleteAction::make()
                ->label('Xóa'),
        ];
    }

    /**
     * After saving, redirect to View page for a cleaner flow
     * (View has all actions + relation manager for items).
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
