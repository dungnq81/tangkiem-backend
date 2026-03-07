<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Concerns;

use App\Jobs\RunScrapeJob;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Services\Scraper\ScrapeImporter;
use App\Services\Scraper\ScraperService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Shared scrape action buttons for Edit & View pages.
 *
 * Provides: startScrapeAction, importSelectedAction, retryScrapeAction.
 * Each checks the current record status to show/hide itself.
 */
trait HasScrapeActions
{
    protected function startScrapeAction(): Actions\Action
    {
        return Actions\Action::make('startScrape')
            ->label('Bắt đầu thu thập')
            ->color('success')
            ->icon(Heroicon::OutlinedPlay)
            ->requiresConfirmation()
            ->modalHeading('Bắt đầu thu thập dữ liệu?')
            ->modalDescription(function () {
                if ($this->record->isChapterType()
                    && $this->record->hasDetailConfig()
                    && ($this->record->detail_config['auto_fetch_content'] ?? true)) {
                    return 'Hệ thống sẽ thu thập mục lục → tự động lấy nội dung từng chương. Quá trình có thể mất vài phút.';
                }

                return 'Hệ thống sẽ thu thập dữ liệu ngay lập tức. Vui lòng chờ trong giây lát.';
            })
            ->visible(fn () => in_array($this->record->status, [
                ScrapeJob::STATUS_DRAFT,
                ScrapeJob::STATUS_FAILED,
            ]))
            ->action(function () {
                try {
                    $this->record->markScraping();
                    RunScrapeJob::dispatch($this->record);

                    Notification::make()
                        ->title('Đã bắt đầu thu thập')
                        ->body('Job đang chạy nền. Tiến trình sẽ tự cập nhật trên trang này.')
                        ->success()
                        ->send();

                    $this->dispatch('scrape-job-status-changed');
                } catch (\Throwable $e) {
                    $this->record->markFailed($e->getMessage());

                    Notification::make()
                        ->title('Thu thập thất bại')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function stopScrapeAction(): Actions\Action
    {
        return Actions\Action::make('stopScrape')
            ->label('Dừng thu thập')
            ->color('danger')
            ->icon(Heroicon::OutlinedStopCircle)
            ->requiresConfirmation()
            ->modalHeading('Dừng thu thập?')
            ->modalDescription('Job sẽ dừng sau khi xử lý xong trang/chương hiện tại. Bạn có thể sửa cấu hình rồi chạy lại.')
            ->visible(fn () => in_array($this->record->status, [
                ScrapeJob::STATUS_SCRAPING,
                ScrapeJob::STATUS_IMPORTING,
            ]) || $this->record->detail_status === ScrapeJob::DETAIL_STATUS_FETCHING)
            ->action(function () {
                // Stop Phase 1 (scraping/importing)
                if (in_array($this->record->status, [ScrapeJob::STATUS_SCRAPING, ScrapeJob::STATUS_IMPORTING])) {
                    $this->record->update(['status' => ScrapeJob::STATUS_DRAFT]);
                }

                // Stop Phase 2 (detail fetching)
                if ($this->record->detail_status === ScrapeJob::DETAIL_STATUS_FETCHING) {
                    $this->record->update(['detail_status' => null]);
                }

                Notification::make()
                    ->title('Đã yêu cầu dừng')
                    ->body('Job sẽ dừng sau khi xử lý xong mục hiện tại. Bạn có thể sửa cấu hình và chạy lại.')
                    ->warning()
                    ->send();

                $this->dispatch('scrape-job-status-changed');
            });
    }

    protected function retryScrapeAction(): Actions\Action
    {
        return Actions\Action::make('retryScrape')
            ->label('Thu thập lại')
            ->color('warning')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalHeading('Thu thập lại từ đầu?')
            ->modalDescription('Kết quả cũ sẽ bị xóa và thu thập lại hoàn toàn. Các items đã import trước đó sẽ tự động được nhận diện khi import lại.')
            ->visible(fn () => in_array($this->record->status, [
                ScrapeJob::STATUS_SCRAPED,
                ScrapeJob::STATUS_DONE,
                ScrapeJob::STATUS_FAILED,
            ]))
            ->action(function () {
                try {
                    // Reset: delete all old items & reset stats
                    $this->record->items()->delete();
                    $this->record->update([
                        'current_page'   => 0,
                        'total_pages'    => 0,
                        'error_log'      => null,
                        'detail_status'  => null,
                        'detail_fetched' => 0,
                        'detail_total'   => 0,
                    ]);

                    $this->record->markScraping();
                    RunScrapeJob::dispatch($this->record);

                    Notification::make()
                        ->title('Đang thu thập lại')
                        ->body('Kết quả cũ đã xóa. Job đang chạy nền — tiến trình sẽ tự cập nhật.')
                        ->success()
                        ->send();

                    $this->dispatch('scrape-job-status-changed');
                } catch (\Throwable $e) {
                    $this->record->markFailed($e->getMessage());

                    Notification::make()
                        ->title('Thu thập lại thất bại')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function importSelectedAction(): Actions\Action
    {
        return Actions\Action::make('importSelected')
            ->label(function () {
                $count = $this->record->items()
                    ->where('status', 'selected')
                    ->count();

                return "Import đã chọn ({$count})";
            })
            ->color('primary')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->requiresConfirmation()
            ->modalHeading('Import items đã chọn?')
            ->modalDescription(function () {
                $count = $this->record->items()
                    ->where('status', 'selected')
                    ->count();

                return "Sẽ import {$count} items đã chọn vào database.";
            })
            ->visible(fn () => in_array($this->record->status, [
                ScrapeJob::STATUS_SCRAPED,
                ScrapeJob::STATUS_DONE,
            ]))
            ->action(function () {
                try {
                    $importer = app(ScrapeImporter::class);
                    $results = $importer->importSelected($this->record);

                    Notification::make()
                        ->title('Import hoàn tất!')
                        ->body("Imported: {$results['imported']} | Merged: {$results['merged']} | Skipped: {$results['skipped']} | Errors: {$results['errors']}")
                        ->success()
                        ->send();

                    // Refresh the relation manager table without full page reload
                    $this->dispatch('scrape-items-updated');
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Lỗi khi import')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Fetch nội dung chương — smart button handles both first-time and retry.
     * Visible when there are items without content or with errors.
     */
    protected function fetchDetailsAction(): Actions\Action
    {
        return Actions\Action::make('fetchDetails')
            ->label(function () {
                $needsFetch = $this->countItemsNeedingContent();

                return "Fetch nội dung ({$needsFetch})";
            })
            ->color('info')
            ->icon(Heroicon::OutlinedDocumentText)
            ->requiresConfirmation()
            ->modalHeading('Fetch nội dung chương?')
            ->modalDescription(function () {
                $needsFetch = $this->countItemsNeedingContent();
                $hasErrors = $this->record->items()
                    ->whereIn('status', [ScrapeItem::STATUS_DRAFT, ScrapeItem::STATUS_SELECTED])
                    ->whereNotNull('raw_data->_detail_error')
                    ->exists();

                $desc = "Sẽ truy cập từng URL chương để lấy nội dung. {$needsFetch} chương cần fetch.";
                if ($hasErrors) {
                    $desc .= ' Các chương bị lỗi trước đó sẽ được thử lại.';
                }

                return $desc;
            })
            ->visible(fn () => $this->record->isChapterType()
                && $this->record->hasDetailConfig()
                && in_array($this->record->status, [
                    ScrapeJob::STATUS_SCRAPED,
                    ScrapeJob::STATUS_DONE,
                ])
                && $this->record->detail_status !== ScrapeJob::DETAIL_STATUS_FETCHING
                && $this->countItemsNeedingContent() > 0)
            ->action(function () {
                // Clear errors on failed items so they get re-fetched
                $errorItems = $this->record->items()
                    ->whereIn('status', [ScrapeItem::STATUS_DRAFT, ScrapeItem::STATUS_SELECTED])
                    ->whereNotNull('raw_data->_detail_error')
                    ->get();

                foreach ($errorItems as $item) {
                    $rawData = $item->raw_data ?? [];
                    unset($rawData['content'], $rawData['_detail_error']);
                    $item->update(['raw_data' => $rawData]);
                }

                try {
                    $scraper = app(ScraperService::class);
                    $scraper->fetchDetails($this->record);

                    $this->record->refresh();
                    $fetched = $this->record->detail_fetched;
                    $total = $this->record->detail_total;

                    Notification::make()
                        ->title('Fetch nội dung hoàn tất!')
                        ->body("Đã fetch {$fetched}/{$total} chương thành công.")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Fetch nội dung thất bại')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }

                $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
            });
    }

    /**
     * Count items that need content fetching (no content or has error).
     */
    private function countItemsNeedingContent(): int
    {
        return $this->record->items()
            ->whereIn('status', [ScrapeItem::STATUS_DRAFT, ScrapeItem::STATUS_SELECTED])
            ->where(function ($q) {
                $q->where('has_content', false)
                  ->orWhere('has_error', true);
            })
            ->count();
    }
}
