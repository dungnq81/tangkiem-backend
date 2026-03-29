<?php

declare(strict_types=1);

namespace App\Services\Scraper\Data;

/**
 * Mutable DTO for accumulating scrape job metrics.
 */
class ScrapeMetrics
{
    public int $totalFetchTimeMs = 0;
    public int $totalExtractTimeMs = 0;
    public int $pagesScraped = 0;
    public int $itemsExtracted = 0;
    public int $itemsFetched = 0;
    public int $itemsWithContent = 0;
    public int $errors = 0;
    public int $cfDetections = 0;
    public int $retryCount = 0;
    public float $startTime = 0;
    public float $endTime = 0;

    public function start(): void
    {
        $this->startTime = microtime(true);
    }

    public function stop(): void
    {
        $this->endTime = microtime(true);
    }

    public function totalDurationMs(): int
    {
        $end = $this->endTime > 0 ? $this->endTime : microtime(true);
        return (int) (($end - $this->startTime) * 1000);
    }

    public function successRate(): float
    {
        $total = $this->itemsFetched + $this->errors;
        return $total > 0 ? round($this->itemsFetched / $total * 100, 1) : 0;
    }

    public function avgFetchTimeMs(): int
    {
        return $this->itemsFetched > 0
            ? (int) ($this->totalFetchTimeMs / $this->itemsFetched)
            : 0;
    }

    public function merge(self $other): void
    {
        $this->totalFetchTimeMs += $other->totalFetchTimeMs;
        $this->totalExtractTimeMs += $other->totalExtractTimeMs;
        $this->pagesScraped += $other->pagesScraped;
        $this->itemsExtracted += $other->itemsExtracted;
        $this->itemsFetched += $other->itemsFetched;
        $this->itemsWithContent += $other->itemsWithContent;
        $this->errors += $other->errors;
        $this->cfDetections += $other->cfDetections;
        $this->retryCount += $other->retryCount;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total_duration_ms'     => $this->totalDurationMs(),
            'total_fetch_time_ms'   => $this->totalFetchTimeMs,
            'total_extract_time_ms' => $this->totalExtractTimeMs,
            'pages_scraped'         => $this->pagesScraped,
            'items_extracted'       => $this->itemsExtracted,
            'items_fetched'         => $this->itemsFetched,
            'items_with_content'    => $this->itemsWithContent,
            'errors'                => $this->errors,
            'cf_detections'         => $this->cfDetections,
            'retry_count'           => $this->retryCount,
            'success_rate'          => $this->successRate(),
            'avg_fetch_time_ms'     => $this->avgFetchTimeMs(),
        ];
    }
}
