<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunScrapeJob;
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
            // Reset job for re-scrape
            $job->update([
                'status'            => ScrapeJob::STATUS_DRAFT,
                'error_log'         => null,
                'current_page'      => 0,
                'last_scheduled_at' => now(),
                'detail_status'     => null,
                'detail_fetched'    => 0,
                'detail_total'      => 0,
            ]);

            // Dispatch async — large jobs (8000+ chapters) would block if sync.
            // Web cron endpoint processes these via queue:work --stop-when-empty.
            RunScrapeJob::dispatch($job, autoImport: $job->auto_import, isScheduledRun: true);

            $this->info("Dispatched: {$job->name} (#{$job->id})" . ($job->auto_import ? ' + auto-import' : ''));

            Log::info('Scheduled scrape job dispatched', [
                'job_id'      => $job->id,
                'job_name'    => $job->name,
                'frequency'   => $job->schedule_frequency,
                'auto_import' => $job->auto_import,
            ]);
        }

        $this->info("Done. Dispatched {$dueJobs->count()} job(s).");

        return self::SUCCESS;
    }
}
