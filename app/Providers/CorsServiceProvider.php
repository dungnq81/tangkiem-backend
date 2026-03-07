<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class CorsServiceProvider extends ServiceProvider
{
    /**
     * Cache TTL for CORS origins (10 minutes).
     */
    private const CACHE_TTL = 600;

    public function boot(): void
    {
        // Skip during migrations, artisan commands that don't need CORS
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return;
        }

        $origins = $this->getActiveOrigins();

        if (! empty($origins)) {
            Config::set('cors.allowed_origins', $origins);
        }
        // If empty, keeps default ['*'] from config
    }

    /**
     * Get active domains from tk_api_domains, cached.
     *
     * @return string[]
     */
    private function getActiveOrigins(): array
    {
        try {
            if (! Schema::hasTable('api_domains')) {
                return [];
            }

            return Cache::remember('cors:allowed_origins', self::CACHE_TTL, function () {
                $domains = DB::table('api_domains')
                    ->where('is_active', true)
                    ->whereNotNull('domain')
                    ->where('domain', '!=', '')
                    ->pluck('domain')
                    ->unique()
                    ->values()
                    ->all();

                // Convert domain names to full origins (https://)
                $origins = [];
                foreach ($domains as $domain) {
                    $origins[] = "https://{$domain}";
                    $origins[] = "http://{$domain}"; // Allow HTTP for dev
                }

                return $origins;
            });
        } catch (\Throwable) {
            // DB not ready (migration, fresh install) — fallback to allow all
            return [];
        }
    }
}
