<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\Chapter;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use App\Services\Scraper\Drivers\AiExtractor;
use App\Services\Scraper\Drivers\CloudflareDetectedException;
use App\Services\Scraper\Drivers\DriverInterface;
use App\Services\Scraper\Drivers\HttpDriver;
use App\Services\Scraper\Drivers\PlaywrightDriver;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class ScraperService
{
    // ═══════════════════════════════════════════════════════════════════════
    // Adaptive Rate Limiting (TIER 4)
    // ═══════════════════════════════════════════════════════════════════════

    protected int $currentDelayMs = 0;

    protected int $consecutiveSuccess = 0;

    /** @var array<string, ?string> In-memory DNS cache for pool requests */
    protected array $dnsCache = [];
    /**
     * Run a scrape job: fetch pages → parse items → save as draft ScrapeItems.
     */
    public function execute(ScrapeJob $job): void
    {
        $source = $job->source;
        $driver = $this->resolveDriver($source);

        $job->markScraping();

        try {
            $pagination = $job->pagination;
            $type = $pagination['type'] ?? 'single';

            if ($type === 'next_link') {
                $totalPages = $this->scrapeWithNextLink($job, $driver, $source);
            } else {
                $pages = $this->resolvePages($job->target_url, $pagination);
                $totalPages = $this->scrapePages($job, $driver, $source, $pages);
            }

            $job->update(['total_pages' => $totalPages]);
            $job->markScraped();
        } catch (\Throwable $e) {
            Log::error('Scrape failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
            $job->markFailed($e->getMessage());
        }
    }

    /**
     * Execute a chapter_detail job: fetch ONE chapter page → extract content → save as single ScrapeItem.
     *
     * Unlike execute() which scrapes a TOC listing, this directly scrapes
     * the chapter content page and stores it for review/editing before import.
     */
    public function executeChapterDetail(ScrapeJob $job): void
    {
        $source = $job->source;
        $driver = $this->resolveDriver($source);

        $job->markScraping();

        try {
            $url = $job->target_url;
            $html = $this->fetchWithCfFallback($driver, $url, $source->default_headers ?? []);

            $config = $job->detail_config ?? [];
            $defaults = $job->import_defaults ?? [];

            // Extract content using the same pipeline as chapter detail fetching
            $detailData = [];

            if (! empty($config['content_selector'])) {
                // CSS extraction path (fast, free)
                $detailData = $this->extractDetailWithCss($html, $config);

                // Apply text pattern removal if configured
                if (! empty($detailData['content'])) {
                    $detailData['content'] = $this->removeTextPatterns($detailData['content'], $config);
                }
            } elseif ($source->usesAi()) {
                // AI extraction path
                $detailData = $this->extractDetailHybrid($html, $config, $source);
            } else {
                // Fallback: try to get content from the whole page body
                $crawler = new Crawler($html);
                $body = $crawler->filter('body');
                if ($body->count() > 0) {
                    $detailData['content'] = trim($body->html());
                }
            }

            // Merge chapter_number from import_defaults
            $chapterNumber = $defaults['chapter_number'] ?? null;
            if ($chapterNumber !== null) {
                $detailData['chapter_number'] = (float) $chapterNumber;
            }

            // Build the raw_data payload (same structure as chapter items)
            $rawData = array_merge([
                'title' => $detailData['title'] ?? null,
                'content' => $detailData['content'] ?? null,
                'chapter_number' => $detailData['chapter_number'] ?? $chapterNumber,
                'volume_number' => $detailData['volume_number'] ?? 1,
                'url' => $url,
            ], $detailData);

            // Save as a single ScrapeItem (upsert by source_hash)
            $sourceHash = ScrapeItem::hashUrl($url);

            $existing = ScrapeItem::where('job_id', $job->id)
                ->where('source_hash', $sourceHash)
                ->first();

            if ($existing) {
                $existing->update([
                    'raw_data' => $rawData,
                    'source_url' => $url,
                    'status' => ScrapeItem::STATUS_DRAFT,
                    'error_message' => null,
                ]);
            } else {
                ScrapeItem::create([
                    'job_id' => $job->id,
                    'raw_data' => $rawData,
                    'source_url' => $url,
                    'source_hash' => $sourceHash,
                    'status' => ScrapeItem::STATUS_DRAFT,
                    'page_number' => 1,
                    'sort_order' => 1,
                ]);
            }

            $job->update(['total_pages' => 1]);
            $job->markScraped();

        } catch (\Throwable $e) {
            Log::error('Chapter detail scrape failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
            $job->markFailed($e->getMessage());
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
            $extractor = app(AiExtractor::class);

            return $extractor->extract(
                html: $html,
                prompt: $prompt,
                entityType: $entityType,
                provider: $source->ai_provider,
                model: $source->ai_model,
            );
        }

        return $this->parseItems($html, $selectors, $entityType);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Pagination strategies
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Scrape using pre-resolved page URLs (single/query_param).
     *
     * TIER 5C: Supports concurrent TOC page fetching for query_param.
     * Falls back to sequential for single-page or small page counts.
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
                $items = $this->extractItems($html, $job, $source);
                $this->saveItems($job, $items, $pageNum, $source->base_url);

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

            // Concurrent fetch
            $htmlResults = [];
            if ($driver instanceof HttpDriver) {
                $responses = Http::pool(function (Pool $pool) use ($batch, $driver, $headers) {
                    foreach ($batch as $pageNum => $pageUrl) {
                        $this->configurePoolRequest(
                            $pool->as((string) $pageNum), $driver, $headers, retries: 3, url: $pageUrl
                        )->get($pageUrl);
                    }
                });

                foreach ($batch as $pageNum => $pageUrl) {
                    $response = $responses[(string) $pageNum] ?? null;
                    if ($response instanceof Response && $response->successful()) {
                        $htmlResults[$pageNum] = $response->body();
                    } else {
                        // Fallback to sequential fetch for failed page
                        // (pool returns ConnectionException on connection failure, not Response)
                        try {
                            $htmlResults[$pageNum] = $this->fetchWithCfFallback($driver, $pageUrl, $headers);
                        } catch (\Throwable $e) {
                            Log::warning('TOC page fetch failed', ['page' => $pageNum, 'url' => $pageUrl, 'error' => $e->getMessage()]);
                        }
                    }
                }
            } else {
                // PlaywrightDriver: sequential within batch (server handles its own concurrency)
                foreach ($batch as $pageNum => $pageUrl) {
                    try {
                        $htmlResults[$pageNum] = $this->fetchWithCfFallback($driver, $pageUrl, $headers);
                    } catch (\Throwable $e) {
                        Log::warning('TOC page fetch failed', ['page' => $pageNum, 'url' => $pageUrl, 'error' => $e->getMessage()]);
                    }
                }
            }

            // Process results sequentially
            foreach ($htmlResults as $pageNum => $html) {
                $job->update(['current_page' => $pageNum]);
                $items = $this->extractItems($html, $job, $source);
                $this->saveItems($job, $items, $pageNum, $source->base_url);
            }

            // Delay between batches
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
        $maxPages = (int) ($pagination['max_pages'] ?? 50);
        $currentUrl = $job->target_url;
        $pageNum = 0;

        if (empty($nextSelector)) {
            throw new \RuntimeException(
                'CSS selector liên kết phân trang chưa cấu hình. Vào tab Phân trang → nhập CSS selector cho nút chuyển trang (next hoặc prev).'
            );
        }

        while ($currentUrl && $pageNum < $maxPages) {
            // Check if job was cancelled (user clicked Stop)
            if ($this->isCancelled($job)) {
                return $pageNum;
            }

            $pageNum++;
            $job->update(['current_page' => $pageNum]);

            $html = $this->fetchWithCfFallback($driver, $currentUrl, $source->default_headers ?? []);
            $items = $this->extractItems($html, $job, $source);
            $this->saveItems($job, $items, $pageNum, $source->base_url);

            // Find next page URL
            $currentUrl = $this->findNextPageUrl($html, $nextSelector, $source->base_url);

            // Respect delay between requests
            if ($currentUrl && $source->delay_ms > 0) {
                usleep($source->delay_ms * 1000);
            }
        }

        if ($pageNum >= $maxPages) {
            Log::info('Scrape reached max_pages limit', [
                'job_id' => $job->id,
                'max_pages' => $maxPages,
            ]);
        }

        return $pageNum;
    }

    /**
     * Find the next page URL by CSS selector from the current page HTML.
     *
     * Looks for an <a> tag matching the selector and extracts its href.
     * Returns null if no link found (= last page).
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
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Cancellation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if the job was cancelled (user clicked "Stop" in admin).
     *
     * Refreshes the record from DB to get the latest status,
     * since the job runs in a queue worker and the status may
     * have been changed by the admin UI.
     */
    protected function isCancelled(ScrapeJob $job): bool
    {
        $job->refresh();

        if ($job->status !== ScrapeJob::STATUS_SCRAPING) {
            Log::info('Scrape job cancelled by user', [
                'job_id' => $job->id,
                'current_status' => $job->status,
            ]);

            return true;
        }

        return false;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Extraction
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Extract items from HTML using the appropriate method (AI or CSS).
     */
    protected function extractItems(string $html, ScrapeJob $job, ScrapeSource $source): array
    {
        if ($source->usesAi()) {
            return $this->extractWithAi($html, $job);
        }

        return $this->parseItems($html, $job->selectors, $job->entity_type);
    }

    /**
     * Extract items using AI provider.
     */
    protected function extractWithAi(string $html, ScrapeJob $job): array
    {
        $source = $job->source;
        $extractor = app(AiExtractor::class);

        return $extractor->extract(
            html: $html,
            prompt: $job->resolveAiPrompt(),
            entityType: $job->entity_type,
            provider: $source->ai_provider,
            model: $source->ai_model,
        );
    }

    /**
     * Parse HTML using CSS selectors and return array of raw data items.
     */
    protected function parseItems(string $html, array $selectors, string $entityType): array
    {
        $crawler = new Crawler($html);
        $container = $selectors['container'] ?? null;
        $fields = $selectors['fields'] ?? $selectors;

        // Remove 'container' from fields if present
        unset($fields['container']);

        if (! $container) {
            Log::warning('CSS Scrape: container selector is empty', [
                'entity_type' => $entityType,
            ]);

            return [];
        }

        $items = [];

        try {
            $containerNodes = $crawler->filter($container);
            $matchCount = $containerNodes->count();

            if ($matchCount === 0) {
                Log::warning('CSS Scrape: container selector matched 0 elements', [
                    'container' => $container,
                    'html_length' => strlen($html),
                ]);

                return [];
            }

            if ($matchCount === 1) {
                Log::info('CSS Scrape: container selector matched only 1 element — if you expected multiple items, the selector may be targeting the wrapper instead of individual items.', [
                    'container' => $container,
                    'tag' => $containerNodes->first()->nodeName(),
                    'children' => $containerNodes->first()->children()->count(),
                ]);
            }

            $containerNodes->each(function ($node) use ($fields, &$items) {
                $item = [];

                foreach ($fields as $fieldName => $selector) {
                    $item[$fieldName] = $this->extractField($node, $selector);
                }

                // Only add non-empty items
                if (array_filter($item)) {
                    $items[] = $item;
                }
            });
        } catch (\Exception $e) {
            Log::warning('Parse error', ['error' => $e->getMessage()]);
        }

        return $items;
    }

    /**
     * Extract a field value from a DOM node using extended CSS selector.
     *
     * Selector format:
     *   "h3 a"           → text content of h3 > a
     *   "h3 a@href"      → href attribute of h3 > a
     *   "img@src"         → src attribute of img
     *   "a[href]"         → auto-extracts href attribute (smart detection)
     *   "img[src]"        → auto-extracts src attribute (smart detection)
     *   ".cat-list a"     → text content (multiple → comma separated)
     */
    protected function extractField(Crawler $node, string $selector): ?string
    {
        // Check for explicit attribute extraction: "selector@attribute"
        $attribute = null;
        if (str_contains($selector, '@')) {
            [$selector, $attribute] = explode('@', $selector, 2);
        }

        // Auto-detect CSS attribute selectors: "a[href]", "img[src]", etc.
        // When user writes "a[href]" they usually mean "extract the href from <a>",
        // not just "match <a> tags that have href and get their text".
        if (! $attribute && preg_match('/\[(\w+)\]$/', $selector, $attrMatch)) {
            $detectedAttr = $attrMatch[1];
            // Only auto-extract for common link/media attributes
            if (in_array($detectedAttr, ['href', 'src', 'data-src', 'content', 'value', 'action'], true)) {
                $attribute = $detectedAttr;
            }
        }

        try {
            $found = $node->filter(trim($selector));

            if ($found->count() === 0) {
                return null;
            }

            if ($attribute) {
                return trim($found->first()->attr($attribute) ?? '');
            }

            // If multiple nodes, join with comma (e.g., category list)
            if ($found->count() > 1) {
                $texts = [];
                $found->each(function ($el) use (&$texts) {
                    $text = trim($el->text(''));
                    if ($text !== '') {
                        $texts[] = $text;
                    }
                });
                return implode(', ', $texts);
            }

            return trim($found->text(''));
        } catch (\Exception $e) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Phase 2: Chapter Detail Fetching
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Phase 2: Fetch detail pages for chapter items.
     *
     * UPGRADED with:
     * - TIER 1: Concurrent batch fetching (Http::pool)
     * - TIER 2: Smart retry for transient errors
     * - TIER 3: Content validation pipeline
     * - TIER 4: Adaptive rate limiting
     * - TIER 5: Timing metrics per item
     *
     * @param  int|null  $limit  Max items to fetch. null=ALL (manual mode), int=batch (scheduled mode).
     */
    public function fetchDetails(ScrapeJob $job, ?int $limit = null): void
    {
        if (! $job->isChapterType() || ! $job->hasDetailConfig()) {
            throw new \RuntimeException('Detail fetch requires chapter type with detail_config');
        }

        $source = $job->source;
        $driver = $this->resolveDriver($source);
        $config = $job->detail_config;
        $maxRetries = max(1, $source->max_retries ?? 3);
        $concurrency = max(1, $source->max_concurrency ?? 3);

        // Initialize adaptive rate limiter
        $this->currentDelayMs = $source->delay_ms ?? 2000;
        $this->consecutiveSuccess = 0;

        // TIER 2: Build base query using generated columns (indexed, no JSON scan)
        $baseQuery = fn () => $job->items()
            ->whereIn('status', [ScrapeItem::STATUS_DRAFT, ScrapeItem::STATUS_SELECTED])
            ->where(function ($q) use ($maxRetries) {
                // Items without content (normal fetch)
                $q->where('has_content', false)
                // OR items with transient errors below retry limit
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

            // Collect items for chunk-based processing
            $itemBuffer = collect();

            foreach ($items as $item) {
                // Safety guard: re-fetch as Eloquent model if query returned stdClass
                if (! $item instanceof ScrapeItem) {
                    $item = ScrapeItem::find($item->id ?? null);
                    if (! $item) {
                        $errors++;

                        continue;
                    }
                }

                $itemBuffer->push($item);

                // Process batch when buffer reaches concurrency size
                if ($itemBuffer->count() >= $concurrency) {
                    $results = $this->fetchDetailBatch(
                        $job, $itemBuffer, $driver, $source, $config
                    );
                    $fetched += $results['fetched'];
                    $errors += $results['errors'];
                    $itemBuffer = collect();

                    // Free circular references from processed Eloquent models
                    gc_collect_cycles();

                    // Check cancellation between batches
                    $job->refresh();
                    if ($job->detail_status !== ScrapeJob::DETAIL_STATUS_FETCHING) {
                        Log::info('Detail fetch cancelled', [
                            'job_id' => $job->id, 'fetched' => $fetched, 'errors' => $errors,
                        ]);

                        return;
                    }

                    // Memory safeguard: stop gracefully before OOM kill
                    $memoryUsage = memory_get_usage(true);
                    $memoryLimit = $this->getMemoryLimitBytes();
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
                    if ($this->currentDelayMs > 0) {
                        usleep($this->currentDelayMs * 1000);
                    }
                }
            }

            // Process remaining items in buffer
            if ($itemBuffer->isNotEmpty()) {
                $results = $this->fetchDetailBatch(
                    $job, $itemBuffer, $driver, $source, $config
                );
                $fetched += $results['fetched'];
                $errors += $results['errors'];
            }

            // Determine final detail status based on actual results
            $remaining = $baseQuery()->count();

            if ($fetched === 0 && $errors > 0) {
                // All items failed — don't misleadingly say "fetched"
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
     * TIER 1: Fetch and process a batch of items concurrently.
     *
     * Step 1: Concurrent HTML fetch (the slow part)
     * Step 2: Sequential extract + validate + save (fast, ~5ms each)
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
        $headers = $source->default_headers ?? [];

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
                $this->adaptDelay($source->delay_ms ?? 2000, false);
            } catch (\Throwable $e) {
                $errors++;
                $this->trackDetailError($item, $e);
                $this->adaptDelay($source->delay_ms ?? 2000, $e->getCode() === 429);
            }
        }

        // Batch increment detail_fetched (1 query per batch instead of per item)
        if ($fetched > 0) {
            $job->increment('detail_fetched', $fetched);
        }

        return compact('fetched', 'errors');
    }

    /**
     * TIER 1: Fetch HTML for multiple items concurrently.
     *
     * Uses Http::pool() for HttpDriver (truly concurrent).
     * Falls back to sequential for PlaywrightDriver (server handles its own concurrency).
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
            // Concurrent fetch via Http::pool()
            $responses = Http::pool(function (Pool $pool) use ($items, $driver, $headers) {
                foreach ($items as $item) {
                    if (empty($item->source_url)) {
                        continue;
                    }

                    $this->configurePoolRequest(
                        $pool->as((string) $item->id), $driver, $headers, retries: 2, url: $item->source_url
                    )->get($item->source_url);
                }
            });

            // Track whether CF was detected — once detected, remaining pool
            // responses are likely also CF-blocked, so re-fetch via new driver.
            $cfDetected = false;

            foreach ($items as $item) {
                // After CF switch, remaining pool responses are stale — fetch via Playwright
                if ($cfDetected) {
                    try {
                        $htmlResults[$item->id] = $driver->fetchHtml($item->source_url, $headers);
                    } catch (\Throwable $fallbackError) {
                        $this->trackDetailError($item, $fallbackError);
                    }

                    continue;
                }

                $response = $responses[(string) $item->id] ?? null;

                // Pool returns ConnectionException on connection failure, not Response.
                // Must check instanceof Response before calling ->successful().
                if ($response instanceof Response && $response->successful()) {
                    $body = $response->body();

                    // Check for CF in response body (only while still using HttpDriver)
                    try {
                        /** @var HttpDriver $driver */
                        $driver->detectCloudflarePublic($item->source_url, $body);
                        $htmlResults[$item->id] = $body;
                    } catch (CloudflareDetectedException $e) {
                        // CF detected — switch driver permanently for this job
                        Log::warning('CF detected in pool response, switching to Playwright', [
                            'url' => $item->source_url,
                        ]);
                        $driver = new PlaywrightDriver();
                        $cfDetected = true;

                        try {
                            $htmlResults[$item->id] = $driver->fetchHtml($item->source_url, $headers);
                        } catch (\Throwable $fallbackError) {
                            $this->trackDetailError($item, $fallbackError);
                        }
                    }
                } elseif ($response instanceof Response) {
                    // HTTP error — log and track
                    $this->trackDetailError($item, new \RuntimeException(
                        "HTTP {$response->status()}: {$item->source_url}"
                    ));
                } else {
                    // No response or ConnectionException
                    $errorMsg = $response instanceof \Throwable
                        ? "Connection failed: {$item->source_url} — {$response->getMessage()}"
                        : "Connection failed: {$item->source_url}";
                    $this->trackDetailError($item, new \RuntimeException($errorMsg));
                }
            }
        } else {
            // Sequential fetch (single item, PlaywrightDriver, or concurrency=1)
            foreach ($items as $item) {
                if (empty($item->source_url) || $item->source_url === $source->base_url) {
                    continue;
                }

                try {
                    $htmlResults[$item->id] = $this->fetchWithCfFallback(
                        $driver, $item->source_url, $headers
                    );
                } catch (\Throwable $e) {
                    $this->trackDetailError($item, $e);
                }
            }
        }

        return $htmlResults;
    }

    /**
     * Fetch and extract detail content for a single chapter item.
     *
     * Used by fetchDetailForItems() (bulk UI action) — sequential mode.
     * The main fetchDetails() pipeline uses fetchDetailBatch() instead.
     */
    protected function fetchItemDetail(
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
        $html = $this->fetchWithCfFallback($driver, $url, $source->default_headers ?? []);
        $fetchTime = (int) ((microtime(true) - $fetchStart) * 1000);

        $this->processItemDetail($item, $html, $source, $config, $fetchTime);
    }

    /**
     * Process fetched HTML: extract detail data, validate, and save.
     *
     * Separated from fetchItemDetail() so concurrent batch fetching
     * can call this after Html is fetched via Http::pool().
     */
    protected function processItemDetail(
        ScrapeItem $item,
        string $html,
        ScrapeSource $source,
        array $config,
        int $fetchTimeMs = 0,
    ): void {
        $extractStart = microtime(true);

        // Strategy: CSS-first, AI as supplement
        if ($source->usesAi()) {
            $detailData = $this->extractDetailHybrid($html, $config, $source);
        } else {
            $detailData = $this->extractDetailWithCss($html, $config);
        }

        $extractTime = (int) ((microtime(true) - $extractStart) * 1000);

        // Remove text patterns from extracted content (more reliable than HTML-level)
        if (! empty($detailData['content'])) {
            $detailData['content'] = $this->removeTextPatterns($detailData['content'], $config);
        }

        // TIER 3: Content validation
        $detailData = $this->validateDetailContent($detailData);

        // TIER 5B: Timing metrics
        $detailData['_timing'] = [
            'fetch_ms'   => $fetchTimeMs,
            'extract_ms' => $extractTime,
            'total_ms'   => $fetchTimeMs + $extractTime,
        ];

        // Merge detail data into existing raw_data
        $rawData = $item->raw_data ?? [];
        $phase1Title = $rawData['title'] ?? '';
        $phase2Title = $detailData['title'] ?? '';

        $rawData = array_merge($rawData, $detailData);

        // Clear previous error/retry metadata on success
        unset($rawData['_detail_error'], $rawData['_error_type'], $rawData['_retry_count'], $rawData['_last_error_at']);

        // Keep Phase 1 title if Phase 2 title is shorter/generic
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
     * Hybrid extraction: use CSS to get content, AI for metadata.
     *
     * Strategy:
     * 1. If content_selector exists → CSS extracts content (fast, no token cost)
     * 2. AI only processes small metadata (title, chapter_number, volume_number)
     *    using the page's header area instead of the full content
     * 3. If no content_selector → fall back to full AI extraction with aggressive truncation
     */
    protected function extractDetailHybrid(string $html, array $config, ScrapeSource $source): array
    {
        $contentSelector = $config['content_selector'] ?? null;

        // No content_selector → full AI extraction (truncated)
        if (! $contentSelector) {
            return $this->extractDetailWithAi($html, $config, $source);
        }

        // CSS extracts content + metadata (fast, reliable, no token cost)
        // When content_selector is set, AI is NOT used at all.
        // Missing metadata (title, chapter_number) will be filled from Phase 1 (TOC) data.
        $cssData = $this->extractDetailWithCss($html, $config);

        if (! empty($cssData['content'])) {
            return $cssData;
        }

        // CSS failed to extract content → fallback to full AI (truncated)
        Log::info('CSS content_selector found nothing, falling back to AI', [
            'selector' => $contentSelector,
        ]);

        return $this->extractDetailWithAi($html, $config, $source);
    }

    /**
     * Fetch detail content for specific items only (batch/selective fetch).
     *
     * Unlike fetchDetails() which fetches ALL unfetched items,
     * this method only fetches the given items — useful for UI bulk actions
     * where the user selects specific chapters to fetch.
     *
     * @param  \Illuminate\Support\Collection<ScrapeItem>  $items
     * @return array{fetched: int, errors: int, total: int}
     */
    public function fetchDetailForItems(ScrapeJob $job, $items): array
    {
        if (! $job->isChapterType() || ! $job->hasDetailConfig()) {
            throw new \RuntimeException('Detail fetch requires chapter type with detail_config');
        }

        $source = $job->source;
        $driver = $this->resolveDriver($source);
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

            // Respect delay between requests
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

    /**
     * Extract chapter detail data using CSS selectors.
     *
     * @return array{content: ?string, title: ?string, chapter_number: ?float, volume_number: ?int}
     */
    protected function extractDetailWithCss(string $html, array $config): array
    {
        // Apply remove_selectors on FULL page HTML first (before content extraction).
        // This ensures selectors like '.khung-chinh .truyen:last-child' work correctly
        // since they reference ancestors that exist in the full page but not inside extracted content.
        $html = $this->cleanDetailContent($html, $config);

        $crawler = new Crawler($html);
        $data = [];

        // 1) Content — required
        $contentSelector = $config['content_selector'] ?? null;
        if ($contentSelector) {
            $contentNode = $crawler->filter($contentSelector);
            if ($contentNode->count() > 0) {
                $data['content'] = trim($contentNode->first()->html());
            }
        }

        // 2) Title — optional override from detail page
        $titleSelector = $config['title_selector'] ?? null;
        if ($titleSelector) {
            try {
                $titleNode = $crawler->filter($titleSelector);
                if ($titleNode->count() > 0) {
                    $data['title'] = trim($titleNode->first()->text(''));
                }
            } catch (\Exception $e) {
                // Non-critical, ignore
            }
        }

        // 3) Chapter number — optional from detail page
        $chapterNumSelector = $config['chapter_number_selector'] ?? null;
        if ($chapterNumSelector) {
            try {
                $numNode = $crawler->filter($chapterNumSelector);
                if ($numNode->count() > 0) {
                    $numText = $numNode->first()->text('');
                    if (preg_match('/(\d+(?:\.\d+)?[a-zA-Z]?)/', $numText, $m)) {
                        $data['chapter_number'] = Chapter::normalizeChapterNumber($m[1]);
                    }
                }
            } catch (\Exception $e) {
                // Non-critical
            }
        }

        // 4) Volume — optional
        $volumeSelector = $config['volume_selector'] ?? null;
        if ($volumeSelector) {
            try {
                $volNode = $crawler->filter($volumeSelector);
                if ($volNode->count() > 0) {
                    $volText = $volNode->first()->text('');
                    if (preg_match('/(\d+)/', $volText, $m)) {
                        $data['volume_number'] = (int) $m[1];
                    }
                }
            } catch (\Exception $e) {
                // Non-critical
            }
        }

        return $data;
    }

    /**
     * Clean chapter content HTML by removing unwanted elements.
     *
     * Uses remove_selectors from detail_config + source-level clean_patterns.
     */
    protected function cleanDetailContent(string $html, array $config): string
    {
        $removeSelectors = $config['remove_selectors'] ?? null;
        if (empty($removeSelectors)) {
            return $html;
        }

        // Parse newline-separated selectors string
        if (is_string($removeSelectors)) {
            $selectors = array_filter(array_map('trim', explode("\n", $removeSelectors)));
        } else {
            $selectors = (array) $removeSelectors;
        }

        if (empty($selectors)) {
            return $html;
        }

        try {
            $crawler = new Crawler('<div id="__wrapper__">' . $html . '</div>');
            $wrapper = $crawler->filter('#__wrapper__');

            foreach ($selectors as $selector) {
                $selector = trim($selector);
                if (empty($selector)) {
                    continue;
                }

                try {
                    // Collect all matching nodes first, then remove in reverse
                    // to avoid index-shifting issues during DOM mutation
                    $nodes = $wrapper->filter($selector);
                    $domNodes = [];
                    $nodes->each(function (Crawler $node) use (&$domNodes) {
                        $domNodes[] = $node->getNode(0);
                    });

                    foreach (array_reverse($domNodes) as $domNode) {
                        if ($domNode->parentNode) {
                            $domNode->parentNode->removeChild($domNode);
                        }
                    }
                } catch (\Exception $e) {
                    // Invalid selector, skip
                    Log::debug('Invalid remove selector', ['selector' => $selector, 'error' => $e->getMessage()]);
                }
            }

            $result = $wrapper->html();
        } catch (\Exception $e) {
            Log::warning('Failed to clean detail content', ['error' => $e->getMessage()]);

            $result = $html;
        }

        return $result;
    }

    /**
     * Remove text patterns from content string.
     *
     * Handles: case-insensitive matching, &nbsp; normalization,
     * whitespace collapsing, and HTML-interspersed text.
     */
    protected function removeTextPatterns(string $content, array $config): string
    {
        $removeTextPatterns = $config['remove_text_patterns'] ?? null;
        if (empty($removeTextPatterns)) {
            return $content;
        }

        if (is_string($removeTextPatterns)) {
            $patterns = array_filter(array_map('trim', explode("\n", $removeTextPatterns)));
        } else {
            $patterns = (array) $removeTextPatterns;
        }

        if (empty($patterns)) {
            return $content;
        }

        // Normalize non-breaking spaces → regular spaces
        $content = str_replace(['&nbsp;', "\xC2\xA0"], ' ', $content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ($patterns as $pattern) {
            if (empty($pattern)) {
                continue;
            }

            // 1) Case-insensitive direct match
            $content = str_ireplace($pattern, '', $content);

            // 2) Regex with flexible whitespace (handles multiple spaces, tabs, newlines)
            $normalizedPattern = preg_replace('/\s+/', ' ', trim($pattern));
            $regexPattern = preg_quote($normalizedPattern, '/');
            $regexPattern = str_replace('\\ ', '\\s+', $regexPattern);
            $content = preg_replace('/' . $regexPattern . '/ui', '', $content);

            // 3) Match against stripped-tags version (text interspersed with <br>, <span>, etc.)
            // Build regex that allows optional HTML tags between words
            $words = preg_split('/\s+/', trim($pattern));
            if (count($words) > 1) {
                $tagTolerantRegex = implode('(?:\s|<[^>]*>)*', array_map(function ($w) {
                    return preg_quote($w, '/');
                }, $words));
                $content = preg_replace('/' . $tagTolerantRegex . '/ui', '', $content);
            }
        }

        // Clean up empty elements and excessive whitespace left after removal
        $content = preg_replace('/<(p|div|span)>\s*<\/\1>/i', '', $content);
        $content = preg_replace('/(<br\s*\/?>){3,}/i', '<br><br>', $content);

        return $content;
    }

    /**
     * Extract chapter detail data using AI.
     */
    protected function extractDetailWithAi(string $html, array $config, ScrapeSource $source): array
    {
        $prompt = $config['ai_prompt'] ?? 'Extract the chapter content, title, chapter_number, and volume_number from this page. Return as JSON object with keys: content (HTML), title (string), chapter_number (number), volume_number (number or null).';

        $cleanedHtml = Drivers\HtmlCleaner::clean($html);

        // Truncate if too large for AI model (max ~10K tokens ≈ 30K chars)
        // This is the fallback path when no content_selector is set
        $maxChars = 30_000;
        if (mb_strlen($cleanedHtml) > $maxChars) {
            Log::warning('Detail HTML truncated for AI (no content_selector)', [
                'original_len' => mb_strlen($cleanedHtml),
                'truncated_to' => $maxChars,
            ]);
            $cleanedHtml = mb_substr($cleanedHtml, 0, $maxChars);
        }

        $systemPrompt = <<<PROMPT
        Bạn là AI trích xuất nội dung chương truyện từ HTML.
        Nhiệm vụ: trích xuất NỘI DUNG chương từ trang chi tiết.

        Fields cần trích xuất:
        - content: nội dung chương (giữ HTML format: <p>, <br>, <em>, <strong>)
        - title: tiêu đề chương
        - chapter_number: số chương (float)
        - volume_number: số quyển/tập (integer hoặc null)

        Quy tắc BẮT BUỘC:
        1. Trả về ĐÚNG 1 JSON object (KHÔNG phải array)
        2. content phải là nội dung chính, LOẠI BỎ: quảng cáo, điều hướng, sidebar, footer
        3. Giữ format HTML cơ bản cho content (p, br, em, strong)
        4. Nếu field không tìm thấy, set null
        PROMPT;

        $userPrompt = "Hướng dẫn thêm: {$prompt}\n\nHTML content:\n{$cleanedHtml}";

        $result = app(\App\Services\Ai\AiService::class)->callJson(
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            provider: $source->ai_provider,
            model: $source->ai_model,
            temperature: 0.1,
        );

        // Ensure we got an object, not array of objects
        if (array_is_list($result) && ! empty($result)) {
            $result = $result[0];
        }

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Persistence
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Save extracted items as draft ScrapeItems (dedup by source_hash).
     *
     * For NEW items: creates with status=draft.
     * For EXISTING items: merges new TOC data into raw_data while preserving
     * Phase 2 fields (content, _detail_error) and the current status.
     */
    protected function saveItems(ScrapeJob $job, array $items, int $pageNum, string $baseUrl): void
    {
        // Pre-resolve all URLs and hashes
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

        // Dedup by sourceHash within the same page — if the same URL appears
        // multiple times (e.g., story listed twice), keep only the last occurrence.
        // Without this, both items pass the DB existence check and the second
        // insert hits the unique constraint: SQLSTATE[23000] Duplicate entry.
        $resolved = collect($resolved)->keyBy('sourceHash')->values()->all();

        // Batch lookup: 1 query instead of N queries
        $allHashes = array_column($resolved, 'sourceHash');
        $existingItems = ScrapeItem::where('job_id', $job->id)
            ->whereIn('source_hash', $allHashes)
            ->get()
            ->keyBy('source_hash');

        $newRows = [];

        foreach ($resolved as $item) {
            $existing = $existingItems->get($item['sourceHash']);

            if ($existing) {
                // Merge new TOC data with existing data, preserving Phase 2 fields
                // (content, _detail_error) that TOC re-scrape doesn't produce.
                // Without this, re-scraping overwrites fetched content, causing
                // batch fetch to loop on the same items forever.
                $existingData = $existing->raw_data ?? [];
                $mergedData = $item['rawData'];

                // Preserve all Phase 2 metadata that TOC re-scrape doesn't produce.
                // Without this, re-scraping TOC overwrites fetched content and metrics,
                // causing batch fetch to loop on the same items forever.
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
                // Collect for bulk insert
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

        // Bulk insert new items (1 query instead of N)
        if (! empty($newRows)) {
            // Chunk to avoid exceeding MySQL max_allowed_packet (~16MB)
            foreach (array_chunk($newRows, 200) as $chunk) {
                ScrapeItem::insert($chunk);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // URL helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Resolve full URL for an item from its raw data.
     */
    protected function resolveItemUrl(array $rawData, string $baseUrl): string
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
    protected function resolveAbsoluteUrl(string $url, string $baseUrl): string
    {
        // Already absolute
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // Protocol-relative URL (e.g., //example.com/path)
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return "{$scheme}:{$url}";
        }

        // Relative URL
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Resolve page URLs from pagination config (for query_param type).
     */
    protected function resolvePages(string $baseUrl, ?array $pagination): array
    {
        if (! $pagination) {
            return [1 => $baseUrl];
        }

        $type = $pagination['type'] ?? 'single';

        if ($type === 'query_param') {
            $start = (int) ($pagination['start_page'] ?? 1);
            $end = (int) ($pagination['end_page'] ?? 1);
            $pattern = $pagination['url_pattern'] ?? $baseUrl;

            $pages = [];

            // Support reverse pagination: when start > end, iterate backwards
            // (e.g., start=10, end=1 → scrape page 10, 9, 8, ..., 1)
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

    /**
     * Resolve the driver based on source render_type.
     *
     * SSR → HttpDriver (cURL, fast, lightweight)
     * SPA → PlaywrightDriver (persistent browser, stealth, CF bypass)
     */
    protected function resolveDriver(ScrapeSource $source): DriverInterface
    {
        if ($source->isSpa()) {
            return new PlaywrightDriver();
        }

        return new HttpDriver();
    }

    /**
     * Fetch HTML with automatic Cloudflare fallback.
     *
     * If HttpDriver detects CF protection, automatically escalates
     * to PlaywrightDriver for browser-based bypass.
     * The $driver parameter is passed by reference — once CF is detected,
     * the driver is permanently switched to PlaywrightDriver for the
     * rest of the job (no more wasted cURL attempts on CF-protected sites).
     *
     * @throws CloudflareDetectedException  If CF cannot be bypassed (e.g., Turnstile)
     * @throws \RuntimeException            On connection or server errors
     */
    protected function fetchWithCfFallback(
        DriverInterface &$driver,
        string $url,
        array $headers = [],
    ): string {
        try {
            return $driver->fetchHtml($url, $headers);
        } catch (CloudflareDetectedException $e) {
            // Only escalate from HttpDriver → PlaywrightDriver
            if (! $driver instanceof HttpDriver) {
                throw $e;
            }

            Log::warning('HttpDriver hit Cloudflare, switching to PlaywrightDriver for remaining requests', [
                'url'     => $url,
                'cf_type' => $e->cfType,
            ]);

            // Switch driver permanently for this job — subsequent pages
            // will use PlaywrightDriver directly (no more cURL → CF → retry loop)
            $driver = new PlaywrightDriver();

            return $driver->fetchHtml($url, $headers);
        }
    }

    /**
     * Configure a pool PendingRequest with standard options.
     *
     * Centralizes: browser headers, timeout, retry, curl options,
     * SSL bypass and DNS resolution for local dev — so pool requests
     * behave identically to HttpDriver::fetchHtml().
     *
     * @return \Illuminate\Http\Client\PendingRequest  Ready for ->get()
     */
    protected function configurePoolRequest(
        \Illuminate\Http\Client\PendingRequest $pending,
        HttpDriver $driver,
        array $headers = [],
        int $retries = 2,
        ?string $url = null,
    ): \Illuminate\Http\Client\PendingRequest {
        $curlOptions = [
            CURLOPT_ENCODING       => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
        ];

        // Local dev: bypass SSL + resolve DNS via Google (avoid local hosts file issues)
        if (app()->environment('local')) {
            $pending = $pending->withoutVerifying();

            if ($url) {
                $host = parse_url($url, PHP_URL_HOST) ?: '';
                if ($host) {
                    // Cache DNS result per host to avoid N lookups in pool
                    if (! array_key_exists($host, $this->dnsCache)) {
                        $this->dnsCache[$host] = $driver->resolveHostViaGoogle($host);
                    }
                    $ip = $this->dnsCache[$host];
                    if ($ip) {
                        $curlOptions[CURLOPT_RESOLVE] = [
                            "{$host}:443:{$ip}",
                            "{$host}:80:{$ip}",
                        ];
                    }
                }
            }
        }

        return $pending
            ->withHeaders(array_merge($driver->getDefaultHeaders(), $headers))
            ->timeout(30)
            ->retry($retries, 2000, throw: false)
            ->withOptions(['curl' => $curlOptions]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TIER 2: Error Categorization & Tracking
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Track detail fetch error on an item with categorization and retry count.
     */
    protected function trackDetailError(ScrapeItem $item, \Throwable $e): void
    {
        $errorType = $this->categorizeError($e);
        $rawData = $item->raw_data ?? [];
        $retryCount = ($rawData['_retry_count'] ?? 0);

        // Increment retry count only for transient errors
        if ($errorType === 'transient') {
            $retryCount++;
        }

        $rawData['_detail_error'] = mb_substr($e->getMessage(), 0, 300);
        $rawData['_error_type'] = $errorType;
        $rawData['_retry_count'] = $retryCount;
        $rawData['_last_error_at'] = now()->toDateTimeString();

        $item->update(['raw_data' => $rawData]);

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
    protected function categorizeError(\Throwable $e): string
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // Permanent errors — DO NOT retry
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

        // Transient errors — CAN retry (timeout, 429, 5xx, connection issues)
        return 'transient';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TIER 3: Content Validation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Validate extracted detail content for quality issues.
     *
     * Detects empty content, suspiciously short pages, encoding errors,
     * and generates a content hash for duplicate detection.
     *
     * @return array  The detail data with _validation_issues and _content_hash added if applicable
     */
    protected function validateDetailContent(array $detailData): array
    {
        $issues = [];
        $content = $detailData['content'] ?? '';
        $textContent = trim(strip_tags($content));

        // 1. Empty content
        if (empty($textContent)) {
            $issues[] = 'empty_content';
        }

        // 2. Suspiciously short (< 100 chars of actual text)
        $textLength = mb_strlen($textContent);
        if ($textLength > 0 && $textLength < 100) {
            $issues[] = 'short_content';
        }

        // 3. Encoding issues (Unicode replacement char = mojibake)
        if (preg_match('/\x{FFFD}/u', $content)) {
            $issues[] = 'encoding_error';
        }

        // 4. Content hash for duplicate detection
        if (! empty($textContent)) {
            $detailData['_content_hash'] = md5($textContent);
        }

        if (! empty($issues)) {
            $detailData['_validation_issues'] = $issues;
            Log::info('Content validation issues detected', [
                'issues' => $issues,
                'text_length' => $textLength,
            ]);
        }

        return $detailData;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TIER 4: Adaptive Rate Limiting
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Adjust delay between batches based on server response.
     *
     * - Rate-limited (429) → double delay (max 30s)
     * - 10 consecutive successes → reduce delay by 10% (min = baseDelay)
     */
    protected function adaptDelay(int $baseDelay, bool $wasRateLimited): void
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
     *
     * Returns 0 if unlimited (-1).
     */
    protected function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === false) {
            return 0; // unlimited
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
}
