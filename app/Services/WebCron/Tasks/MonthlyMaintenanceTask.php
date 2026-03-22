<?php

declare(strict_types=1);

namespace App\Services\WebCron\Tasks;

use App\Models\WebCronLog;
use Illuminate\Support\Facades\Artisan;

class MonthlyMaintenanceTask extends AbstractTask
{
    /** Run at most once per 30 days. */
    protected int $throttleSeconds = 60 * 60 * 24 * 30;

    public function name(): string
    {
        return 'maintenance:monthly';
    }

    public function execute(): ?string
    {
        $results = [];

        // 1. Clean old activity logs
        Artisan::call('logs:cleanup', ['--days' => 90]);
        $output = trim(Artisan::output());
        if ($output) {
            $results[] = "logs: {$output}";
        }

        // 2. Clean old scrape data
        Artisan::call('scrape:cleanup');
        $output = trim(Artisan::output());
        if ($output) {
            $results[] = "scrape: {$output}";
        }

        // 3. Clean old web cron logs (keep latest 500)
        $deleted = WebCronLog::cleanup(500);
        if ($deleted > 0) {
            $results[] = "cron-logs: deleted {$deleted}";
        }

        return ! empty($results) ? implode(' | ', $results) : null;
    }
}
