<?php

declare(strict_types=1);

namespace App\Services\Scraper\Processors;

use App\Services\Scraper\Contracts\ContentProcessorInterface;

/**
 * Normalize plain-text content to HTML by converting newlines to <br> tags.
 *
 * Sources like tangthuvien.vn serve chapter content as plain text with \n
 * instead of HTML block tags. Without conversion, line breaks are lost.
 *
 * Only converts when no block-level HTML tags are detected.
 */
class NormalizeLineBreaksProcessor implements ContentProcessorInterface
{
    public function process(string $content, array $config = []): string
    {
        if (empty($content)) {
            return $content;
        }

        // If content already has HTML block tags, leave it as-is
        if (preg_match('/<(p|div|br)\b/i', $content)) {
            return $content;
        }

        // Convert \n to <br> for plain-text content
        return nl2br($content, false);
    }
}
