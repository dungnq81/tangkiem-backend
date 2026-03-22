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
use App\Services\Scraper\Events\ScrapeJobCompleted;
use App\Services\Scraper\Events\ScrapeJobFailed;
use App\Services\Scraper\Events\ScrapeJobStarted;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Scraper orchestrator — coordinates fetch, extract, and save phases.
 *
 * Delegates specialized work to:
 * - ContentExtractor: CSS/AI data extraction
 * - ContentPipeline:  Content cleaning, transformation, validation
 * - DetailFetcher:    Phase 2 batch detail page fetching
 *
 * Keeps: orchestration, pagination, persistence, driver resolution,
 * CF fallback, rate limiting, error tracking.
 */
class ScraperService
{
    // ═══════════════════════════════════════════════════════════════════════
    // State
    // ═══════════════════════════════════════════════════════════════════════

    protected int $currentDelayMs = 0;

    protected int $consecutiveSuccess = 0;

    /** @var array<string, ?string> In-memory DNS cache for pool requests */
    protected array $dnsCache = [];

    protected ContentExtractor $extractor;

    protected ContentPipeline $pipeline;

    protected DetailFetcher $detailFetcher;

    protected ScrapeMetrics $metrics;

    public function __construct()
    {
        $this->extractor = new ContentExtractor();
        $this->pipeline = ContentPipeline::default();
        $this->detailFetcher = new DetailFetcher($this, $this->extractor, $this->pipeline);
        $this->metrics = new ScrapeMetrics();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Public API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Run a scrape job: fetch pages → parse items → save as draft ScrapeItems.
     */
    public function execute(ScrapeJob $job): void
    {
        $source = $job->source;
        $driver = $this->resolveDriver($source);

        $job->markScraping();
        $this->metrics->start();
        event(new ScrapeJobStarted($job, 'toc'));

        try {
            $pagination = $job->pagination;
            $type = $pagination['type'] ?? 'single';

            if ($type === 'next_link') {
                $totalPages = $this->scrapeWithNextLink($job, $driver, $source);
            } elseif ($type === 'query_param' && (! isset($pagination['end_page']) || $pagination['end_page'] === '' || $pagination['end_page'] === null)) {
                $totalPages = $this->scrapePagesAuto($job, $driver, $source);
            } else {
                $pages = $this->resolvePages($job->target_url, $pagination);
                $totalPages = $this->scrapePages($job, $driver, $source, $pages);
            }

            $job->update(['total_pages' => $totalPages]);
            $job->markScraped();

            $this->metrics->stop();
            $this->saveMetrics($job);
            event(new ScrapeJobCompleted($job, $this->metrics));
        } catch (\Throwable $e) {
            Log::error('Scrape failed', [
                'job_id' => $job->id,
                'error'  => $e->getMessage(),
            ]);
            $job->markFailed($e->getMessage());

            $this->metrics->stop();
            $this->saveMetrics($job);
            event(new ScrapeJobFailed($job, $e->getMessage(), $e));
        }
    }

    /**
     * Execute a chapter_detail job: fetch ONE chapter page → extract content → save as single ScrapeItem.
     */
    public function executeChapterDetail(ScrapeJob $job): void
    {
        $source = $job->source;
        $driver = $this->resolveDriver($source);

        $job->markScraping();
        event(new ScrapeJobStarted($job, 'chapter_detail'));

        try {
            $url = $job->target_url;
            $html = $this->fetchWithCfFallback($driver, $url, $source->default_headers ?? []);

            $config = $job->detail_config ?? [];
            $defaults = $job->import_defaults ?? [];

            // Clean page HTML → Extract content → Process through pipeline
            $cleanedHtml = $this->pipeline->cleanPageHtml($html, $config);
            $detailData = $this->extractor->extractDetail($cleanedHtml, $config, $source);

            // If no specific extraction worked, fallback to body content
            if (empty($detailData['content'])) {
                $crawler = new Crawler($html);
                $body = $crawler->filter('body');
                if ($body->count() > 0) {
                    $detailData['content'] = trim($body->html());
                }
            }

            // Process content through pipeline (remove patterns → normalize → validate)
            if (! empty($detailData['content'])) {
                $processed = $this->pipeline->process($detailData['content'], $config);
                $detailData['content'] = $processed->content;
                $detailData['_content_hash'] = $processed->contentHash;
                $detailData['_validation_issues'] = $processed->validationIssues;
            }

            // Merge chapter_number from import_defaults
            $chapterNumber = $defaults['chapter_number'] ?? null;
            if ($chapterNumber !== null) {
                $detailData['chapter_number'] = (float) $chapterNumber;
            }

            // Build the raw_data payload
            $rawData = array_merge([
                'title'          => $detailData['title'] ?? null,
                'content'        => $detailData['content'] ?? null,
                'chapter_number' => $detailData['chapter_number'] ?? $chapterNumber,
                'volume_number'  => $detailData['volume_number'] ?? 1,
                'url'            => $url,
            ], $detailData);

            // Save as a single ScrapeItem (upsert by source_hash)
            $sourceHash = ScrapeItem::hashUrl($url);

            $existing = ScrapeItem::where('job_id', $job->id)
                ->where('source_hash', $sourceHash)
                ->first();

            if ($existing) {
                $existing->update([
                    'raw_data'      => $rawData,
                    'source_url'    => $url,
                    'status'        => ScrapeItem::STATUS_DRAFT,
                    'error_message' => null,
                ]);
            } else {
                ScrapeItem::create([
                    'job_id'      => $job->id,
                    'raw_data'    => $rawData,
                    'source_url'  => $url,
                    'source_hash' => $sourceHash,
                    'status'      => ScrapeItem::STATUS_DRAFT,
                    'page_number' => 1,
                    'sort_order'  => 1,
                ]);
            }

            $job->update(['total_pages' => 1]);
            $job->markScraped();

            event(new ScrapeJobCompleted($job, $this->metrics));
        } catch (\Throwable $e) {
            Log::error('Chapter detail scrape failed', [
                'job_id' => $job->id,
                'error'  => $e->getMessage(),
            ]);
            $job->markFailed($e->getMessage());
            event(new ScrapeJobFailed($job, $e->getMessage(), $e));
        }
    }

    /**
     * Test scrape: fetch first page only, return raw items (no saving).
     */
    public function testScrape(
        string $url,
        array $selectors,
        string $entityType,
        ScrapeSource $source,
        ?string $aiPrompt = null,
    ): array {
        $driver = $this->resolveDriver($source);
        $html = $driver->fetchHtml($url, $source->default_headers ?? []);

        if ($source->usesAi()) {
            $prompt = $aiPrompt ?? $source->ai_prompt_template;
            $extractor = app(\App\Services\Scraper\Drivers\AiExtractor::class);

            return $extractor->extract(
                html: $html,
                prompt: $prompt,
                entityType: $entityType,
                provider: $source->ai_provider,
                model: $source->ai_model,
            );
        }

        return $this->extractor->parseItems($html, $selectors, $entityType);
    }

    /**
     * Phase 2: Fetch detail pages for chapter items.
     * Delegates to DetailFetcher.
     */
    public function fetchDetails(ScrapeJob $job, ?int $limit = null): void
    {
        $this->detailFetcher->fetchDetails($job, $limit);
    }

    /**
     * Fetch detail content for specific items only (selective/bulk action).
     * Delegates to DetailFetcher.
     */
    public function fetchDetailForItems(ScrapeJob $job, $items): array
    {
        return $this->detailFetcher->fetchDetailForItems($job, $items);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Pagination Strategies
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Scrape using pre-resolved page URLs (single/query_param).
     * Supports concurrent TOC page fetching for query_param.
     */
    protected function scrapePages(
        ScrapeJob $job,
        DriverInterface $driver,
        ScrapeSource $source,
        array $pages,
    ): int {
        $totalPages = count($pages);
        $concurrency = max(1, $source->max_concurrency ?? 3);
        $headers = $source->default_headers ?? [];

        // Sequential for single page or when concurrency is 1
        if ($totalPages <= 1 || $concurrency <= 1) {
            foreach ($pages as $pageNum => $pageUrl) {
                if ($this->isCancelled($job)) {
                    return $pageNum > 0 ? $pageNum - 1 : 0;
                }

                $job->update(['current_page' => $pageNum]);

                $html = $this->fetchWithCfFallback($driver, $pageUrl, $headers);
                $items = $this->extractor->extractItems($html, $job, $source);
                $this->saveItems($job, $items, $pageNum, $source->base_url);

                $this->metrics->pagesScraped++;
                $this->metrics->itemsExtracted += count($items);

                if ($source->delay_ms > 0 && $pageNum < $totalPages) {
                    usleep($source->delay_ms * 1000);
                }
            }

            return $totalPages;
        }

        // Concurrent TOC fetching for multi-page query_param
        $pagesCollection = collect($pages);
        foreach ($pagesCollection->chunk($concurrency) as $batch) {
            if ($this->isCancelled($job)) {
                return $batch->keys()->first();
            }

            $htmlResults = [];
            if ($driver instanceof HttpDriver) {
                $responses = Http::pool(function (Pool $pool) use ($batch, $driver, $headers) {
                    foreach ($batch as $pageNum => $pageUrl) {
                        $driver->createPoolRequest(
                            $pool->as((string) $pageNum), $headers, retries: 3,
                            url: $pageUrl, dnsCache: $this->dnsCache
                        )->get($pageUrl);
                    }
                });

                foreach ($batch as $pageNum => $pageUrl) {
                    $response = $responses[(string) $pageNum] ?? null;
                    if ($response instanceof Response && $response->successful()) {
                        $htmlResults[$pageNum] = $response->body();
                    } else {
                        try {
                            $htmlResults[$pageNum] = $this->fetchWithCfFallback($driver, $pageUrl, $headers);
                        } catch (\Throwable $e) {
                            Log::warning('TOC page fetch failed', [
                                'page' => $pageNum, 'url' => $pageUrl, 'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            } else {
                foreach ($batch as $pageNum => $pageUrl) {
                    try {
                        $htmlResults[$pageNum] = $this->fetchWithCfFallback($driver, $pageUrl, $headers);
                    } catch (\Throwable $e) {
                        Log::warning('TOC page fetch failed', [
                            'page' => $pageNum, 'url' => $pageUrl, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            foreach ($htmlResults as $pageNum => $html) {
                $job->update(['current_page' => $pageNum]);
                $items = $this->extractor->extractItems($html, $job, $source);
                $this->saveItems($job, $items, $pageNum, $source->base_url);

                $this->metrics->pagesScraped++;
                $this->metrics->itemsExtracted += count($items);
            }

            if ($source->delay_ms > 0) {
                usleep($source->delay_ms * 1000);
            }
        }

        return $totalPages;
    }

    /**
     * Scrape by following "next page" links until exhausted or max_pages reached.
     */
    protected function scrapeWithNextLink(
        ScrapeJob $job,
        DriverInterface $driver,
        ScrapeSource $source,
    ): int {
        $pagination = $job->pagination;
        $nextSelector = $pagination['next_selector'] ?? '';
        $maxPages = (int) ($pagination['max_pages'] ?? 100);
        $currentUrl = $job->target_url;
        $pageNum = 0;

        if (empty($nextSelector)) {
            throw new \RuntimeException(
                'CSS selector liên kết phân trang chưa cấu hình. Vào tab Phân trang → nhập CSS selector cho nút chuyển trang (next hoặc prev).'
            );
        }

        while ($currentUrl && $pageNum < $maxPages) {
            if ($this->isCancelled($job)) {
                return $pageNum;
            }

            $pageNum++;
            $job->update(['current_page' => $pageNum]);

            $html = $this->fetchWithCfFallback($driver, $currentUrl, $source->default_headers ?? []);
            $items = $this->extractor->extractItems($html, $job, $source);
            $this->saveItems($job, $items, $pageNum, $source->base_url);

            $this->metrics->pagesScraped++;
            $this->metrics->itemsExtracted += count($items);

            $currentUrl = $this->findNextPageUrl($html, $nextSelector, $source->base_url);

            if ($currentUrl && $source->delay_ms > 0) {
                usleep($source->delay_ms * 1000);
            }
        }

        if ($pageNum >= $maxPages) {
            Log::info('Scrape reached max_pages limit', [
                'job_id'    => $job->id,
                'max_pages' => $maxPages,
            ]);
        }

        return $pageNum;
    }

    /**
     * Auto-scrape pages incrementally until no items found or max_pages reached.
     */
    protected function scrapePagesAuto(
        ScrapeJob $job,
        DriverInterface $driver,
        ScrapeSource $source,
    ): int {
        $pagination = $job->pagination;
        $start = (int) ($pagination['start_page'] ?? 0);
        $maxPages = (int) ($pagination['max_pages'] ?? 100);
        $pattern = $pagination['url_pattern'] ?? $job->target_url;
        $headers = $source->default_headers ?? [];

        $pageNum = $start;
        $pagesScraped = 0;
        $hasFoundData = false;
        $consecutiveEmpty = 0;

        while ($pagesScraped < $maxPages) {
            if ($this->isCancelled($job)) {
                return $pagesScraped;
            }

            $pageUrl = str_replace('{page}', (string) $pageNum, $pattern);
            $job->update(['current_page' => $pageNum]);

            try {
                $html = $this->fetchWithCfFallback($driver, $pageUrl, $headers);
                $items = $this->extractor->extractItems($html, $job, $source);
            } catch (\Throwable $e) {
                Log::warning('Auto-pagination: page fetch failed, stopping', [
                    'job_id' => $job->id,
                    'page'   => $pageNum,
                    'error'  => $e->getMessage(),
                ]);
                break;
            }

            if (empty($items)) {
                if ($hasFoundData) {
                    Log::info('Auto-pagination: empty page after data, stopping', [
                        'job_id'        => $job->id,
                        'page'          => $pageNum,
                        'pages_scraped' => $pagesScraped,
                    ]);
                    break;
                }

                $consecutiveEmpty++;
                if ($consecutiveEmpty >= 3) {
                    Log::info('Auto-pagination: 3 consecutive empty pages, no data found, stopping', [
                        'job_id' => $job->id,
                        'page'   => $pageNum,
                    ]);
                    break;
                }

                Log::info('Auto-pagination: page empty, trying next', [
                    'job_id'            => $job->id,
                    'page'              => $pageNum,
                    'consecutive_empty' => $consecutiveEmpty,
                ]);
                $pageNum++;

                continue;
            }

            $hasFoundData = true;
            $this->saveItems($job, $items, $pageNum, $source->base_url);
            $pagesScraped++;

            $this->metrics->pagesScraped++;
            $this->metrics->itemsExtracted += count($items);

            if ($source->delay_ms > 0) {
                usleep($source->delay_ms * 1000);
            }

            $pageNum++;
        }

        if ($pagesScraped >= $maxPages) {
            Log::info('Auto-pagination: reached max_pages limit', [
                'job_id'    => $job->id,
                'max_pages' => $maxPages,
            ]);
        }

        return $pagesScraped;
    }

    /**
     * Find the next page URL by CSS selector from the current page HTML.
     */
    protected function findNextPageUrl(string $html, string $selector, string $baseUrl): ?string
    {
        try {
            $crawler = new Crawler($html);
            $nextLink = $crawler->filter($selector);

            if ($nextLink->count() === 0) {
                return null;
            }

            $href = $nextLink->first()->attr('href');
            if (! $href || $href === '#' || $href === 'javascript:void(0)') {
                return null;
            }

            return $this->resolveAbsoluteUrl($href, $baseUrl);
        } catch (\Exception $e) {
            Log::warning('Failed to find next page link', [
                'selector' => $selector,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Cancellation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if the job was cancelled (user clicked "Stop" in admin).
     */
    public function isCancelled(ScrapeJob $job): bool
    {
        $job->refresh();

        if ($job->status !== ScrapeJob::STATUS_SCRAPING) {
            Log::info('Scrape job cancelled by user', [
                'job_id'         => $job->id,
                'current_status' => $job->status,
            ]);

            return true;
        }

        return false;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Persistence
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Save extracted items as draft ScrapeItems (dedup by source_hash).
     */
    protected function saveItems(ScrapeJob $job, array $items, int $pageNum, string $baseUrl): void
    {
        $resolved = [];
        foreach ($items as $index => $rawData) {
            $sourceUrl = $this->resolveItemUrl($rawData, $baseUrl);
            $resolved[] = [
                'rawData'    => $rawData,
                'sourceUrl'  => $sourceUrl,
                'sourceHash' => ScrapeItem::hashUrl($sourceUrl),
                'sortOrder'  => $pageNum * 1000 + $index,
            ];
        }

        // Dedup by sourceHash within the same page
        $resolved = collect($resolved)->keyBy('sourceHash')->values()->all();

        // Batch lookup
        $allHashes = array_column($resolved, 'sourceHash');
        $existingItems = ScrapeItem::where('job_id', $job->id)
            ->whereIn('source_hash', $allHashes)
            ->get()
            ->keyBy('source_hash');

        $newRows = [];

        foreach ($resolved as $item) {
            $existing = $existingItems->get($item['sourceHash']);

            if ($existing) {
                $existingData = $existing->raw_data ?? [];
                $mergedData = $item['rawData'];

                // Preserve Phase 2 metadata
                $preserveKeys = [
                    'content', '_detail_error', '_error_type', '_retry_count',
                    '_last_error_at', '_content_hash', '_validation_issues', '_timing',
                ];
                foreach ($preserveKeys as $key) {
                    if (! empty($existingData[$key]) && ! isset($mergedData[$key])) {
                        $mergedData[$key] = $existingData[$key];
                    }
                }

                $existing->update([
                    'raw_data'    => $mergedData,
                    'source_url'  => $item['sourceUrl'],
                    'page_number' => $pageNum,
                    'sort_order'  => $item['sortOrder'],
                ]);
            } else {
                $newRows[] = [
                    'job_id'      => $job->id,
                    'source_hash' => $item['sourceHash'],
                    'raw_data'    => json_encode($item['rawData']),
                    'source_url'  => $item['sourceUrl'],
                    'status'      => ScrapeItem::STATUS_DRAFT,
                    'page_number' => $pageNum,
                    'sort_order'  => $item['sortOrder'],
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }
        }

        if (! empty($newRows)) {
            foreach (array_chunk($newRows, 200) as $chunk) {
                ScrapeItem::insert($chunk);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // URL Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Resolve full URL for an item from its raw data.
     */
    public function resolveItemUrl(array $rawData, string $baseUrl): string
    {
        $url = $rawData['url'] ?? $rawData['href'] ?? '';

        if (empty($url)) {
            return $baseUrl;
        }

        return $this->resolveAbsoluteUrl($url, $baseUrl);
    }

    /**
     * Resolve a potentially relative URL to absolute.
     */
    public function resolveAbsoluteUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return "{$scheme}:{$url}";
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Resolve page URLs from pagination config (for query_param type).
     */
    public function resolvePages(string $baseUrl, ?array $pagination): array
    {
        if (! $pagination) {
            return [1 => $baseUrl];
        }

        $type = $pagination['type'] ?? 'single';

        if ($type === 'query_param') {
            $start = (int) ($pagination['start_page'] ?? 0);
            $end = (int) ($pagination['end_page'] ?? $start);
            $pattern = $pagination['url_pattern'] ?? $baseUrl;

            $pages = [];

            if ($start <= $end) {
                for ($i = $start; $i <= $end; $i++) {
                    $pages[$i] = str_replace('{page}', (string) $i, $pattern);
                }
            } else {
                for ($i = $start; $i >= $end; $i--) {
                    $pages[$i] = str_replace('{page}', (string) $i, $pattern);
                }
            }

            return $pages;
        }

        return [1 => $baseUrl];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Driver & Fetch
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Resolve the driver based on source render_type.
     */
    public function resolveDriver(ScrapeSource $source): DriverInterface
    {
        if ($source->isSpa()) {
            return new PlaywrightDriver();
        }

        return new HttpDriver();
    }

    /**
     * Fetch HTML with automatic Cloudflare fallback.
     *
     * The $driver parameter is passed by reference — once CF is detected,
     * the driver is permanently switched to PlaywrightDriver.
     *
     * @throws CloudflareDetectedException  If CF cannot be bypassed
     */
    public function fetchWithCfFallback(
        DriverInterface &$driver,
        string $url,
        array $headers = [],
    ): string {
        try {
            return $driver->fetchHtml($url, $headers);
        } catch (CloudflareDetectedException $e) {
            if (! $driver instanceof HttpDriver) {
                throw $e;
            }

            Log::warning('HttpDriver hit Cloudflare, switching to PlaywrightDriver', [
                'url'     => $url,
                'cf_type' => $e->cfType,
            ]);

            $this->metrics->cfDetections++;
            $driver = new PlaywrightDriver();

            return $driver->fetchHtml($url, $headers);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Error Tracking
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Track detail fetch error on an item with categorization and retry count.
     */
    public function trackDetailError(ScrapeItem $item, \Throwable $e): void
    {
        $errorType = $this->categorizeError($e);
        $rawData = $item->raw_data ?? [];
        $retryCount = ($rawData['_retry_count'] ?? 0);

        if ($errorType === 'transient') {
            $retryCount++;
        }

        $rawData['_detail_error'] = mb_substr($e->getMessage(), 0, 300);
        $rawData['_error_type'] = $errorType;
        $rawData['_retry_count'] = $retryCount;
        $rawData['_last_error_at'] = now()->toDateTimeString();

        $item->update(['raw_data' => $rawData]);

        $this->metrics->errors++;

        Log::warning('Detail fetch failed for item', [
            'item_id'     => $item->id,
            'url'         => $item->source_url,
            'error'       => mb_substr($e->getMessage(), 0, 200),
            'error_type'  => $errorType,
            'retry_count' => $retryCount,
        ]);
    }

    /**
     * Categorize an error as transient (retriable) or permanent (skip).
     */
    public function categorizeError(\Throwable $e): string
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        if ($code === 404 || str_contains($message, '404')) {
            return 'permanent';
        }
        if ($code === 410) {
            return 'permanent';
        }
        if (str_contains($message, 'no valid detail URL')) {
            return 'permanent';
        }
        if ($e instanceof CloudflareDetectedException && $e->cfType === 'turnstile') {
            return 'permanent';
        }
        if ($code === 403 || str_contains($message, '403 Forbidden')) {
            return 'permanent';
        }

        return 'transient';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Adaptive Rate Limiting
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Initialize rate limiter with base delay.
     */
    public function initRateLimiter(int $baseDelayMs): void
    {
        $this->currentDelayMs = $baseDelayMs;
        $this->consecutiveSuccess = 0;
    }

    /**
     * Get current delay in milliseconds.
     */
    public function getCurrentDelayMs(): int
    {
        return $this->currentDelayMs;
    }

    /**
     * Get DNS cache reference (for pool requests in DetailFetcher).
     *
     * @return array<string, ?string>
     */
    public function &getDnsCache(): array
    {
        return $this->dnsCache;
    }

    /**
     * Adjust delay between batches based on server response.
     */
    public function adaptDelay(int $baseDelay, bool $wasRateLimited): void
    {
        if ($wasRateLimited) {
            $this->currentDelayMs = min(30000, $this->currentDelayMs * 2);
            $this->consecutiveSuccess = 0;
            Log::info('Rate limited — increasing delay', [
                'delay_ms' => $this->currentDelayMs,
            ]);
        } else {
            $this->consecutiveSuccess++;
            if ($this->consecutiveSuccess >= 10) {
                $this->currentDelayMs = max($baseDelay, (int) ($this->currentDelayMs * 0.9));
                $this->consecutiveSuccess = 0;
            }
        }
    }

    /**
     * Parse PHP's memory_limit into bytes.
     */
    public function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === false) {
            return 0;
        }

        $value = (int) $limit;
        $suffix = strtoupper(substr(trim($limit), -1));

        return match ($suffix) {
            'G' => $value * 1073741824,
            'M' => $value * 1048576,
            'K' => $value * 1024,
            default => $value,
        };
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Metrics
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get current metrics (for external access).
     */
    public function getMetrics(): ScrapeMetrics
    {
        return $this->metrics;
    }

    /**
     * Persist metrics to scrape_jobs table.
     */
    protected function saveMetrics(ScrapeJob $job): void
    {
        try {
            $job->update(['error_log' => json_encode($this->metrics->toArray())]);
        } catch (\Throwable $e) {
            Log::debug('Failed to save scrape metrics', ['error' => $e->getMessage()]);
        }
    }
}
