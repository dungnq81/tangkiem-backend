<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use App\Services\Scraper\Data\ScrapeMetrics;
use App\Services\Scraper\Drivers\CloudflareDetectedException;
use App\Services\Scraper\Drivers\DriverInterface;
use App\Services\Scraper\Drivers\HttpDriver;
use App\Services\Scraper\Drivers\PlaywrightDriver;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2: Chapter detail page fetching engine.
 *
 * Handles concurrent batch fetching of chapter content pages
 * with retry, rate limiting, memory safeguards, and CF fallback.
 *
 * Depends on ScraperService for shared utilities (fetchWithCfFallback,
 * trackDetailError, adaptDelay, configurePoolRequest).
 */
class DetailFetcher
{
    public function __construct(
        protected ScraperService $scraper,
        protected ContentExtractor $extractor,
        protected ContentPipeline $pipeline,
    ) {}

    /**
     * Fetch detail pages for chapter items.
     *
     * @param  int|null  $limit  Max items to fetch. null=ALL, int=batch.
     */
    public function fetchDetails(ScrapeJob $job, ?int $limit = null): void
    {
        if (! $job->isChapterType() || ! $job->hasDetailConfig()) {
            throw new \RuntimeException('Detail fetch requires chapter type with detail_config');
        }

        $source = $job->source;
        $driver = $this->scraper->resolveDriver($source);
        $config = $job->detail_config;
        $maxRetries = max(1, $source->max_retries ?? 3);
        $concurrency = max(1, $source->max_concurrency ?? 3);

        // Initialize rate limiter
        $this->scraper->initRateLimiter($source->delay_ms ?? 2000);

        // Build base query using generated columns (indexed, no JSON scan)
        $baseQuery = fn () => $job->items()
            ->whereIn('status', [ScrapeItem::STATUS_DRAFT, ScrapeItem::STATUS_SELECTED])
            ->where(function ($q) use ($maxRetries) {
                $q->where('has_content', false)
                    ->orWhere(function ($q2) use ($maxRetries) {
                        $q2->where('has_error', true)
                           ->where('error_type', 'transient')
                           ->where('retry_count', '<', $maxRetries);
                    });
            });

        $totalUnfetched = $baseQuery()->count();

        if ($totalUnfetched === 0) {
            Log::info('No items need detail fetching', ['job_id' => $job->id]);
            $job->markDetailFetched();

            return;
        }

        $fetchCount = $limit ? min($limit, $totalUnfetched) : $totalUnfetched;
        $job->markDetailFetching($fetchCount);

        Log::info('Detail fetch starting', [
            'job_id'          => $job->id,
            'fetch_count'     => $fetchCount,
            'total_unfetched' => $totalUnfetched,
            'concurrency'     => $concurrency,
            'limited'         => $limit !== null,
        ]);

        $fetched = 0;
        $errors = 0;

        try {
            $items = $limit
                ? $baseQuery()->orderBy('sort_order')->take($limit)->get()
                : $baseQuery()->orderBy('sort_order')->cursor();

            $itemBuffer = collect();

            foreach ($items as $item) {
                if (! $item instanceof ScrapeItem) {
                    $item = ScrapeItem::find($item->id ?? null);
                    if (! $item) {
                        $errors++;

                        continue;
                    }
                }

                $itemBuffer->push($item);

                if ($itemBuffer->count() >= $concurrency) {
                    $results = $this->fetchDetailBatch($job, $itemBuffer, $driver, $source, $config);
                    $fetched += $results['fetched'];
                    $errors += $results['errors'];
                    $itemBuffer = collect();

                    gc_collect_cycles();

                    // Check cancellation between batches
                    $job->refresh();
                    if ($job->detail_status !== ScrapeJob::DETAIL_STATUS_FETCHING) {
                        Log::info('Detail fetch cancelled', [
                            'job_id' => $job->id, 'fetched' => $fetched, 'errors' => $errors,
                        ]);

                        return;
                    }

                    // Memory safeguard
                    $memoryUsage = memory_get_usage(true);
                    $memoryLimit = $this->scraper->getMemoryLimitBytes();
                    if ($memoryLimit > 0 && $memoryUsage > $memoryLimit * 0.80) {
                        Log::warning('Detail fetch stopped: memory limit approaching', [
                            'job_id'       => $job->id,
                            'fetched'      => $fetched,
                            'memory_usage' => round($memoryUsage / 1048576, 1) . 'MB',
                            'memory_limit' => round($memoryLimit / 1048576, 1) . 'MB',
                        ]);

                        break;
                    }

                    // Adaptive delay between batches
                    $currentDelay = $this->scraper->getCurrentDelayMs();
                    if ($currentDelay > 0) {
                        usleep($currentDelay * 1000);
                    }
                }
            }

            // Process remaining items
            if ($itemBuffer->isNotEmpty()) {
                $results = $this->fetchDetailBatch($job, $itemBuffer, $driver, $source, $config);
                $fetched += $results['fetched'];
                $errors += $results['errors'];
            }

            // Determine final status
            $remaining = $baseQuery()->count();

            if ($fetched === 0 && $errors > 0) {
                $job->markDetailFailed(
                    "Fetch hoàn tất: 0 thành công, {$errors} lỗi, {$remaining} chưa fetch"
                );
            } else {
                $job->markDetailFetched();
            }

            Log::info('Detail fetch completed', [
                'job_id'          => $job->id,
                'fetched'         => $fetched,
                'errors'          => $errors,
                'total_requested' => $fetchCount,
                'remaining'       => $remaining,
            ]);
        } catch (\Throwable $e) {
            Log::error('Detail fetch failed globally', [
                'job_id' => $job->id,
                'error'  => $e->getMessage(),
            ]);
            $job->markDetailFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * Fetch and process a batch of items concurrently.
     *
     * @return array{fetched: int, errors: int}
     */
    protected function fetchDetailBatch(
        ScrapeJob $job,
        Collection $items,
        DriverInterface &$driver,
        ScrapeSource $source,
        array $config,
    ): array {
        $fetched = 0;
        $errors = 0;

        // Step 1: Concurrent fetch HTML
        $htmlResults = $this->fetchBatchHtml($items, $driver, $source);

        // Step 2: Sequential extract + save
        foreach ($items as $item) {
            $itemId = $item->id;

            if (! isset($htmlResults[$itemId])) {
                $errors++;

                continue;
            }

            try {
                $this->processItemDetail($item, $htmlResults[$itemId], $source, $config);
                $fetched++;
                $this->scraper->getMetrics()->itemsFetched++;
                $this->scraper->adaptDelay($source->delay_ms ?? 2000, false);
            } catch (\Throwable $e) {
                $errors++;
                $this->scraper->trackDetailError($item, $e);
                $this->scraper->adaptDelay($source->delay_ms ?? 2000, $e->getCode() === 429);
            }
        }

        if ($fetched > 0) {
            $job->increment('detail_fetched', $fetched);
        }

        return compact('fetched', 'errors');
    }

    /**
     * Fetch HTML for multiple items concurrently via Http::pool.
     *
     * @return array<int, string>  Map of item_id => HTML content
     */
    protected function fetchBatchHtml(
        Collection $items,
        DriverInterface &$driver,
        ScrapeSource $source,
    ): array {
        $headers = $source->default_headers ?? [];
        $htmlResults = [];

        if ($driver instanceof HttpDriver && $items->count() > 1) {
            $responses = Http::pool(function (Pool $pool) use ($items, $driver, $headers) {
                foreach ($items as $item) {
                    if (empty($item->source_url)) {
                        continue;
                    }

                    $driver->createPoolRequest(
                        $pool->as((string) $item->id), $headers, retries: 2,
                        url: $item->source_url, dnsCache: $this->scraper->getDnsCache()
                    )->get($item->source_url);
                }
            });

            $cfDetected = false;

            foreach ($items as $item) {
                if ($cfDetected) {
                    try {
                        $htmlResults[$item->id] = $driver->fetchHtml($item->source_url, $headers);
                    } catch (\Throwable $fallbackError) {
                        $this->scraper->trackDetailError($item, $fallbackError);
                    }

                    continue;
                }

                $response = $responses[(string) $item->id] ?? null;

                if ($response instanceof Response && $response->successful()) {
                    $body = $response->body();

                    try {
                        /** @var HttpDriver $httpDriver */
                        $httpDriver = $driver;
                        $httpDriver->detectCloudflarePublic($item->source_url, $body);
                        $htmlResults[$item->id] = $body;
                    } catch (CloudflareDetectedException $e) {
                        Log::warning('CF detected in pool response, switching to Playwright', [
                            'url' => $item->source_url,
                        ]);
                        $driver = new PlaywrightDriver();
                        $cfDetected = true;

                        try {
                            $htmlResults[$item->id] = $driver->fetchHtml($item->source_url, $headers);
                        } catch (\Throwable $fallbackError) {
                            $this->scraper->trackDetailError($item, $fallbackError);
                        }
                    }
                } elseif ($response instanceof Response) {
                    $this->scraper->trackDetailError($item, new \RuntimeException(
                        "HTTP {$response->status()}: {$item->source_url}"
                    ));
                } else {
                    $errorMsg = $response instanceof \Throwable
                        ? "Connection failed: {$item->source_url} — {$response->getMessage()}"
                        : "Connection failed: {$item->source_url}";
                    $this->scraper->trackDetailError($item, new \RuntimeException($errorMsg));
                }
            }
        } else {
            // Sequential fetch
            foreach ($items as $item) {
                if (empty($item->source_url) || $item->source_url === $source->base_url) {
                    continue;
                }

                try {
                    $htmlResults[$item->id] = $this->scraper->fetchWithCfFallback(
                        $driver, $item->source_url, $headers
                    );
                } catch (\Throwable $e) {
                    $this->scraper->trackDetailError($item, $e);
                }
            }
        }

        return $htmlResults;
    }

    /**
     * Fetch and extract detail content for a single item.
     */
    public function fetchItemDetail(
        ScrapeItem $item,
        DriverInterface $driver,
        ScrapeSource $source,
        array $config,
    ): void {
        $url = $item->source_url;
        if (empty($url) || $url === $source->base_url) {
            throw new \RuntimeException('Item has no valid detail URL');
        }

        $fetchStart = microtime(true);
        $html = $this->scraper->fetchWithCfFallback($driver, $url, $source->default_headers ?? []);
        $fetchTime = (int) ((microtime(true) - $fetchStart) * 1000);

        $this->processItemDetail($item, $html, $source, $config, $fetchTime);
    }

    /**
     * Process fetched HTML: clean → extract → validate → save.
     */
    protected function processItemDetail(
        ScrapeItem $item,
        string $html,
        ScrapeSource $source,
        array $config,
        int $fetchTimeMs = 0,
    ): void {
        $extractStart = microtime(true);

        // Step 1: Clean page HTML (remove unwanted elements)
        $cleanedHtml = $this->pipeline->cleanPageHtml($html, $config);

        // Step 2: Extract content
        $detailData = $this->extractor->extractDetail($cleanedHtml, $config, $source);

        $extractTime = (int) ((microtime(true) - $extractStart) * 1000);

        // Step 3: Process content through pipeline (remove patterns → normalize → validate)
        if (! empty($detailData['content'])) {
            $processed = $this->pipeline->process($detailData['content'], $config);
            $detailData['content'] = $processed->content;
            $detailData['_content_hash'] = $processed->contentHash;
            $detailData['_validation_issues'] = $processed->validationIssues;

            $this->scraper->getMetrics()->itemsWithContent++;
        }

        // Step 4: Add timing metrics
        $detailData['_timing'] = [
            'fetch_ms'   => $fetchTimeMs,
            'extract_ms' => $extractTime,
            'total_ms'   => $fetchTimeMs + $extractTime,
        ];

        // Step 5: Merge into existing raw_data
        $rawData = $item->raw_data ?? [];
        $phase1Title = $rawData['title'] ?? '';
        $phase2Title = $detailData['title'] ?? '';

        $rawData = array_merge($rawData, $detailData);

        // Clear previous error metadata on success
        unset(
            $rawData['_detail_error'],
            $rawData['_error_type'],
            $rawData['_retry_count'],
            $rawData['_last_error_at']
        );

        // Keep Phase 1 title if longer/better
        if (! empty($phase1Title) && ! empty($phase2Title)) {
            if (mb_strlen($phase1Title) > mb_strlen($phase2Title)) {
                $rawData['title'] = $phase1Title;
            }
        } elseif (! empty($phase1Title) && empty($phase2Title)) {
            $rawData['title'] = $phase1Title;
        }

        $item->update(['raw_data' => $rawData]);
    }

    /**
     * Fetch detail content for specific items only (selective/bulk action).
     *
     * @param  Collection<ScrapeItem>  $items
     * @return array{fetched: int, errors: int, total: int}
     */
    public function fetchDetailForItems(ScrapeJob $job, $items): array
    {
        if (! $job->isChapterType() || ! $job->hasDetailConfig()) {
            throw new \RuntimeException('Detail fetch requires chapter type with detail_config');
        }

        $source = $job->source;
        $driver = $this->scraper->resolveDriver($source);
        $config = $job->detail_config;

        $total = $items->count();
        $fetched = 0;
        $errors = 0;

        foreach ($items as $item) {
            try {
                $this->fetchItemDetail($item, $driver, $source, $config);
                $fetched++;
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('Detail fetch failed for item', [
                    'item_id' => $item->id,
                    'url'     => $item->source_url,
                    'error'   => $e->getMessage(),
                ]);

                $rawData = $item->raw_data ?? [];
                $rawData['_detail_error'] = mb_substr($e->getMessage(), 0, 300);
                $item->update(['raw_data' => $rawData]);
            }

            if ($source->delay_ms > 0) {
                usleep($source->delay_ms * 1000);
            }
        }

        Log::info('Selective detail fetch completed', [
            'job_id'  => $job->id,
            'fetched' => $fetched,
            'errors'  => $errors,
            'total'   => $total,
        ]);

        return compact('fetched', 'errors', 'total');
    }
}
