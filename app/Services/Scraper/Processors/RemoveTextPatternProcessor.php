<?php

declare(strict_types=1);

namespace App\Services\Scraper\Processors;

use App\Services\Scraper\Contracts\ContentProcessorInterface;

/**
 * Remove specific text patterns from content.
 *
 * Handles: case-insensitive matching, &nbsp; normalization,
 * whitespace collapsing, and HTML-interspersed text.
 */
class RemoveTextPatternProcessor implements ContentProcessorInterface
{
    public function process(string $content, array $config = []): string
    {
        $removeTextPatterns = $config['remove_text_patterns'] ?? null;
        if (empty($removeTextPatterns)) {
            return $content;
        }

        $patterns = is_string($removeTextPatterns)
            ? array_filter(array_map('trim', explode("\n", $removeTextPatterns)))
            : (array) $removeTextPatterns;

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

            // 2) Flexible whitespace regex (handles spaces, tabs, newlines)
            $normalized = preg_replace('/\s+/', ' ', trim($pattern));
            $regex = preg_quote($normalized, '/');
            $regex = str_replace('\\ ', '\\s+', $regex);
            $content = preg_replace('/' . $regex . '/ui', '', $content);

            // 3) Tag-tolerant match (text interspersed with <br>, <span>, etc.)
            $words = preg_split('/\s+/', trim($pattern));
            if (count($words) > 1) {
                $tagTolerant = implode('(?:\s|<[^>]*>)*', array_map(
                    fn ($w) => preg_quote($w, '/'),
                    $words
                ));
                $content = preg_replace('/' . $tagTolerant . '/ui', '', $content);
            }
        }

        // Clean up empty elements and excessive line breaks
        $content = preg_replace('/<(p|div|span)>\s*<\/\1>/i', '', $content);
        $content = preg_replace('/(<br\s*\/?>){3,}/i', '<br><br>', $content);

        return $content;
    }
}
