<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScrapeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recover scrape jobs stuck in active statuses due to unexpected interruptions.
 *
 * When a queue worker crashes, the server restarts, or the machine shuts down
 * mid-scrape, jobs can be left in 'scraping' or 'importing' status permanently.
 * The scheduled runner skips these jobs (they look "busy"), so they never run again.
 *
 * This command detects jobs stuck for longer than --minutes (default: 30) and
 * resets them to 'failed' with a descriptive error_log, allowing the scheduler
 * to pick them up on the next run or the user to retry manually.
 */
class ScrapeRecoverStale extends Command
{
    protected $signature = 'scrape:recover-stale
        {--minutes=30 : Consider jobs stale after this many minutes}
        {--dry-run : Show what would be recovered without making changes}';

    protected $description = 'Recover scrape jobs stuck in active status (scraping/importing) due to crashes';

    public function handle(): int
    {
        $minutesThreshold = (int) $this->option('minutes');
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($minutesThreshold);

        // Find jobs stuck in active statuses with no update for too long
        $staleJobs = ScrapeJob::query()
            ->where(function ($query) {
                $query->whereIn('status', [
                    ScrapeJob::STATUS_SCRAPING,
                    ScrapeJob::STATUS_IMPORTING,
                ])->orWhere('detail_status', ScrapeJob::DETAIL_STATUS_FETCHING);
            })
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($staleJobs->isEmpty()) {
            $this->line('No stale jobs found.');

            return self::SUCCESS;
        }

        $this->info("Found {$staleJobs->count()} stale job(s) (no activity for {$minutesThreshold}+ minutes):");

        /** @var ScrapeJob $job */
        foreach ($staleJobs as $job) {
            $stuckFor = $job->updated_at->diffForHumans(short: true);
            $stuckIn = $job->status;
            $detailNote = $job->detail_status === ScrapeJob::DETAIL_STATUS_FETCHING
                ? " + detail_status=fetching"
                : '';

            $this->line("  #{$job->id} [{$job->name}] — stuck in '{$stuckIn}'{$detailNote} since {$stuckFor}");

            if ($dryRun) {
                continue;
            }

            $errorMessage = "Auto-recovered: job was stuck in '{$stuckIn}'{$detailNote} "
                . "for {$minutesThreshold}+ minutes (likely server crash or restart). "
                . "Last updated: {$job->updated_at->toDateTimeString()}.";

            $updates = [
                'status' => ScrapeJob::STATUS_FAILED,
                'error_log' => $errorMessage,
            ];

            // Also reset detail_status if it was stuck in fetching
            if ($job->detail_status === ScrapeJob::DETAIL_STATUS_FETCHING) {
                $updates['detail_status'] = ScrapeJob::DETAIL_STATUS_FAILED;
            }

            $job->update($updates);

            Log::warning('Stale scrape job recovered', [
                'job_id'     => $job->id,
                'job_name'   => $job->name,
                'was_status' => $stuckIn,
                'stuck_since' => $job->updated_at->toDateTimeString(),
                'threshold_minutes' => $minutesThreshold,
            ]);
        }

        if ($dryRun) {
            $this->warn('Dry run — no changes made. Remove --dry-run to apply.');
        } else {
            $this->info("Recovered {$staleJobs->count()} job(s). They can now be retried manually or by the scheduler.");
        }

        return self::SUCCESS;
    }
}
