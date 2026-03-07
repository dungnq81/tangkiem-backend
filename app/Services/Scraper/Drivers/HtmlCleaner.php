<?php

declare(strict_types=1);

namespace App\Services\Scraper\Drivers;

/**
 * Clean HTML before sending to AI — strips non-essential elements
 * to reduce token count significantly (500KB → ~50KB).
 */
class HtmlCleaner
{
    /**
     * Clean HTML by removing scripts, styles, comments, and unnecessary attributes.
     * Keeps structural content: links, text, images, tables.
     */
    public static function clean(string $html): string
    {
        // Guard: for very large HTML (>1MB), extract <body> first to reduce work.
        // Many pages have huge <head> sections with inline CSS/JS that waste time.
        if (mb_strlen($html) > 1_000_000) {
            $bodyStart = stripos($html, '<body');
            $bodyEnd = stripos($html, '</body>');
            if ($bodyStart !== false && $bodyEnd !== false) {
                $html = substr($html, $bodyStart, $bodyEnd - $bodyStart + 7);
            }
        }

        // Increase PCRE backtrack limit for large HTML to prevent silent null returns
        $prevLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '5000000');

        // 1. Remove <script>, <style>, <noscript>, <svg>, <iframe> tags and their content
        $html = preg_replace(
            '/<(script|style|noscript|svg|iframe)\b[^>]*>.*?<\/\1>/si',
            '',
            $html
        ) ?? $html;

        // 2. Remove HTML comments
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        // 3. Remove common non-content elements by class/id patterns
        $html = preg_replace(
            '/<(nav|footer|header)\b[^>]*>.*?<\/\1>/si',
            '',
            $html
        ) ?? $html;

        // 4. Remove data-* attributes, inline styles, event handlers
        $html = preg_replace('/\s+(data-[\w-]+|style|onclick|onload|onmouseover|onerror)\s*=\s*"[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace("/\s+(data-[\w-]+|style|onclick|onload|onmouseover|onerror)\s*=\s*'[^']*'/i", '', $html) ?? $html;

        // 5. Remove class attributes (saves tokens, AI doesn't need them)
        $html = preg_replace('/\s+class\s*=\s*"[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace("/\s+class\s*=\s*'[^']*'/i", '', $html) ?? $html;

        // 6. Remove id attributes (usually not needed for extraction)
        $html = preg_replace('/\s+id\s*=\s*"[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace("/\s+id\s*=\s*'[^']*'/i", '', $html) ?? $html;

        // 7. Collapse whitespace
        $html = preg_replace('/\s+/', ' ', $html) ?? $html;

        // 8. Remove empty tags (except self-closing like <img>, <br>)
        $html = preg_replace('/<(div|span|p|ul|ol|li|section|article)\b[^>]*>\s*<\/\1>/i', '', $html) ?? $html;

        // Restore PCRE backtrack limit
        ini_set('pcre.backtrack_limit', $prevLimit ?: '1000000');

        return trim($html);
    }

    /**
     * Get a rough estimate of token count for the cleaned HTML.
     * Rule of thumb: ~4 characters per token for English, ~2-3 for CJK.
     */
    public static function estimateTokens(string $text): int
    {
        // Rough estimate: 1 token ≈ 3 characters (mixed content)
        return (int) ceil(mb_strlen($text) / 3);
    }
}
