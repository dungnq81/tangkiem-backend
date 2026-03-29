<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Pages;

use App\Filament\Resources\ScrapeJobs\Concerns\HasScrapeActions;
use App\Filament\Resources\ScrapeJobs\ScrapeJobResource;
use App\Filament\Resources\ScrapeJobs\Widgets\ScrapeJobProgressWidget;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewScrapeJob extends ViewRecord
{
    use HasScrapeActions;

    protected static string $resource = ScrapeJobResource::class;

    /**
     * When RelationManager dispatches 'scrape-items-updated',
     * refresh the record so header action labels re-render with correct counts.
     */
    #[On('scrape-items-updated')]
    #[On('scrape-job-status-changed')]
    public function refreshRecord(): void
    {
        $this->record->refresh();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ScrapeJobProgressWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        $isChapterDetail = $this->record->isChapterDetailType();

        $actions = [
            $this->startScrapeAction(),
            $this->resumeChainAction(),
            $this->stopScrapeAction(),
        ];

        // fetchDetails: only for chapter (has Phase 2), not chapter_detail
        if (! $isChapterDetail) {
            $actions[] = $this->fetchDetailsAction();
        }

        // importSelected: for both chapter and chapter_detail
        $actions[] = $this->importSelectedAction();
        $actions[] = $this->retryScrapeAction();
        $actions[] = Actions\EditAction::make();

        return $actions;
    }
}
