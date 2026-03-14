<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Widgets;

use App\Filament\Resources\ScrapeJobs\ScrapeJobResource;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

class ScrapeCompletionWidget extends Widget
{
    protected string $view = 'filament.resources.scrape-jobs.widgets.scrape-completion-widget';

    protected int | string | array $columnSpan = 'full';

    /**
     * Track whether confirmation step is active.
     */
    public bool $confirming = false;

    protected function getViewData(): array
    {
        $total = ScrapeJob::count();

        if ($total === 0) {
            return ['allDone' => false, 'total' => 0];
        }

        // For raw SQL (selectRaw): must include DB prefix manually
        $prefix = \Illuminate\Support\Facades\DB::getTablePrefix();
        $j = $prefix . (new ScrapeJob)->getTable();   // e.g. "tk_scrape_jobs"
        $i = $prefix . (new ScrapeItem)->getTable();  // e.g. "tk_scrape_items"

        // For query builder methods (leftJoin ON clause): unprefixed (auto-prefixed)
        $jUnprefixed = (new ScrapeJob)->getTable();
        $iUnprefixed = (new ScrapeItem)->getTable();

        // Single aggregate query: check if ALL jobs have all items imported/merged
        // and no chapter jobs have detail errors or missing content.
        // Replaces N+1 loop that queried items per job.
        $jobStats = ScrapeJob::query()
            ->leftJoin($iUnprefixed, "{$jUnprefixed}.id", '=', "{$iUnprefixed}.job_id")
            ->selectRaw("
                COUNT(DISTINCT {$j}.id) as total_jobs,
                COUNT(DISTINCT CASE WHEN {$i}.id IS NOT NULL
                    AND {$i}.status NOT IN (?, ?)
                    THEN {$j}.id END) as jobs_with_unfinished_items,
                COUNT(DISTINCT CASE
                    WHEN {$j}.entity_type IN (?, ?)
                    AND {$i}.has_error = 1
                    THEN {$j}.id END) as jobs_with_detail_errors,
                COUNT(DISTINCT CASE
                    WHEN {$j}.entity_type IN (?, ?)
                    AND {$i}.has_content = 0
                    AND {$i}.status IN (?, ?)
                    THEN {$j}.id END) as jobs_with_missing_content
            ", [
                ScrapeItem::STATUS_IMPORTED, ScrapeItem::STATUS_MERGED,
                ScrapeJob::ENTITY_CHAPTER, ScrapeJob::ENTITY_CHAPTER_DETAIL,
                ScrapeJob::ENTITY_CHAPTER, ScrapeJob::ENTITY_CHAPTER_DETAIL,
                ScrapeItem::STATUS_IMPORTED, ScrapeItem::STATUS_MERGED,
            ])
            ->first();

        // Check: any job with zero items?
        $hasEmptyJobs = ScrapeJob::query()
            ->whereDoesntHave('items')
            ->exists();

        $allDone = ! $hasEmptyJobs
            && (int) $jobStats->jobs_with_unfinished_items === 0
            && (int) $jobStats->jobs_with_detail_errors === 0
            && (int) $jobStats->jobs_with_missing_content === 0;

        return [
            'allDone' => $allDone,
            'total' => $total,
        ];
    }

    public function confirmDelete(): void
    {
        $this->confirming = true;
    }

    public function cancelDelete(): void
    {
        $this->confirming = false;
    }

    public function deleteAllDone(): void
    {
        $deleted = ScrapeJob::where('status', ScrapeJob::STATUS_DONE)->delete();

        Notification::make()
            ->title("Đã xóa {$deleted} tác vụ hoàn thành")
            ->success()
            ->send();

        $this->redirect(ScrapeJobResource::getUrl('index'));
    }
}
