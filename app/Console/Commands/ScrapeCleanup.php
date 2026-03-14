<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScrapeCleanup extends Command
{
    protected $signature = 'scrape:cleanup';

    protected $description = 'Auto-delete old scrape items based on each source\'s cleanup_after_days setting';

    /**
     * Max items to delete per batch to avoid locking the table too long.
     */
    private const BATCH_SIZE = 500;

    public function handle(): int
    {
        $sources = ScrapeSource::query()
            ->where('cleanup_after_days', '>', 0)
            ->get(['id', 'name', 'cleanup_after_days']);

        if ($sources->isEmpty()) {
            $this->line('No sources have cleanup configured.');

            return self::SUCCESS;
        }

        $totalDeleted = 0;

        foreach ($sources as $source) {
            /** @var ScrapeSource $source */
            $cutoffDate = now()->subDays($source->cleanup_after_days);

            // Build the base query: old terminal-status items belonging to this source's jobs
            // Uses subquery instead of pluck() to avoid loading all job IDs into memory
            $query = ScrapeItem::query()
                ->whereIn('job_id', function ($sub) use ($source) {
                    $sub->select('id')
                        ->from((new ScrapeJob)->getTable())
                        ->where('source_id', $source->id);
                })
                ->where('created_at', '<', $cutoffDate)
                ->whereIn('status', [
                    ScrapeItem::STATUS_IMPORTED,
                    ScrapeItem::STATUS_MERGED,
                    ScrapeItem::STATUS_SKIPPED,
                    ScrapeItem::STATUS_ERROR,
                ]);

            // Delete in batches to avoid locking tables for too long
            $deleted = 0;

            do {
                $batch = (clone $query)->limit(self::BATCH_SIZE)->delete();
                $deleted += $batch;
            } while ($batch === self::BATCH_SIZE);

            if ($deleted > 0) {
                $this->info("Source \"{$source->name}\": deleted {$deleted} items older than {$source->cleanup_after_days} days.");

                Log::info('Scrape cleanup: deleted old items', [
                    'source_id' => $source->id,
                    'source_name' => $source->name,
                    'cleanup_after_days' => $source->cleanup_after_days,
                    'deleted_count' => $deleted,
                    'cutoff_date' => $cutoffDate->toDateTimeString(),
                ]);
            }

            $totalDeleted += $deleted;
        }

        $this->info("Done. Total deleted: {$totalDeleted} items.");

        return self::SUCCESS;
    }
}
