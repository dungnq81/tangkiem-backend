<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiDomain;
use App\Services\Sitemap\SitemapManager;
use Illuminate\Console\Command;

class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:warm {--domain= : Warm cache for a specific domain}';

    protected $description = 'Warm sitemap cache for all active API domains (or a specific one)';

    public function handle(): int
    {
        $specificDomain = $this->option('domain');

        if ($specificDomain) {
            $this->warmDomain($specificDomain);

            return self::SUCCESS;
        }

        // Warm cache for all active API domains
        $domains = ApiDomain::active()->pluck('domain');

        if ($domains->isEmpty()) {
            $this->warn('No active API domains found.');

            return self::SUCCESS;
        }

        foreach ($domains as $domain) {
            $this->warmDomain($domain);
        }

        $this->info("✓ Warmed sitemap cache for {$domains->count()} domain(s)");

        return self::SUCCESS;
    }

    protected function warmDomain(string $domain): void
    {
        $this->info("Warming sitemap cache for: {$domain}");

        $manager = SitemapManager::forDomain($domain);
        $manager->clearCache();

        // Warm index
        $manager->index();

        // Warm all registered sub-sitemaps
        foreach ($manager->getRegisteredTypes() as $type) {
            $manager->sub($type);
        }

        $this->info("  ✓ Done");
    }
}
