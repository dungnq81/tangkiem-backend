<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use App\Services\Scraper\Concerns\ManagesRateLimit;
use App\Services\Scraper\Concerns\ResolvesUrls;
use App\Services\Scraper\Concerns\TracksErrors;
use App\Services\Scraper\Data\ScrapeMetrics;
use App\Services\Scraper\Drivers\CloudflareDetectedException;
use App\Services\Scraper\Drivers\DriverInterface;
use App\Services\Scraper\Drivers\HttpDriver;
use App\Services\Scraper\Drivers\PlaywrightDriver;
use App\Services\Scraper\Strategies\ChainCrawlStrategy;
use App\Services\Scraper\Strategies\TocScrapeStrategy;
use Illuminate\Support\Facades\Log;

/**
 * Scraper orchestrator — coordinates fetch, extract, and save phases.
 *
 * Delegates specialized work to:
 * - Concerns\ResolvesUrls:     URL resolution, pagination, navigation
 * - Concerns\TracksErrors:     Error categorization and retry tracking
 * - Concerns\ManagesRateLimit: Adaptive delay, DNS cache, memory mgmt
 * - ContentExtractor:          CSS/AI data extraction
 * - ContentPipeline:           Content cleaning, transformation, validation
 * - DetailFetcher:             Phase 2 batch detail page fetching
 */
class ScraperService
{
    use ManagesRateLimit;
    use ResolvesUrls;
    use TracksErrors;

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
     * Run a scrape job: delegates to the appropriate strategy.
     */
    public function execute(ScrapeJob $job): void
    {
        (new TocScrapeStrategy($this))->execute($job);
    }


    /**
     * Execute a chapter chain crawl: follow next/prev links.
     * Delegates to ChainCrawlStrategy.
     *
     * @param  bool  $isScheduledRun  Limits crawl to batch size for cron/scheduled runs.
     */
    public function executeChapterChain(ScrapeJob $job, bool $isScheduledRun = false): void
    {
        (new ChainCrawlStrategy($this))->execute($job, $isScheduledRun);
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
    // Accessors (for strategy classes)
    // ═══════════════════════════════════════════════════════════════════════

    public function getExtractor(): ContentExtractor
    {
        return $this->extractor;
    }

    public function getPipeline(): ContentPipeline
    {
        return $this->pipeline;
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
    public function saveItems(ScrapeJob $job, array $items, int $pageNum, string $baseUrl): void
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

    // URL helpers: resolveItemUrl(), resolveAbsoluteUrl(), resolvePages(), findNextPageUrl()
    // → Moved to Concerns\ResolvesUrls trait

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

    // Error tracking: trackDetailError(), categorizeError()
    // → Moved to Concerns\TracksErrors trait

    // Rate limiting: initRateLimiter(), getCurrentDelayMs(), getDnsCache(), adaptDelay(), getMemoryLimitBytes()
    // → Moved to Concerns\ManagesRateLimit trait

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
    public function saveMetrics(ScrapeJob $job): void
    {
        try {
            $job->update(['error_log' => json_encode($this->metrics->toArray())]);
        } catch (\Throwable $e) {
            Log::debug('Failed to save scrape metrics', ['error' => $e->getMessage()]);
        }
    }
}
