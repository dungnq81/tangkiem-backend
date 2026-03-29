<?php

declare(strict_types=1);

namespace App\Services\Scraper\Strategies;

use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use App\Services\Scraper\Contracts\ScrapeStrategyInterface;
use App\Services\Scraper\Events\ScrapeJobCompleted;
use App\Services\Scraper\Events\ScrapeJobFailed;
use App\Services\Scraper\Events\ScrapeJobStarted;
use App\Services\Scraper\ScraperService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Chain crawl strategy — follow "next chapter" links sequentially.
 *
 * Flow: target_url → extract content → follow chain_selector → next → repeat
 * Each visited page = 1 ScrapeItem with content (no separate Phase 2 needed).
 *
 * Features:
 * - Per-chapter error handling: failed chapters save as error items, chain continues
 * - Gap preservation: error items keep their sort_order for correct positioning
 * - Resume support: saves last_crawled_url + chapter_num for interrupted chains
 * - Batch limiting: scheduled runs crawl N chapters/batch, resume next run
 * - Memory safeguard + GC for 10,000+ chapter crawls
 *
 * Limitation: If fetch fails → no HTML → no next URL → chain MUST stop.
 * This is inherent to chain crawling (no URL pattern to infer next page).
 */
class ChainCrawlStrategy implements ScrapeStrategyInterface
{
    public function __construct(
        protected ScraperService $service,
    ) {}

