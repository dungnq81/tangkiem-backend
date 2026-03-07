<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\ScrapeSource;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered chapter content cleaner.
 *
 * 3-step pipeline:
 * 1. Custom Patterns (global + per-source) → FREE
 * 2. Built-in Regex (zero-width, whitespace)  → FREE
 * 3. AI Clean (optional, costs tokens)        → PAID
 */
class AiContentCleaner
{
    public function __construct(
        protected AiService $aiService,
    ) {}

    /**
     * Full clean pipeline.
     *
     * @param  string  $content         Raw chapter content (HTML or plain text)
     * @param  int|null $scrapeSourceId  Source ID for per-source patterns (optional)
     * @param  bool    $useAi           Whether to use AI cleaning (Step 3)
     * @return string  Cleaned content
     */
    public function clean(string $content, ?int $scrapeSourceId = null, bool $useAi = false): string
    {
        return $this->cleanWithReport($content, $scrapeSourceId, $useAi)['content'];
    }

    /**
     * Full clean pipeline with detailed report of what was removed.
     *
     * @return array{content: string, removals: array<string>, charDiff: int}
     */
    public function cleanWithReport(string $content, ?int $scrapeSourceId = null, bool $useAi = false): array
    {
        $originalLength = mb_strlen($content);
        $removals = [];

        // Step 1: Custom patterns (FREE)
        [$content, $patternRemovals] = $this->applyCustomPatternsTracked($content, $scrapeSourceId);
        $removals = array_merge($removals, $patternRemovals);

        // Step 2: Built-in regex (FREE)
        [$content, $regexRemovals] = $this->quickCleanTracked($content);
        $removals = array_merge($removals, $regexRemovals);

        // Step 3: AI clean (PAID — optional)
        if ($useAi && AiService::isEnabled('content_clean')) {
            $beforeAi = mb_strlen($content);
            $content = $this->aiClean($content);
            $aiDiff = $beforeAi - mb_strlen($content);
            if ($aiDiff > 0) {
                $removals[] = "🤖 AI dọn dẹp: -{$aiDiff} ký tự";
            }
        }

        $charDiff = $originalLength - mb_strlen($content);

        Log::debug('Content cleaner finished', [
            'original_length'  => $originalLength,
            'cleaned_length'   => mb_strlen($content),
            'reduction'        => $charDiff,
            'removals'         => count($removals),
            'used_ai'          => $useAi,
            'scrape_source_id' => $scrapeSourceId,
        ]);

        return [
            'content'  => $content,
            'removals' => $removals,
            'charDiff' => $charDiff,
        ];
    }

    /**
     * Step 1: Apply custom patterns with tracking of what was removed.
     *
     * @return array{0: string, 1: array<string>}
     */
    protected function applyCustomPatternsTracked(string $content, ?int $scrapeSourceId): array
    {
        $removals = [];

        // Load global patterns
        $globalPatterns = Setting::get('ai.clean_patterns', []);
        if (! is_array($globalPatterns)) {
            $globalPatterns = [];
        }

        // Load source-specific patterns
        $sourcePatterns = [];
        if ($scrapeSourceId) {
            $source = ScrapeSource::find($scrapeSourceId);
            $sourcePatterns = $source?->clean_patterns ?? [];
            if (! is_array($sourcePatterns)) {
                $sourcePatterns = [];
            }
        }

        // Merge and apply
        $allPatterns = array_merge($globalPatterns, $sourcePatterns);

        foreach ($allPatterns as $item) {
            $pattern = $item['pattern'] ?? '';
            $type = $item['type'] ?? 'text';

            if (empty($pattern)) {
                continue;
            }

            $before = $content;

            if ($type === 'regex') {
                $cleaned = @preg_replace("/{$pattern}/usi", '', $content);
                if ($cleaned !== null) {
                    $content = $cleaned;
                } else {
                    Log::warning('Invalid clean pattern regex', ['pattern' => $pattern]);
                }
            } else {
                $content = str_ireplace($pattern, '', $content);
            }

            if ($before !== $content) {
                $diff = mb_strlen($before) - mb_strlen($content);
                $label = mb_strlen($pattern) > 30 ? mb_substr($pattern, 0, 30) . '…' : $pattern;
                $removals[] = "Pattern \"{$label}\": -{$diff} ký tự";
            }
        }

        return [$content, $removals];
    }

