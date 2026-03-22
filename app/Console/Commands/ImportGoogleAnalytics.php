<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\GoogleAnalytics\GaClient;
use App\Services\Analytics\GoogleAnalytics\GaImporter;
use Illuminate\Console\Command;

class ImportGoogleAnalytics extends Command
{
    protected $signature = 'ga:import
                            {--date= : Import a specific date (Y-m-d). Defaults to yesterday.}
                            {--from= : Start date for range import (Y-m-d).}
                            {--to= : End date for range import (Y-m-d). Defaults to yesterday.}
                            {--check : Check GA4 configuration without importing.}';

    protected $description = 'Import Google Analytics 4 data into daily_analytics';

    public function handle(GaClient $client, GaImporter $importer): int
    {
        // Check mode
        if ($this->option('check')) {
            return $this->checkConfig($client);
        }

        // Verify GA4 is configured
        if (!$client->isEnabled()) {
            $this->error('❌ GA4 not configured. Check your .env settings:');
            $this->line('   GA_ENABLED=true');
            $this->line('   GA_PROPERTY_ID=<your-property-id>');
            $this->line('   GA_CREDENTIALS_PATH=<path-to-credentials.json>');

            return Command::FAILURE;
        }

        // Determine date range
        $from = $this->option('from');
        $to = $this->option('to');
        $date = $this->option('date');

        if ($from) {
            return $this->importRange($importer, $from, $to);
        }

        return $this->importSingle($importer, $date);
    }

    /**
     * Import a date range.
     */
    private function importRange(GaImporter $importer, string $from, ?string $to): int
    {
        $to = $to ?? now()->subDay()->toDateString();
        $this->info("📊 Importing GA4 data: {$from} → {$to}...");

        $result = $importer->importDateRange($from, $to);

        $this->info("   ✅ Imported {$result['total_imported']} day(s)");

        if (!empty($result['errors'])) {
            $this->warn('   ⚠️ Errors:');
            foreach ($result['errors'] as $error) {
                $this->line("      - {$error}");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Import a single date.
     */
    private function importSingle(GaImporter $importer, ?string $date): int
    {
        $date = $date ?? now()->subDay()->toDateString();
        $this->info("📊 Importing GA4 data for {$date}...");

        $result = $importer->importForDate($date);

        if ($result->imported) {
            $views = number_format($result->metrics['total_views'] ?? 0);
            $visitors = number_format($result->metrics['unique_visitors'] ?? 0);
            $this->info("   ✅ Imported: {$views} views, {$visitors} visitors");

            return Command::SUCCESS;
        }

        $this->error("   ❌ Import failed: {$result->error}");

        return Command::FAILURE;
    }

    /**
     * Check GA4 configuration and display status table.
     */
    private function checkConfig(GaClient $client): int
    {
        $this->info('🔍 Checking GA4 configuration...');

        $config = $client->getConfig();
        $credentialsExist = $config['credentials'] && file_exists($config['credentials']);

        $this->table(
            ['Setting', 'Value', 'Status'],
            [
                [
                    'GA_ENABLED',
                    $config['enabled'] ? 'true' : 'false',
                    $config['enabled'] ? '✅' : '❌',
                ],
                [
                    'GA_PROPERTY_ID',
                    $config['property_id'] ?: '(empty)',
                    $config['property_id'] ? '✅' : '❌',
                ],
                [
                    'GA_CREDENTIALS_PATH',
                    $config['credentials'] ?: '(empty)',
                    $credentialsExist ? '✅' : '❌ File not found',
                ],
            ]
        );

        if ($client->isEnabled()) {
            $this->newLine();
            $this->info('✅ GA4 integration is ready. Run `php artisan ga:import` to import data.');

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->error('❌ GA4 integration is NOT ready. Fix the issues above.');

        return Command::FAILURE;
    }
}