    public function execute(ScrapeJob $job, bool $isScheduledRun = false): void
    {
        $source = $job->source;
        $driver = $this->service->resolveDriver($source);
        $config = $job->resolveDetailConfig();
        $chainSelector = $config['chain_selector'] ?? '';
        $maxChapters = (int) ($config['chain_max_chapters'] ?? 5000);
        $isSingleMode = (bool) ($config['single_chapter_mode'] ?? false);

        if (empty($chainSelector) && ! $isSingleMode) {
            $job->markFailed('Chain selector chưa cấu hình. Nhập CSS selector nút "Chương tiếp" hoặc bật chế độ "Chỉ cào 1 chương".');

            return;
        }

        $job->markScraping();
        $this->service->getMetrics()->start();
        event(new ScrapeJobStarted($job, 'chapter_chain'));

        // Batch intervals for expensive operations
        $progressInterval = 10;   // Update current_page + check cancellation every N
        $gcInterval = 50;          // GC + memory check every N
        $logInterval = 100;        // Progress log every N

        // Batch limiting: single mode = 1; scheduled = chain_batch_size; manual = all
        if ($isSingleMode) {
            $batchSize = 1;
        } elseif ($isScheduledRun) {
            $batchSize = (int) ($config['chain_batch_size'] ?? 50);
        } else {
            $batchSize = $maxChapters;
        }
        $effectiveLimit = min($batchSize, $maxChapters);

        $consecutiveErrors = 0;

        // Error counters for reporting
        $totalErrors = 0;

        $chapterNum = 0;
        $currentUrl = '';

        // Previous total for cumulative tracking across batch runs
        $previousTotal = (int) ($job->total_pages ?? 0);

        try {
            // Resume support: start from last_crawled_url if available
            $resumeUrl = $config['last_crawled_url'] ?? null;
            $defaults = $job->import_defaults ?? [];
            $startChapter = (int) ($defaults['chapter_number'] ?? 1);

            if ($resumeUrl) {
                // Resume: start from saved position
                $currentUrl = $resumeUrl;
                $chapterNum = (int) ($config['chain_last_chapter_num'] ?? ($startChapter - 1));

                Log::info('Chapter chain: resuming from saved position', [
                    'job_id'      => $job->id,
                    'resume_url'  => $resumeUrl,
                    'chapter_num' => $chapterNum,
                ]);

                // The resume URL is the NEXT url to crawl (saved after finding next link),
                // so we need to fetch it first on this iteration.
            } else {
                // Fresh start — reset cumulative counter
                $previousTotal = 0;
                $currentUrl = $job->target_url;
                $chapterNum = $startChapter - 1; // Will be incremented on first iteration
            }

            $crawled = 0;
            $visited = [];
            $breakReason = null; // Why loop stopped: 'circular'|'cancel'|'memory'|'fetch_fail'|null

            while ($currentUrl && $crawled < $effectiveLimit) {
                // Deduplicate: stop if we've already visited this URL (circular link)
                $urlHash = md5($currentUrl);
                if (isset($visited[$urlHash])) {
                    Log::info('Chapter chain: circular link detected, stopping', [
                        'job_id' => $job->id,
                        'url'    => $currentUrl,
                    ]);
                    $breakReason = 'circular';
                    break;
                }
                $visited[$urlHash] = true;

                $chapterNum++;
                $crawled++;

                // Batched: progress update + cancellation check + save resume state
                if ($crawled % $progressInterval === 0) {
                    $this->saveProgress($job, $config, $chapterNum, $currentUrl);

                    if ($this->service->isCancelled($job)) {
                        Log::info('Chapter chain: cancelled by user', [
                            'job_id'   => $job->id,
                            'chapters' => $chapterNum,
                        ]);
                        $breakReason = 'cancel';
                        break;
                    }
                }

                // Batched: GC + memory safeguard
                if ($crawled % $gcInterval === 0) {
                    gc_collect_cycles();

                    $memoryUsage = memory_get_usage(true);
                    $memoryLimit = $this->service->getMemoryLimitBytes();
                    if ($memoryLimit > 0 && $memoryUsage > $memoryLimit * 0.80) {
                        Log::warning('Chapter chain: memory limit approaching, stopping', [
                            'job_id'       => $job->id,
                            'chapters'     => $chapterNum,
                            'memory_usage' => round($memoryUsage / 1048576, 1) . 'MB',
                            'memory_limit' => round($memoryLimit / 1048576, 1) . 'MB',
                        ]);
                        $breakReason = 'memory';
                        break;
                    }
                }

                // Batched: progress logging
                if ($crawled % $logInterval === 0) {
                    Log::info('Chapter chain: progress', [
                        'job_id'   => $job->id,
                        'chapters' => $chapterNum,
                        'errors'   => $totalErrors,
                        'memory'   => round(memory_get_usage(true) / 1048576, 1) . 'MB',
                    ]);
                }

                // === Per-chapter try/catch ===
                $html = null;
                $nextUrl = null;

                try {
                    // Fetch page HTML
                    $html = $this->service->fetchWithCfFallback($driver, $currentUrl, $source->default_headers ?? []);

                    // Extract content using same pipeline as chapter_detail
                    $cleanedHtml = $this->service->getPipeline()->cleanPageHtml($html, $config);
                    $detailData = $this->service->getExtractor()->extractDetail($cleanedHtml, $config, $source);

                    // Fallback to body if extraction yields nothing
                    if (empty($detailData['content'])) {
                        $crawler = new Crawler($html);
                        $body = $crawler->filter('body');
                        if ($body->count() > 0) {
                            $detailData['content'] = trim($body->html());
                        }
                    }

                    // Process content through pipeline
                    if (! empty($detailData['content'])) {
                        $processed = $this->service->getPipeline()->process($detailData['content'], $config);
                        $detailData['content'] = $processed->content;
                        $detailData['_content_hash'] = $processed->contentHash;
                        $detailData['_validation_issues'] = $processed->validationIssues;
                    }

                    // Build raw_data — use extracted chapter_number or fallback to counter
                    $rawData = array_merge([
                        'title'           => $detailData['title'] ?? null,
                        'content'         => $detailData['content'] ?? null,
                        'chapter_number'  => $detailData['chapter_number'] ?? $chapterNum,
                        'volume_number'   => $detailData['volume_number'] ?? 1,
                        'url'             => $currentUrl,
                        '_chain_position' => $chapterNum, // Always save counter as backup
                    ], $detailData);

                    // Upsert ScrapeItem by source_hash
                    $sourceHash = ScrapeItem::hashUrl($currentUrl);
                    $hasContent = ! empty($rawData['content']);
                    ScrapeItem::updateOrCreate(
                        ['job_id' => $job->id, 'source_hash' => $sourceHash],
                        [
                            'raw_data'      => $rawData,
                            'source_url'    => $currentUrl,
                            'status'        => ScrapeItem::STATUS_DRAFT,
                            'has_content'   => $hasContent,
                            'page_number'   => 1,
                            'sort_order'    => $chapterNum,
                            'error_message' => null,
                        ]
                    );

                    $this->service->getMetrics()->pagesScraped++;
                    $this->service->getMetrics()->itemsExtracted++;
                    $consecutiveErrors = 0; // Reset on success

                    // Find next chapter URL (uses ORIGINAL html, not cleaned)
                    $nextUrl = $this->service->findNextPageUrl($html, $chainSelector, $source->base_url);

                    // Free large strings early
                    unset($cleanedHtml, $detailData, $rawData);
                } catch (\Throwable $e) {
                    $consecutiveErrors++;
                    $totalErrors++;

                    // Save failed item WITH gap preserved (sort_order = chapterNum)
                    $this->saveFailedItem($job, $currentUrl, $chapterNum, $e->getMessage());

                    Log::warning('Chapter chain: single chapter failed', [
                        'job_id'      => $job->id,
                        'chapter_num' => $chapterNum,
                        'url'         => $currentUrl,
                        'error'       => mb_substr($e->getMessage(), 0, 200),
                        'consecutive' => $consecutiveErrors,
                    ]);

                    // Fetch failed → no HTML → cannot find next URL → must break.
                    // Save resume state pointing to the FAILED URL so cron retries it later.
                    // Critical for ongoing stories: chapter doesn't exist today, may exist tomorrow.
                    if ($html === null) {
                        Log::info('Chapter chain: fetch failed, saving resume state for retry', [
                            'job_id'      => $job->id,
                            'failed_url'  => $currentUrl,
                            'chapter_num' => $chapterNum,
                        ]);
                        $breakReason = 'fetch_fail';
                        break;
                    }

                    // Circuit breaker: 5+ consecutive extraction errors → source likely changed/blocking
                    if ($consecutiveErrors >= 5) {
                        Log::warning('Chapter chain: 5 consecutive errors, pausing', [
                            'job_id'  => $job->id,
                            'chapter' => $chapterNum,
                        ]);
                        $breakReason = 'consecutive_errors';
                        break;
                    }

                    // Extraction/processing failed but we DO have HTML → try to find next URL
                    $nextUrl = $this->service->findNextPageUrl($html, $chainSelector, $source->base_url);
                }

                // Free HTML after next-URL extraction
                unset($html);

                // Advance to next URL
                $currentUrl = $nextUrl;

                // Rate limiting
                if ($currentUrl && $source->delay_ms > 0) {
                    usleep($source->delay_ms * 1000);
                }
            }

            // Post-loop: determine outcome based on WHY loop stopped
            // Cancel/memory/fetch_fail = interrupted → save resume state for retry
            // Circular/natural end = completed → clear resume state
            $shouldKeepResumeState = in_array($breakReason, ['cancel', 'memory', 'fetch_fail', 'consecutive_errors'])
                || ($currentUrl !== null && $crawled >= $effectiveLimit); // batch limit reached

            if ($shouldKeepResumeState) {
                // Any break reason means the CURRENT chapter was not fully processed,
                // so resume from chapterNum - 1 to retry it. Batch limit (no break) means
                // all chapters up to chapterNum were processed → keep as-is.
                $resumeChapterNum = ($breakReason !== null)
                    ? $chapterNum - 1
                    : $chapterNum;

                // Single combined UPDATE: stats + resume state
                $job->update([
                    'total_pages'   => $previousTotal + $crawled,
                    'current_page'  => $chapterNum,
                    'detail_config' => array_merge($config, [
                        'last_crawled_url'       => $currentUrl,
                        'chain_last_chapter_num' => $resumeChapterNum,
                    ]),
                ]);
                $job->markScraped();

                Log::info('Chapter chain: paused, will resume next run', [
                    'job_id'       => $job->id,
                    'crawled'      => $crawled,
                    'reason'       => $breakReason ?? 'batch_limit',
                    'resume_url'   => $currentUrl,
                    'chapter_num'  => $resumeChapterNum,
                    'errors'       => $totalErrors,
                ]);
            } else {
                // Chain truly finished: circular link, natural end (no next URL), or max reached
                $cleanConfig = $config;
                unset($cleanConfig['last_crawled_url'], $cleanConfig['chain_last_chapter_num']);

                // Single combined UPDATE: stats + clear resume
                $job->update([
                    'total_pages'   => $previousTotal + $crawled,
                    'current_page'  => $chapterNum,
                    'detail_config' => $cleanConfig,
                ]);
                $job->markScraped();

                Log::info('Chapter chain crawl completed', [
                    'job_id'        => $job->id,
                    'crawled'       => $crawled,
                    'chapter_range' => $startChapter . '–' . $chapterNum,
                    'max_chapters'  => $maxChapters,
                    'errors'        => $totalErrors,
                    'memory_peak'   => round(memory_get_peak_usage(true) / 1048576, 1) . 'MB',
                ]);
            }

            $this->service->getMetrics()->stop();
            $this->service->saveMetrics($job);
            event(new ScrapeJobCompleted($job, $this->service->getMetrics()));
        } catch (\Throwable $e) {
            // Outer catch: only for truly fatal errors (DB connection lost, etc.)
            // Save resume state so user can retry from where we left off
            $this->saveProgress($job, $config ?? [], $chapterNum, $currentUrl);

            Log::error('Chapter chain crawl failed (fatal)', [
                'job_id'  => $job->id,
                'chapter' => $chapterNum,
                'error'   => $e->getMessage(),
            ]);
            $job->markFailed($e->getMessage());

            $this->service->getMetrics()->stop();
            $this->service->saveMetrics($job);
            event(new ScrapeJobFailed($job, $e->getMessage(), $e));
        }
    }

