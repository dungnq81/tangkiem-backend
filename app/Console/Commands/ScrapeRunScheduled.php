<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunScrapeJob;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScrapeRunScheduled extends Command
{
    protected $signature = 'scrape:run-scheduled';

    protected $description = 'Dispatch scheduled scrape jobs that are due to run';

    public function handle(): int
    {
        $dueJobs = ScrapeJob::query()
            ->where('is_scheduled', true)
            ->whereNotIn('status', [ScrapeJob::STATUS_SCRAPING, ScrapeJob::STATUS_IMPORTING])
            ->whereHas('source', fn ($q) => $q->where('is_active', true))
            ->get()
            ->filter(fn (ScrapeJob $job) => $job->isDueForScheduledRun());

        if ($dueJobs->isEmpty()) {
            $this->line('No scheduled jobs are due.');

            return self::SUCCESS;
        }

        foreach ($dueJobs as $job) {
            $hasUnfetchedItems = $this->hasItemsNeedingWork($job);

            if ($hasUnfetchedItems) {
                // CONTINUE mode: items exist but still need content fetch or import.
                // Skip TOC re-scrape — go straight to Phase 2+3.
                // Only update scheduling timestamp, preserve all progress.
                $job->update([
                    'status'            => ScrapeJob::STATUS_DRAFT,
                    'error_log'         => null,
                    'last_scheduled_at' => now(),
                    // Keep: current_page, detail_status, detail_fetched, detail_total
                ]);

                $mode = 'continue';
            } else {
                // FRESH mode: all items are done (or no items yet) → full re-scrape TOC.
                $job->update([
                    'status'            => ScrapeJob::STATUS_DRAFT,
                    'error_log'         => null,
                    'current_page'      => 0,
                    'last_scheduled_at' => now(),
                    'detail_status'     => null,
                    'detail_fetched'    => 0,
                    'detail_total'      => 0,
                ]);

                $mode = 'fresh';
            }

            // skipTocScrape = true in continue mode → RunScrapeJob skips Phase 1
            RunScrapeJob::dispatch(
                $job,
                autoImport: $job->auto_import,
                isScheduledRun: true,
                skipTocScrape: $hasUnfetchedItems,
            );

            $this->info("Dispatched [{$mode}]: {$job->name} (#{$job->id})" . ($job->auto_import ? ' + auto-import' : ''));

            Log::info('Scheduled scrape job dispatched', [
                'job_id'      => $job->id,
                'job_name'    => $job->name,
                'mode'        => $mode,
                'frequency'   => $job->schedule_frequency,
                'auto_import' => $job->auto_import,
            ]);
        }

        $this->info("Done. Dispatched {$dueJobs->count()} job(s).");

        return self::SUCCESS;
    }

    /**
     * Check if the job has items that still need content fetching or import.
     *
     * Returns true if there are draft/selected items without content,
     * indicating we should continue fetching rather than re-scraping TOC.
     */
    private function hasItemsNeedingWork(ScrapeJob $job): bool
    {
        // No items at all → needs fresh scrape
        if (! $job->items()->exists()) {
            return false;
        }

        // Has draft items without content → continue fetching
        $needsContent = $job->items()
            ->where('status', ScrapeItem::STATUS_DRAFT)
            ->where(function ($q) {
                $q->where('has_content', false)
                  ->orWhereNull('has_content');
            })
            ->exists();

        if ($needsContent) {
            return true;
        }

        // Has draft items ready for import → continue importing
        $needsImport = $job->items()
            ->whereIn('status', [ScrapeItem::STATUS_DRAFT, ScrapeItem::STATUS_SELECTED])
            ->exists();

        return $needsImport;
    }
}
