<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Widgets;

use App\Models\ScrapeJob;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class ScrapeJobProgressWidget extends BaseWidget
{
    /**
     * Filament auto-injects the record on Edit/View resource pages.
     */
    public ?Model $record = null;

    protected int | string | array $columnSpan = 'full';

    /**
     * Don't lazy-load — show immediately.
     */
    protected static bool $isLazy = false;

    /**
     * Track whether the job was active on last poll cycle.
     * Used to detect active→completed transition and notify the page.
     */
    private bool $wasActive = false;

    /**
     * Dynamic polling: 3s when scraping/importing/fetching details, off otherwise.
     *
     * When status transitions from active → completed, dispatches
     * 'scrape-job-status-changed' so the page re-renders header actions
     * (e.g., Stop button auto-hides, Fetch/Import buttons appear).
     */
    protected function getPollingInterval(): ?string
    {
        if (! $this->record) {
            return null;
        }

        $previousStatus = $this->record->status;
        $previousDetailStatus = $this->record->detail_status;

        // Refresh record from DB to get latest status
        $this->record->refresh();

        $activeStates = [
            ScrapeJob::STATUS_SCRAPING,
            ScrapeJob::STATUS_IMPORTING,
        ];

        $isActive = in_array($this->record->status, $activeStates)
            || $this->record->detail_status === ScrapeJob::DETAIL_STATUS_FETCHING;

        // Detect status transition: was active → now completed
        $wasActive = in_array($previousStatus, $activeStates)
            || $previousDetailStatus === ScrapeJob::DETAIL_STATUS_FETCHING;

        if ($wasActive && ! $isActive) {
            // Job just completed — tell the page to re-render header actions
            $this->dispatch('scrape-job-status-changed');
        }

        return $isActive ? '3s' : null;
    }

    /**
     * Listen for status change and bulk action events to refresh stats.
     *
     * scrape-data-updated: from RelationManager bulk actions
     * scrape-job-status-changed: from header actions (start/stop/retry)
     *   — triggers re-render so polling restarts when job becomes active
     */
    #[On('scrape-data-updated')]
    #[On('scrape-job-status-changed')]
    public function refreshStats(): void
    {
        // Livewire will re-render the component, calling getStats() again
        $this->record?->refresh();
    }

    protected function getStats(): array
    {
        $record = $this->record;
        if (! $record) {
            return [];
        }

        // Single aggregate query instead of N+1 count queries
        // This $itemCounts is passed to all stat methods to avoid re-querying.
        $itemCounts = $record->items()
            ->selectRaw("status, COUNT(*) as total")
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $compact = ['class' => '[&_.fi-wi-stats-overview-stat-value]:text-xl'];

        $stats = [
            $this->statusStat($record, $itemCounts)->extraAttributes($compact),
            $this->progressStat($record)->extraAttributes($compact),
            $this->itemsStat($record, $itemCounts)->extraAttributes($compact),
        ];

        // Show detail stat only for chapter jobs with detail_config
        if ($record->isChapterType() && $record->hasDetailConfig()) {
            $stats[] = $this->detailStat($record, $itemCounts)->extraAttributes($compact);
        }

        $stats[] = $this->timeStat($record)->extraAttributes($compact);

        return $stats;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Individual stats
    // ═══════════════════════════════════════════════════════════════════════

    private function statusStat(ScrapeJob $record, array $itemCounts): Stat
    {
        $scrapingDesc = $record->source?->usesAi()
            ? 'AI đang trích xuất dữ liệu'
            : 'CSS Selector đang trích xuất dữ liệu';

        $statusConfig = match ($record->status) {
            ScrapeJob::STATUS_DRAFT    => ['label' => '📝 Nháp', 'color' => 'gray', 'desc' => $this->getDraftDescription($record, $itemCounts)],
            ScrapeJob::STATUS_SCRAPING => ['label' => '⏳ Đang thu thập...', 'color' => 'warning', 'desc' => $scrapingDesc],
            ScrapeJob::STATUS_SCRAPED  => ['label' => '✅ Đã thu thập', 'color' => 'success', 'desc' => $this->getScrapedDescription($record, $itemCounts)],
            ScrapeJob::STATUS_IMPORTING => ['label' => '📥 Đang import...', 'color' => 'primary', 'desc' => 'Đang ghi vào database'],
            ScrapeJob::STATUS_DONE     => ['label' => '🎉 Hoàn tất', 'color' => 'success', 'desc' => $this->getDoneDescription($itemCounts)],
            ScrapeJob::STATUS_FAILED   => ['label' => '❌ Lỗi', 'color' => 'danger', 'desc' => $record->error_log ? mb_substr($record->error_log, 0, 80) : 'Có lỗi xảy ra'],
            default                    => ['label' => $record->status, 'color' => 'gray', 'desc' => ''],
        };

        return Stat::make('Trạng thái', $statusConfig['label'])
            ->description($statusConfig['desc'])
            ->color($statusConfig['color']);
    }

    private function getDraftDescription(ScrapeJob $record, array $itemCounts): string
    {
        $totalItems = array_sum($itemCounts);

        if ($totalItems === 0) {
            return 'Chưa bắt đầu thu thập';
        }

        $importedCount = ($itemCounts['imported'] ?? 0) + ($itemCounts['merged'] ?? 0);

        if ($importedCount === $totalItems) {
            return $record->is_scheduled
                ? '✅ Tất cả đã import — chờ chương mới'
                : '✅ Tất cả đã import';
        }

        if ($record->is_scheduled) {
            return '⏳ Đang chờ queue xử lý...';
        }

        return "Đã có {$totalItems} items từ lần thu thập trước";
    }

    private function getDoneDescription(array $itemCounts): string
    {
        $imported = $itemCounts['imported'] ?? 0;
        $merged = $itemCounts['merged'] ?? 0;
        $total = $imported + $merged;

        if ($total > 0) {
            $parts = [];
            if ($imported > 0) {
                $parts[] = "{$imported} imported";
            }
            if ($merged > 0) {
                $parts[] = "{$merged} merged";
            }

            return implode(', ', $parts);
        }

        return 'Tất cả items đã xử lý xong';
    }

    private function getScrapedDescription(ScrapeJob $record, array $itemCounts): string
    {
        if (! $record->isChapterType() || ! $record->hasDetailConfig()) {
            $draftCount = $itemCounts['draft'] ?? 0;

            return $draftCount > 0 ? 'Sẵn sàng import' : 'Tất cả items đã import — chờ items mới';
        }

        // For chapter jobs: check actual item states, not just detail_status
        $totalItems = array_sum($itemCounts);
        $importedCount = ($itemCounts['imported'] ?? 0) + ($itemCounts['merged'] ?? 0);

        if ($totalItems > 0 && $importedCount === $totalItems) {
            return '✅ Tất cả chương đã import — chờ chương mới';
        }

        return match ($record->detail_status) {
            ScrapeJob::DETAIL_STATUS_FETCHING => '⏳ Đang fetch nội dung chương...',
            ScrapeJob::DETAIL_STATUS_FETCHED  => '✅ Đã fetch nội dung — sẵn sàng import',
            ScrapeJob::DETAIL_STATUS_FAILED   => '❌ Fetch nội dung thất bại',
            default                           => $importedCount > 0
                ? "📋 Đã import {$importedCount}/{$totalItems} — đang fetch tiếp"
                : '📋 Đã có mục lục — cần fetch nội dung',
        };
    }

    private function progressStat(ScrapeJob $record): Stat
    {
        $current = $record->current_page ?? 0;
        $total = $record->total_pages ?? 0;

        if ($record->status === ScrapeJob::STATUS_SCRAPING) {
            $value = $total > 0 ? "Trang {$current}/{$total}" : "Trang {$current}";
            $desc = 'Đang xử lý...';
        } elseif ($total > 0) {
            $value = "{$total} trang";
            $desc = 'Đã hoàn thành';
        } else {
            $value = '—';
            $desc = 'Chưa bắt đầu';
        }

        return Stat::make('Tiến trình', $value)
            ->description($desc)
            ->color($record->status === ScrapeJob::STATUS_SCRAPING ? 'warning' : 'gray');
    }

    private function itemsStat(ScrapeJob $record, array $counts): Stat
    {
        $totalItems = array_sum($counts);
        $imported = $counts['imported'] ?? 0;
        $merged = $counts['merged'] ?? 0;
        $selected = $counts['selected'] ?? 0;

        if ($totalItems === 0) {
            $value = '0';
            $desc = 'Chưa có items';
        } elseif (($imported + $merged) > 0) {
            $value = (string) $totalItems;
            $desc = "Imported: {$imported}" . ($merged > 0 ? " | Merged: {$merged}" : '') . " | Đã chọn: {$selected}";
        } elseif ($selected > 0) {
            $value = (string) $totalItems;
            $desc = "Đã chọn: {$selected} — sẵn sàng import";
        } else {
            $value = (string) $totalItems;
            $desc = 'Tìm thấy — cần chọn items để import';
        }

        return Stat::make('Items thu thập', $value)
            ->description($desc)
            ->color($totalItems > 0 ? 'info' : 'gray');
    }

    private function detailStat(ScrapeJob $record, array $itemCounts): Stat
    {
        // When actively fetching via header button, show progress from job-level tracking
        if ($record->detail_status === ScrapeJob::DETAIL_STATUS_FETCHING) {
            $fetched = $record->detail_fetched ?? 0;
            $total = $record->detail_total ?? 0;

            return Stat::make('📖 Nội dung chương', "Đang fetch {$fetched}/{$total}")
                ->description('Đang truy cập từng URL chương...')
                ->color('warning');
        }

        // Count actual content status using generated columns (indexed, no JSON scan)
        $chapterItems = $record->items()
            ->whereIn('status', ['draft', 'selected', 'imported', 'merged'])
            ->selectRaw("
                COUNT(*) as total,
                SUM(has_content) as fetched,
                SUM(has_error) as errors
            ")
            ->first();

        $total = (int) ($chapterItems->total ?? 0);
        $fetched = (int) ($chapterItems->fetched ?? 0);
        $errors = (int) ($chapterItems->errors ?? 0);
        $unfetched = max(0, $total - $fetched - $errors);

        if ($total === 0) {
            return Stat::make('📖 Nội dung chương', '—')
                ->description('Chưa có chương nào')
                ->color('gray');
        }

        if ($fetched === 0 && $errors === 0) {
            return Stat::make('📖 Nội dung chương', "0/{$total}")
                ->description('Chưa fetch — chọn chương → bulk action "📖 Fetch nội dung"')
                ->color('gray');
        }

        // Use pre-computed counts instead of re-querying
        $importedCount = ($itemCounts['imported'] ?? 0) + ($itemCounts['merged'] ?? 0);

        $value = "{$fetched}/{$total}";
        $parts = [];

        if ($importedCount > 0 && $importedCount === $total) {
            // All items imported — show clean success message
            $parts[] = "🎉 Tất cả {$importedCount} chương đã import";
        } else {
            if ($fetched > 0) {
                $fetchLabel = $importedCount > 0
                    ? "✅ {$fetched} đã fetch ({$importedCount} imported)"
                    : "✅ {$fetched} đã fetch";
                $parts[] = $fetchLabel;
            }
            if ($errors > 0) {
                $parts[] = "❌ {$errors} lỗi";
            }
            if ($unfetched > 0) {
                $parts[] = "⏳ {$unfetched} chưa fetch";
            }
        }

        $color = match (true) {
            $importedCount === $total && $total > 0 => 'success',
            $fetched === $total => 'success',
            $errors > 0 => 'warning',
            $fetched > 0 => 'info',
            default => 'gray',
        };

        return Stat::make('📖 Nội dung chương', $value)
            ->description(implode(' | ', $parts))
            ->color($color);
    }

    private function timeStat(ScrapeJob $record): Stat
    {
        if ($record->status === ScrapeJob::STATUS_SCRAPING) {
            $elapsed = $record->updated_at->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE);
            $value = $elapsed;
            $desc = 'Bắt đầu từ ' . $record->updated_at->format('H:i:s');
        } elseif ($record->status === ScrapeJob::STATUS_DRAFT) {
            $value = '—';
            $desc = 'Chưa chạy';
        } else {
            $value = $record->updated_at->format('H:i d/m');
            $desc = 'Cập nhật lần cuối';
        }

        return Stat::make('Thời gian', $value)
            ->description($desc)
            ->color('gray');
    }
}
