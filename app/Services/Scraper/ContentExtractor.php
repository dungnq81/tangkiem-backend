<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\Chapter;
use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use App\Services\Scraper\Drivers\AiExtractor;
use App\Services\Scraper\Drivers\HtmlCleaner;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Content extraction engine (Strategy Pattern).
 *
 * Handles all CSS and AI extraction logic, separated from the
 * orchestration concerns of ScraperService.
 *
 * Extraction modes:
 * - CSS: Fast, free, reliable for structured pages
 * - AI:  Flexible, handles unstructured pages, token cost
 * - Hybrid: CSS for content + AI for metadata (best of both)
 */
class ContentExtractor
{
    // ═══════════════════════════════════════════════════════════════════════
    // TOC Extraction (Phase 1)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Extract items from page HTML using the appropriate method.
     *
     * @return array<int, array<string, string|null>>
     */
    public function extractItems(string $html, ScrapeJob $job, ScrapeSource $source): array
    {
        if ($source->usesAi()) {
            return $this->extractWithAi($html, $job);
        }

        return $this->parseItems($html, $job->selectors, $job->entity_type);
    }

    /**
     * Extract items using AI provider.
     *
     * @return array<int, array<string, string|null>>
     */
    public function extractWithAi(string $html, ScrapeJob $job): array
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
     *
     * @return array<int, array<string, string|null>>
     */
    public function parseItems(string $html, array $selectors, string $entityType): array
    {
        $crawler = new Crawler($html);
        $container = $selectors['container'] ?? null;
        $fields = $selectors['fields'] ?? $selectors;

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
                    'container'   => $container,
                    'html_length' => strlen($html),
                ]);

                return [];
            }

            if ($matchCount === 1) {
                Log::info('CSS Scrape: container matched 1 element — may be targeting wrapper', [
                    'container' => $container,
                    'tag'       => $containerNodes->first()->nodeName(),
                    'children'  => $containerNodes->first()->children()->count(),
                ]);
            }

            $containerNodes->each(function ($node) use ($fields, &$items) {
                $item = [];

                foreach ($fields as $fieldName => $selector) {
                    $item[$fieldName] = $this->extractField($node, $selector);
                }

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
     *   "a[href]"         → auto-extracts href attribute
     *   ".cat-list a"     → text (multiple → comma-separated)
     */
    public function extractField(Crawler $node, string $selector): ?string
    {
        $attribute = null;
        if (str_contains($selector, '@')) {
            [$selector, $attribute] = explode('@', $selector, 2);
        }

        // Auto-detect CSS attribute selectors: "a[href]", "img[src]"
        if (! $attribute && preg_match('/\[(\w+)\]$/', $selector, $attrMatch)) {
            $detectedAttr = $attrMatch[1];
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

            // Multiple nodes → join with comma (e.g., category list)
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
    // Detail Extraction (Phase 2)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Extract detail data using the best available method.
     *
     * Strategy: CSS-first, AI as supplement/fallback.
     *
     * @return array<string, mixed>
     */
    public function extractDetail(string $html, array $config, ScrapeSource $source): array
    {
        if ($source->usesAi()) {
            return $this->extractDetailHybrid($html, $config, $source);
        }

        return $this->extractDetailWithCss($html, $config);
    }

    /**
     * Hybrid extraction: CSS for content, AI for metadata.
     *
     * @return array<string, mixed>
     */
    protected function extractDetailHybrid(string $html, array $config, ScrapeSource $source): array
    {
        $contentSelector = $config['content_selector'] ?? null;

        if (! $contentSelector) {
            return $this->extractDetailWithAi($html, $config, $source);
        }

        $cssData = $this->extractDetailWithCss($html, $config);

        if (! empty($cssData['content'])) {
            return $cssData;
        }

        Log::info('CSS content_selector found nothing, falling back to AI', [
            'selector' => $contentSelector,
        ]);

        return $this->extractDetailWithAi($html, $config, $source);
    }

    /**
     * Extract chapter detail data using CSS selectors.
     *
     * @return array{content: ?string, title: ?string, chapter_number: ?string, volume_number: ?int}
     */
    public function extractDetailWithCss(string $html, array $config): array
    {
        $crawler = new Crawler($html);
        $data = [];

        // 1) Content — required
        $contentSelector = $config['content_selector'] ?? null;
        if ($contentSelector) {
            $contentNode = $crawler->filter($contentSelector);
            if ($contentNode->count() > 0) {
                $rawContent = trim($contentNode->first()->html());

                if (! empty($rawContent) && ! preg_match('/<(p|div|br)\b/i', $rawContent)) {
                    $rawContent = nl2br($rawContent, false);
                }

                $data['content'] = $rawContent;
            }
        }

        // 2) Title — optional
        $titleSelector = $config['title_selector'] ?? null;
        if ($titleSelector) {
            try {
                $titleNode = $crawler->filter($titleSelector);
                if ($titleNode->count() > 0) {
                    $data['title'] = trim($titleNode->first()->text(''));
                }
            } catch (\Exception $e) {
                // Non-critical
            }
        }

        // 3) Chapter number — optional
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
     * Extract chapter detail data using AI.
     *
     * @return array<string, mixed>
     */
    protected function extractDetailWithAi(string $html, array $config, ScrapeSource $source): array
    {
        $prompt = $config['ai_prompt']
            ?? 'Extract the chapter content, title, chapter_number, and volume_number. Return as JSON.';

        $cleanedHtml = HtmlCleaner::clean($html);

        $maxChars = 30_000;
        if (mb_strlen($cleanedHtml) > $maxChars) {
            Log::warning('Detail HTML truncated for AI', [
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

        if (array_is_list($result) && ! empty($result)) {
            $result = $result[0];
        }

        return $result;
    }
}
