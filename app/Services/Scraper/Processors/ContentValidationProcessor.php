<?php

declare(strict_types=1);

namespace App\Services\Scraper\Processors;

use App\Services\Scraper\Contracts\ContentProcessorInterface;
use Illuminate\Support\Facades\Log;

/**
 * Validate extracted content for quality issues.
 *
 * Detects: empty content, suspiciously short pages, encoding errors.
 * Appends validation metadata as HTML comment at the end (non-destructive).
 *
 * Note: This processor does NOT modify the content string itself.
 * Validation results are tracked separately via ContentPipeline.
 */
class ContentValidationProcessor implements ContentProcessorInterface
{
    /** @var array<string> Detected issues from last run */
    private array $issues = [];

    private ?string $contentHash = null;

    public function process(string $content, array $config = []): string
    {
        $this->issues = [];
        $this->contentHash = null;

        $textContent = trim(strip_tags($content));

        // 1. Empty content
        if (empty($textContent)) {
            $this->issues[] = 'empty_content';
        }

        // 2. Suspiciously short (< 100 chars of actual text)
        $textLength = mb_strlen($textContent);
        if ($textLength > 0 && $textLength < 100) {
            $this->issues[] = 'short_content';
        }

        // 3. Encoding issues (Unicode replacement char = mojibake)
        if (preg_match('/\x{FFFD}/u', $content)) {
            $this->issues[] = 'encoding_error';
        }

        // 4. Content hash for duplicate detection
        if (! empty($textContent)) {
            $this->contentHash = md5($textContent);
        }

        if (! empty($this->issues)) {
            Log::info('Content validation issues detected', [
                'issues'      => $this->issues,
                'text_length' => $textLength,
            ]);
        }

        // Return content unchanged — validation is non-destructive
        return $content;
    }

    /** @return array<string> */
    public function getIssues(): array
    {
        return $this->issues;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function hasCriticalIssues(): bool
    {
        return ! empty(array_intersect($this->issues, ['empty_content', 'encoding_error']));
    }
}
