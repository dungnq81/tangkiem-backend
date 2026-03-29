<?php

declare(strict_types=1);

namespace App\Services\Scraper\Concerns;

use App\Models\ScrapeItem;
use App\Services\Scraper\Drivers\CloudflareDetectedException;
use Illuminate\Support\Facades\Log;

/**
 * Error categorization and tracking for scrape items.
 *
 * Extracted from ScraperService to reduce file size.
 */
trait TracksErrors
{
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
}