    /**
     * Step 2: Built-in regex with tracking.
     *
     * @return array{0: string, 1: array<string>}
     */
    protected function quickCleanTracked(string $content): array
    {
        $removals = [];

        // Remove zero-width characters
        $before = $content;
        $content = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}]/u', '', $content) ?? $content;
        if ($before !== $content) {
            $diff = mb_strlen($before) - mb_strlen($content);
            $removals[] = "Ký tự ẩn (zero-width): -{$diff}";
        }

        // Remove common Vietnamese watermark patterns
        $watermarkPatterns = [
            'Link đọc truyện'  => '/\(?\s*(?:đọc\s+)?(?:truyện\s+)?(?:tại|ở)\s*:?\s*https?:\/\/\S+\s*\)?/ui',
            'Link nguồn'       => '/(?:nguồn|source)\s*:?\s*https?:\/\/\S+/ui',
            'Text copy/sưu tầm' => '/(?:copy|sưu tầm|lấy)\s+(?:từ|tại)\s+\S+\.\S+/ui',
            'Watermark 【...】' => '/【[^】]*(?:copy|nguồn|source|sưu tầm)[^】]*】/ui',
        ];

        foreach ($watermarkPatterns as $label => $pattern) {
            $before = $content;
            $content = preg_replace($pattern, '', $content) ?? $content;
            if ($before !== $content) {
                // Extract what was matched for display
                preg_match_all($pattern, $before, $matches);
                $found = array_unique(array_map('trim', $matches[0]));
                $samples = implode(', ', array_slice($found, 0, 3));
                $removals[] = "{$label}: \"{$samples}\"";
            }
        }

        // Collapse multiple blank lines (> 3 consecutive → 2)
        $before = $content;
        $content = preg_replace('/(\r?\n\s*){4,}/', "\n\n\n", $content) ?? $content;
        if ($before !== $content) {
            $diff = mb_strlen($before) - mb_strlen($content);
            $removals[] = "Dòng trống thừa: -{$diff} ký tự";
        }

        // Remove trailing whitespace per line
        $before = $content;
        $content = preg_replace('/[^\S\n]+$/m', '', $content) ?? $content;
        if ($before !== $content) {
            $diff = mb_strlen($before) - mb_strlen($content);
            $removals[] = "Khoảng trắng cuối dòng: -{$diff} ký tự";
        }

        // Remove leading/trailing whitespace from entire content
        $before = $content;
        $content = trim($content);
        if ($before !== $content) {
            $diff = mb_strlen($before) - mb_strlen($content);
            $removals[] = "Khoảng trắng đầu/cuối: -{$diff} ký tự";
        }

        return [$content, $removals];
    }

    /**
     * Step 3: AI-powered content cleaning (costs tokens).
     *
     * Handles complex cases that regex can't:
     * - Context-aware ad removal
     * - Fixing broken sentences
     * - Removing embedded promotional text within paragraphs
     */
    protected function aiClean(string $content): string
    {
        // Don't send too much to AI — chunk if needed
        $maxChunkSize = 6000;

        if (mb_strlen($content) <= $maxChunkSize) {
            return $this->aiCleanChunk($content);
        }

        // Split into chunks by paragraphs
        $paragraphs = preg_split('/\n{2,}/', $content);
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            if (mb_strlen($current) + mb_strlen($paragraph) > $maxChunkSize && ! empty($current)) {
                $chunks[] = $current;
                $current = $paragraph;
            } else {
                $current .= (empty($current) ? '' : "\n\n") . $paragraph;
            }
        }

        if (! empty($current)) {
            $chunks[] = $current;
        }

        // Clean each chunk
        $cleanedChunks = array_map(fn ($chunk) => $this->aiCleanChunk($chunk), $chunks);

        return implode("\n\n", $cleanedChunks);
    }

    /**
     * Clean a single chunk of content using AI.
     */
    protected function aiCleanChunk(string $content): string
    {
        $systemPrompt = <<<'PROMPT'
Bạn là AI dọn dẹp nội dung chương truyện. Nhiệm vụ:

1. Loại bỏ: quảng cáo, link spam, watermark, text khuyến mãi nhúng trong nội dung
2. Sửa: câu bị ngắt sai, dấu câu lỗi, khoảng trắng thừa
3. GIỮ NGUYÊN: nội dung truyện gốc, style viết, format paragraph
4. KHÔNG thêm, sửa, hoặc viết lại nội dung truyện
5. KHÔNG thêm markdown, heading, hoặc format mới

CHỈ trả về nội dung đã dọn dẹp, không thêm comment hay giải thích.
PROMPT;

        try {
            return trim($this->aiService->callText($systemPrompt, $content, temperature: 0.1));
        } catch (\Throwable $e) {
            Log::warning('AI content clean failed, returning original', [
                'error' => $e->getMessage(),
            ]);

            return $content;
        }
    }
}
