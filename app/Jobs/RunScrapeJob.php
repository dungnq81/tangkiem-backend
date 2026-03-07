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
     */
    public function __construct(
        public ScrapeJob $scrapeJob,
        public bool $autoImport = false,
        public bool $isScheduledRun = false,
    ) {}

    public function handle(ScraperService $service): void
    {
        // Phase 1: Scrape
        if ($this->scrapeJob->isChapterDetailType()) {
            // Chapter Detail: single-page direct content extraction
            $service->executeChapterDetail($this->scrapeJob);
        } else {
            // Standard: scrape TOC (titles + URLs)
            $service->execute($this->scrapeJob);
        }

        // Phase 2: Auto-fetch chapter content (if configured)
        $this->scrapeJob->refresh();
        if ($this->shouldAutoFetchContent()) {
            $this->autoFetchContent($service);
        }

        // Phase 3: Auto-import (only for scheduled/queued jobs)
        $this->scrapeJob->refresh();
        if ($this->autoImport) {
            $this->autoImportItems();
        }

        // Final status reconciliation: ensure correct status after all phases.
        // Covers the "idle run" case where all items are already imported
        // and Phases 2+3 had nothing to do, leaving status stuck at 'scraped'.
        $this->scrapeJob->refresh();
        if (in_array($this->scrapeJob->status, [ScrapeJob::STATUS_SCRAPED, ScrapeJob::STATUS_DRAFT])) {
            $hasPending = $this->scrapeJob->items()
                ->whereIn('status', [ScrapeItem::STATUS_DRAFT, ScrapeItem::STATUS_SELECTED])
                ->exists();

            if (! $hasPending && $this->scrapeJob->items()->exists()) {
                $this->scrapeJob->markDone();
            }
        }
    }

    /**
     * Check if this chapter job should auto-fetch content after Phase 1.
     */
    private function shouldAutoFetchContent(): bool
    {
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
        if ($this->shouldAutoFetchContent()) {
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
            'job_id'   => $job->id,
            'selected' => $selectedCount,
            'skipped'  => $skippedCount,
        ]);

        try {
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
            ]);
        }
    }
}
