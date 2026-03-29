<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Cache\CacheService;
use App\Services\Cache\RankingService;
use App\Services\Cache\StoryCacheService;
use App\Services\Cache\ViewCountService;
use Illuminate\Support\ServiceProvider;

class CacheServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register CacheService as singleton
        $this->app->singleton(CacheService::class, function () {
            return new CacheService();
        });

        // Register StoryCacheService
        $this->app->singleton(StoryCacheService::class, function ($app) {
            return new StoryCacheService($app->make(CacheService::class));
        });

        // Register ViewCountService
        $this->app->singleton(ViewCountService::class, function ($app) {
            return new ViewCountService($app->make(CacheService::class));
        });

        // Register RankingService
        $this->app->singleton(RankingService::class, function ($app) {
            return new RankingService(
                $app->make(CacheService::class),
                $app->make(StoryCacheService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
