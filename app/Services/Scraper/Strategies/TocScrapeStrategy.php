<?php

declare(strict_types=1);

namespace App\Services\Scraper\Strategies;

use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use App\Services\Scraper\Contracts\ScrapeStrategyInterface;
use App\Services\Scraper\Drivers\DriverInterface;
use App\Services\Scraper\Drivers\HttpDriver;
use App\Services\Scraper\Events\ScrapeJobCompleted;
use App\Services\Scraper\Events\ScrapeJobFailed;
use App\Services\Scraper\Events\ScrapeJobStarted;
use App\Services\Scraper\ScraperService;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TOC (Table of Contents) scraping strategy.
 *
 * Handles listing pages with pagination:
 * - Single page (no pagination)
 * - Query param pagination (page=1, page=2, ...)
 * - Next-link pagination (follow CSS selector)
 * - Auto-detect end page
 */
class TocScrapeStrategy implements ScrapeStrategyInterface
{
    public function __construct(
        protected ScraperService $service,
    ) {}

    public function execute(ScrapeJob $job): void
    {
        $source = $job->source;
        $driver = $this->service->resolveDriver($source);

        $job->markScraping();
        $this->service->getMetrics()->start();
        event(new ScrapeJobStarted($job, 'toc'));

        try {
            $pagination = $job->pagination;
            $type = $pagination['type'] ?? 'single';

            if ($type === 'next_link') {
                $totalPages = $this->scrapeWithNextLink($job, $driver, $source);
            } elseif ($type === 'query_param' && (! isset($pagination['end_page']) || $pagination['end_page'] === '' || $pagination['end_page'] === null)) {
                $totalPages = $this->scrapePagesAuto($job, $driver, $source);
            } else {
                $pages = $this->service->resolvePages($job->target_url, $pagination);
                $totalPages = $this->scrapePages($job, $driver, $source, $pages);
            }

            $job->update(['total_pages' => $totalPages]);
            $job->markScraped();

            $this->service->getMetrics()->stop();
            $this->service->saveMetrics($job);
            event(new ScrapeJobCompleted($job, $this->service->getMetrics()));
        } catch (\Throwable $e) {
            Log::error('Scrape failed', [
                'job_id' => $job->id,
                'error'  => $e->getMessage(),
            ]);
            $job->markFailed($e->getMessage());

            $this->service->getMetrics()->stop();
            $this->service->saveMetrics($job);
            event(new ScrapeJobFailed($job, $e->getMessage(), $e));
        }
    }

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
            $pageCount = 0;
            foreach ($pages as $pageNum => $pageUrl) {
                $pageCount++;

                // Batched: progress update + cancellation every 5 pages
                if ($pageCount % 5 === 0) {
                    $job->update(['current_page' => $pageNum]);
                    if ($this->service->isCancelled($job)) {
                        return $pageNum > 0 ? $pageNum - 1 : 0;
                    }
                }

                $html = $this->service->fetchWithCfFallback($driver, $pageUrl, $headers);
                $items = $this->service->getExtractor()->extractItems($html, $job, $source);
                $this->service->saveItems($job, $items, $pageNum, $source->base_url);

                $this->service->getMetrics()->pagesScraped++;
                $this->service->getMetrics()->itemsExtracted += count($items);

                if ($source->delay_ms > 0 && $pageNum < $totalPages) {
                    usleep($source->delay_ms * 1000);
                }
            }

            // Final progress update
            $job->update(['current_page' => array_key_last($pages)]);

            return $totalPages;
        }

        // Concurrent TOC fetching for multi-page query_param
        $pagesCollection = collect($pages);
        $dnsCache = &$this->service->getDnsCache();

        foreach ($pagesCollection->chunk($concurrency) as $batch) {
            if ($this->service->isCancelled($job)) {
                return $batch->keys()->first();
            }

            $htmlResults = [];
            if ($driver instanceof HttpDriver) {
                $responses = Http::pool(function (Pool $pool) use ($batch, $driver, $headers, &$dnsCache) {
                    foreach ($batch as $pageNum => $pageUrl) {
                        $driver->createPoolRequest(
                            $pool->as((string) $pageNum), $headers, retries: 3,
                            url: $pageUrl, dnsCache: $dnsCache
                        )->get($pageUrl);
                    }
                });

                foreach ($batch as $pageNum => $pageUrl) {
                    $response = $responses[(string) $pageNum] ?? null;
                    if ($response instanceof Response && $response->successful()) {
                        $htmlResults[$pageNum] = $response->body();
                    } else {
                        try {
                            $htmlResults[$pageNum] = $this->service->fetchWithCfFallback($driver, $pageUrl, $headers);
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
                        $htmlResults[$pageNum] = $this->service->fetchWithCfFallback($driver, $pageUrl, $headers);
                    } catch (\Throwable $e) {
                        Log::warning('TOC page fetch failed', [
                            'page' => $pageNum, 'url' => $pageUrl, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Update progress once per batch (last page in batch)
            $lastPageNum = $htmlResults ? array_key_last($htmlResults) : null;
            foreach ($htmlResults as $pageNum => $html) {
                $items = $this->service->getExtractor()->extractItems($html, $job, $source);
                $this->service->saveItems($job, $items, $pageNum, $source->base_url);

                $this->service->getMetrics()->pagesScraped++;
                $this->service->getMetrics()->itemsExtracted += count($items);
            }

            if ($lastPageNum !== null) {
                $job->update(['current_page' => $lastPageNum]);
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
            $pageNum++;

            // Batched: progress update + cancellation every 5 pages
            if ($pageNum % 5 === 0) {
                $job->update(['current_page' => $pageNum]);
                if ($this->service->isCancelled($job)) {
                    return $pageNum;
                }
            }

            $html = $this->service->fetchWithCfFallback($driver, $currentUrl, $source->default_headers ?? []);
            $items = $this->service->getExtractor()->extractItems($html, $job, $source);
            $this->service->saveItems($job, $items, $pageNum, $source->base_url);

            $this->service->getMetrics()->pagesScraped++;
            $this->service->getMetrics()->itemsExtracted += count($items);

            $currentUrl = $this->service->findNextPageUrl($html, $nextSelector, $source->base_url);

            if ($currentUrl && $source->delay_ms > 0) {
                usleep($source->delay_ms * 1000);
            }
        }

        // Final progress update
        $job->update(['current_page' => $pageNum]);

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
        $firstPageIsBaseUrl = (bool) ($pagination['first_page_is_base_url'] ?? false);
        $headers = $source->default_headers ?? [];

        $pageNum = $start;
        $pagesScraped = 0;
        $hasFoundData = false;
        $consecutiveEmpty = 0;

        while ($pagesScraped < $maxPages) {
            // Batched: progress update + cancellation every 5 pages
            if ($pagesScraped > 0 && $pagesScraped % 5 === 0) {
                $job->update(['current_page' => $pageNum]);
                if ($this->service->isCancelled($job)) {
                    return $pagesScraped;
                }
            }

            $pageUrl = ($firstPageIsBaseUrl && $pageNum === $start)
                ? $job->target_url
                : str_replace('{page}', (string) $pageNum, $pattern);

            try {
                $html = $this->service->fetchWithCfFallback($driver, $pageUrl, $headers);
                $items = $this->service->getExtractor()->extractItems($html, $job, $source);
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
            $this->service->saveItems($job, $items, $pageNum, $source->base_url);
            $pagesScraped++;

            $this->service->getMetrics()->pagesScraped++;
            $this->service->getMetrics()->itemsExtracted += count($items);

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
}
