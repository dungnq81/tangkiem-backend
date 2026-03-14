<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Widgets;

use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Services\Scraper\ScrapeImporter;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Rich editor widget for chapter_detail entity type.
 *
 * Displays the scraped chapter content in an editable form,
 * allowing the user to review/edit title, chapter number,
 * and content before importing as a Chapter record.
 */
class ChapterDetailPreviewWidget extends Widget
{
    protected string $view = 'filament.resources.scrape-jobs.widgets.chapter-detail-preview-widget';

    public ?Model $record = null;

    // Editable fields
    public string $chapterTitle = '';
    public ?string $chapterNumber = null;
    public string $chapterContent = '';

    protected int|string|array $columnSpan = 'full';

    public function mount(): void
    {
        $this->loadFromItem();
    }

    #[On('scrape-job-status-changed')]
    #[On('scrape-data-updated')]
    public function refreshContent(): void
    {
        $this->loadFromItem();
    }

    /**
     * Load content from the ScrapeItem associated with this job.
     */
    protected function loadFromItem(): void
    {
        if (! $this->record) {
            return;
        }

        $item = $this->record->items()->first();

        if (! $item) {
            $this->chapterTitle = '';
            $this->chapterNumber = null;
            $this->chapterContent = '';

            return;
        }

        $data = $item->raw_data ?? [];
        $this->chapterTitle = $data['title'] ?? '';
        $this->chapterNumber = isset($data['chapter_number']) ? (string) $data['chapter_number'] : null;
        $this->chapterContent = $data['content'] ?? '';
    }

    /**
     * Save the edited content back to the ScrapeItem.
     */
    public function saveContent(): void
    {
        $item = $this->record->items()->first();

        if (! $item) {
            Notification::make()
                ->title('Không có dữ liệu')
                ->body('Chưa thu thập dữ liệu. Hãy bấm "Bắt đầu thu thập" trước.')
                ->danger()
                ->send();

            return;
        }

        $rawData = $item->raw_data ?? [];
        $rawData['title'] = $this->chapterTitle;
        $rawData['chapter_number'] = $this->chapterNumber ? (float) $this->chapterNumber : null;
        $rawData['content'] = $this->chapterContent;

        $item->update(['raw_data' => $rawData]);

        Notification::make()
            ->title('Đã lưu')
            ->body('Nội dung chương đã được cập nhật.')
            ->success()
            ->send();
    }

    /**
     * Import the chapter directly from the widget.
     */
    public function importChapter(): void
    {
        $item = $this->record->items()->first();

        if (! $item) {
            Notification::make()
                ->title('Không có dữ liệu')
                ->body('Chưa thu thập dữ liệu.')
                ->danger()
                ->send();

            return;
        }

        // Save current edits first
        $this->saveContent();

        // Mark as selected for import
        $item->update(['status' => ScrapeItem::STATUS_SELECTED]);

        try {
            $importer = app(ScrapeImporter::class);
            $results = $importer->importSelected($this->record);

            $imported = $results['imported'] ?? 0;
            $merged = $results['merged'] ?? 0;

            Notification::make()
                ->title('Import thành công')
                ->body("Đã import {$imported} chương" . ($merged ? ", merge {$merged}" : '') . '.')
                ->success()
                ->send();

            $this->dispatch('scrape-job-status-changed');
        } catch (\Throwable $e) {
            $item->update(['status' => ScrapeItem::STATUS_DRAFT]);

            Notification::make()
                ->title('Import thất bại')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Whether this widget should be visible.
     */
    public static function canView(): bool
    {
        return true;
    }

    public function getItem(): ?ScrapeItem
    {
        return $this->record?->items()->first();
    }

    public function hasContent(): bool
    {
        return ! empty($this->chapterContent);
    }

    public function isImportable(): bool
    {
        $item = $this->getItem();

        return $item
            && $this->hasContent()
            && in_array($item->status, [ScrapeItem::STATUS_DRAFT, ScrapeItem::STATUS_ERROR]);
    }

    public function isAlreadyImported(): bool
    {
        $item = $this->getItem();

        return $item && in_array($item->status, [
            ScrapeItem::STATUS_IMPORTED,
            ScrapeItem::STATUS_MERGED,
        ]);
    }
}
