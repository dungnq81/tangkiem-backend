<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cache\ViewCountService;
use Illuminate\Console\Command;

class ResetViewCounts extends Command
{
    protected $signature = 'views:reset {period : daily|weekly|monthly}';

    protected $description = 'Reset view counts for the specified period';

    public function handle(ViewCountService $viewService): int
    {
        $period = $this->argument('period');

        $this->info("Resetting {$period} view counts...");

        $count = match ($period) {
            'daily' => $viewService->resetDailyViews(),
            'weekly' => $viewService->resetWeeklyViews(),
            'monthly' => $viewService->resetMonthlyViews(),
            default => 0,
        };

        if ($count >= 0 && in_array($period, ['daily', 'weekly', 'monthly'])) {
            $this->info("✅ Reset {$count} story view counts for {$period}!");
        } else {
            $this->error('Invalid period. Use: daily, weekly, or monthly');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
