<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Pages;

use App\Filament\Resources\ScrapeJobs\Concerns\HasScrapeActions;
use App\Filament\Resources\ScrapeJobs\ScrapeJobResource;
use App\Filament\Resources\ScrapeJobs\Widgets\ChapterDetailPreviewWidget;
use App\Filament\Resources\ScrapeJobs\Widgets\ScrapeJobProgressWidget;
use App\Models\ScrapeJob;
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

    protected function getFooterWidgets(): array
    {
        // Show chapter detail preview widget only for chapter_detail entity type
        if ($this->record->isChapterDetailType()) {
            return [
                ChapterDetailPreviewWidget::class,
            ];
        }

        return [];
    }

    protected function getHeaderActions(): array
    {
        $isChapterDetail = $this->record->isChapterDetailType();

        $actions = [
            $this->startScrapeAction(),
            $this->stopScrapeAction(),
        ];

        // Chapter detail doesn't need fetchDetails or importSelected buttons
        // (import is handled via the preview widget)
        if (! $isChapterDetail) {
            $actions[] = $this->fetchDetailsAction();
            $actions[] = $this->importSelectedAction();
        }

        $actions[] = $this->retryScrapeAction();
        $actions[] = Actions\EditAction::make();

        return $actions;
    }
}
