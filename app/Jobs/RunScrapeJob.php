<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Services\Scraper\ScrapeImporter;
use App\Services\Scraper\ScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunScrapeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Timeout: 1 hour max per queue job.
     *
     * With batch limiting (default 20 items/run), scheduled jobs complete in minutes.
     * Manual mode uses dispatchSync — this timeout does NOT apply.
     * 1 hour is a generous safety net for edge cases (slow network, many items).
     */
    public int $timeout = 3600;

    /**
     * Retry up to 2 times on failure.
     */
    public int $tries = 2;

    /**
     * @param  bool  $autoImport      Auto-import draft items after scraping (scheduled mode).
     * @param  bool  $isScheduledRun  Whether this is a scheduled (auto) run.
     *                                Affects batch limiting in fetchDetails.
     * @param  bool  $skipTocScrape   Skip Phase 1 (TOC scraping). Used by scheduled
     *                                runs when items already exist and only need
     *                                content fetching.
     */
    public function __construct(
        public ScrapeJob $scrapeJob,
        public bool $autoImport = false,
        public bool $isScheduledRun = false,
        public bool $skipTocScrape = false,
    ) {}

    /**
     * Run scrape job immediately via fire-and-forget HTTP.
     *
     * Sends a fire-and-forget HTTP request to /api/scrape-run/{id}
     * which runs the job DIRECTLY in a separate PHP-FPM process.
     * No Redis queue involved — simpler and more reliable.
     *
     * Use this for manual UI actions. Scheduled runs use dispatch() directly.
     */
    public static function dispatchWithWorker(ScrapeJob $scrapeJob): void
    {
        try {
            $url = url("/api/scrape-run/{$scrapeJob->id}");
            $token = \App\Services\WebCron\WebCronManager::generateToken();

            $http = \Illuminate\Support\Facades\Http::timeout(1)->connectTimeout(1);

            if (app()->environment('local')) {
                $http = $http->withoutVerifying();
            }

            $http->get($url, ['token' => $token]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            // Expected: timeout after 1s while server continues processing
        } catch (\Throwable $e) {
            Log::warning('Scrape run trigger failed', [
                'job_id' => $scrapeJob->id,
                'error'  => $e->getMessage(),
            ]);
        }

        Log::info('Triggered scrape job via HTTP', [
            'job_id' => $scrapeJob->id,
        ]);
    }

    public function handle(ScraperService $service): void
    {
        // Guard: skip if already completed or in-progress (duplicate job in queue).
        // Allow: 'draft' (scheduled dispatch sets this) and 'scraping' (manual dispatch).
        $this->scrapeJob->refresh();
        if (! in_array($this->scrapeJob->status, [
            ScrapeJob::STATUS_DRAFT,
            ScrapeJob::STATUS_SCRAPING,
        ])) {
            Log::info('Skipping duplicate scrape job', [
                'job_id' => $this->scrapeJob->id,
                'status' => $this->scrapeJob->status,
            ]);
            return;
        }

        // Ensure status is 'scraping' (draft → scraping for scheduled runs)
        if ($this->scrapeJob->status === ScrapeJob::STATUS_DRAFT) {
            $this->scrapeJob->markScraping();
        }

        // Phase 1: Scrape TOC (skip if continue mode — items already exist)
        if ($this->skipTocScrape) {
            Log::info('Skipping TOC scrape (continue mode)', [
                'job_id' => $this->scrapeJob->id,
            ]);
            // Transition directly to 'scraped' since TOC items already exist
            $this->scrapeJob->markScraped();
        } elseif ($this->scrapeJob->isChapterDetailType()) {
            // Chapter Detail: crawl through chapters via next link (or single-page mode)
            $service->executeChapterChain($this->scrapeJob, $this->isScheduledRun);
        } else {
            // Standard: scrape TOC (titles + URLs)
            $service->execute($this->scrapeJob);
        }

        // Phase 2: Auto-fetch chapter content (if configured)
        $this->scrapeJob->refresh();
        if ($this->shouldAutoFetchContent()) {
            $this->autoFetchContent($service);
        }

        // Phase 3: Auto-import (for scheduled jobs + chapter_detail with auto_import)
        $this->scrapeJob->refresh();
        if ($this->autoImport || $this->shouldAutoImportChapterDetail()) {
            $this->autoImportItems();
        }

        // Final status reconciliation: ensure correct status after all phases.
        // Single query instead of 2 separate exists() calls.
        $this->scrapeJob->refresh();
        if (in_array($this->scrapeJob->status, [
            ScrapeJob::STATUS_SCRAPED,
            ScrapeJob::STATUS_SCRAPING,
            ScrapeJob::STATUS_DRAFT,
        ])) {
            $counts = $this->scrapeJob->items()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as pending
                ", [ScrapeItem::STATUS_DRAFT, ScrapeItem::STATUS_SELECTED])
                ->first();

            $total = (int) ($counts->total ?? 0);
            $pending = (int) ($counts->pending ?? 0);

            if ($pending === 0 && $total > 0) {
                $this->scrapeJob->markDone();
            } elseif ($this->scrapeJob->status === ScrapeJob::STATUS_SCRAPING) {
                // Don't leave in 'scraping' — transition to 'scraped'
                $this->scrapeJob->markScraped();
            }
        }
    }

    /**
     * Check if this chapter job should auto-fetch content after Phase 1.
     *
     * Only for scheduled runs — manual UI actions scrape TOC only;
     * the user triggers content fetching separately via the Fetch button.
     */
    private function shouldAutoFetchContent(): bool
    {
        if (! $this->isScheduledRun) {
            return false;
        }

        if (! $this->scrapeJob->isChapterType()) {
            return false;
        }

        if (! $this->scrapeJob->hasDetailConfig()) {
            return false;
        }

        // Check if auto_fetch_content is enabled (default: true)
        $config = $this->scrapeJob->detail_config ?? [];

        return ($config['auto_fetch_content'] ?? true) === true;
    }

    /**
     * Phase 2: Auto-fetch chapter content after TOC scraping.
     *
     * Scheduled mode: respects fetch_batch_size (e.g. 20/run)
     * Manual mode: fetches ALL chapters (no limit)
     */
    private function autoFetchContent(ScraperService $service): void
    {
        $config = $this->scrapeJob->detail_config ?? [];

        // Only limit batch for scheduled runs — manual mode fetches all
        $limit = $this->isScheduledRun
            ? max(1, (int) ($config['fetch_batch_size'] ?? 20))
            : null;

        Log::info('Auto-fetching chapter content', [
            'job_id'       => $this->scrapeJob->id,
            'scheduled'    => $this->isScheduledRun,
            'batch_limit'  => $limit,
        ]);

        try {
            $service->fetchDetails($this->scrapeJob, $limit);

            Log::info('Auto-fetch chapter content completed', [
                'job_id'  => $this->scrapeJob->id,
                'fetched' => $this->scrapeJob->detail_fetched,
                'total'   => $this->scrapeJob->detail_total,
            ]);
        } catch (\Throwable $e) {
            // Log but don't rethrow — Phase 1 data is still valid
            Log::error('Auto-fetch chapter content failed', [
                'job_id' => $this->scrapeJob->id,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Phase 3: Auto-import draft items after scraping.
     *
     * Only runs when dispatched with $autoImport=true (scheduled mode).
     *
     * For chapter jobs with auto_fetch_content:
     *   Only imports items that have fetched content successfully.
     *   Items without content stay as draft (for retry on next run).
     *
     * For non-chapter jobs:
     *   Imports all draft items.
     */
    private function autoImportItems(): void
    {
        $job = $this->scrapeJob;

        // Only import if job is in importable state
        if (! in_array($job->status, [ScrapeJob::STATUS_SCRAPED, ScrapeJob::STATUS_DONE])) {
            Log::info('Skipping auto-import — job not in importable state', [
                'job_id' => $job->id,
                'status' => $job->status,
            ]);

            return;
        }

        $query = $job->items()->where('status', ScrapeItem::STATUS_DRAFT);

        // For chapter jobs with auto-fetch: only import items that have content
        if ($this->shouldAutoFetchContent() || $job->isChapterDetailType()) {
            $query->where('has_content', true)
            // TIER 3: Skip items with critical validation issues (empty, encoding)
            // short_content is allowed through — may still be valid small chapters
            ->where(function ($q) {
                $q->whereNull('raw_data->_validation_issues')
                  ->orWhere(function ($q2) {
                      $q2->whereRaw("JSON_CONTAINS(raw_data, '\"empty_content\"', '$._validation_issues') = 0")
                         ->whereRaw("JSON_CONTAINS(raw_data, '\"encoding_error\"', '$._validation_issues') = 0");
                  });
            });
        }

        $selectedCount = $query->update(['status' => ScrapeItem::STATUS_SELECTED]);

        if ($selectedCount === 0) {
            Log::info('No draft items ready to auto-import', ['job_id' => $job->id]);

            return;
        }

        // Log skipped items (drafts without content)
        $skippedCount = $job->items()
            ->where('status', ScrapeItem::STATUS_DRAFT)
            ->count();

        Log::info('Auto-importing items', [
            'job_id'      => $job->id,
            'entity_type' => $job->entity_type,
            'selected'    => $selectedCount,
            'skipped'     => $skippedCount,
        ]);

        try {
            $job->update(['status' => ScrapeJob::STATUS_IMPORTING]);

            $importer = app(ScrapeImporter::class);
            $results = $importer->importSelected($job);

            Log::info('Auto-import completed', [
                'job_id'  => $job->id,
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            Log::error('Auto-import failed', [
                'job_id' => $job->id,
                'error'  => $e->getMessage(),
                'trace'  => mb_substr($e->getTraceAsString(), 0, 500),
            ]);

            // Revert selected items back to draft so user can retry via UI
            $job->items()
                ->where('status', ScrapeItem::STATUS_SELECTED)
                ->update(['status' => ScrapeItem::STATUS_DRAFT]);

            $job->update([
                'status'    => ScrapeJob::STATUS_SCRAPED,
                'error_log' => 'Auto-import thất bại: ' . mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * chapter_detail: auto-import after scraping when configured.
     * Content is already embedded in each page (no Phase 2 needed).
     */
    private function shouldAutoImportChapterDetail(): bool
    {
        $config = $this->scrapeJob->detail_config ?? [];

        return $this->scrapeJob->isChapterDetailType()
            && ($config['auto_import'] ?? true) === true;
    }
}