    /**
     * Save a failed chapter as a ScrapeItem with error state.
     * Preserves the gap (sort_order = chapterNum) so retry can fill it later.
     */
    protected function saveFailedItem(ScrapeJob $job, string $url, int $chapterNum, string $error): void
    {
        $sourceHash = ScrapeItem::hashUrl($url);
        ScrapeItem::updateOrCreate(
            ['job_id' => $job->id, 'source_hash' => $sourceHash],
            [
                'raw_data' => [
                    'url'            => $url,
                    'chapter_number' => $chapterNum,
                    '_chain_position' => $chapterNum,
                    '_detail_error'  => mb_substr($error, 0, 300),
                    '_error_type'    => 'fetch_failed',
                ],
                'source_url'    => $url,
                'status'        => ScrapeItem::STATUS_DRAFT,
                'has_content'   => false,
                'page_number'   => 1,
                'sort_order'    => $chapterNum,
                'error_message' => mb_substr($error, 0, 500),
            ]
        );
    }

    /**
     * Save resume state into detail_config for later continuation.
     * Uses array_merge to preserve other config keys.
     */
    protected function saveProgress(ScrapeJob $job, array $config, int $chapterNum, string $nextUrl): void
    {
        $job->update([
            'current_page'  => $chapterNum,
            'detail_config' => array_merge($config, [
                'last_crawled_url'       => $nextUrl,
                'chain_last_chapter_num' => $chapterNum,
            ]),
        ]);
    }
}

