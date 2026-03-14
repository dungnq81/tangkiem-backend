<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class LogsCleanup extends Command
{
    protected $signature = 'logs:cleanup
                            {--days=90 : Delete activity logs older than N days}
                            {--batch=1000 : Delete in batches of N rows}';

    protected $description = 'Delete old activity logs to prevent unbounded table growth';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $batchSize = (int) $this->option('batch');
        $cutoffDate = now()->subDays($days);

        $this->info("Cleaning activity logs older than {$days} days (before {$cutoffDate->toDateString()})...");

        $totalDeleted = 0;

        do {
            $deleted = ActivityLog::query()
                ->where('created_at', '<', $cutoffDate)
                ->limit($batchSize)
                ->delete();

            $totalDeleted += $deleted;
        } while ($deleted === $batchSize);

        if ($totalDeleted > 0) {
            Log::info('Logs cleanup: deleted old activity logs', [
                'deleted_count' => $totalDeleted,
                'retention_days' => $days,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
            ]);
        }

        $this->info("Done. Deleted {$totalDeleted} old activity log entries.");

        return self::SUCCESS;
    }
}
