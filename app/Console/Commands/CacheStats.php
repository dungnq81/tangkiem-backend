<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cache\CacheService;
use Illuminate\Console\Command;

class CacheStats extends Command
{
    protected $signature = 'cache:stats';

    protected $description = 'Display cache statistics (Redis only)';

    public function handle(CacheService $cacheService): int
    {
        $stats = $cacheService->getStats();

        $this->info('📊 Cache Statistics');
        $this->info('===================');

        if ($stats['driver'] === 'file') {
            $this->warn('Cache driver: file');
            $this->warn('Stats are only available for Redis driver.');
            $this->info('To use Redis, set CACHE_STORE=redis in .env');
            return Command::SUCCESS;
        }

        if (isset($stats['error'])) {
            $this->error("Error: {$stats['error']}");
            return Command::FAILURE;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Driver', $stats['driver']],
                ['Redis Version', $stats['version']],
                ['Uptime (days)', $stats['uptime_days']],
                ['Connected Clients', $stats['connected_clients']],
                ['Used Memory', $stats['used_memory']],
                ['Total Keys', $stats['total_keys']],
                ['Cache Hits', number_format($stats['hits'])],
                ['Cache Misses', number_format($stats['misses'])],
                ['Hit Rate', $stats['hit_rate']],
            ]
        );

        return Command::SUCCESS;
    }
}
