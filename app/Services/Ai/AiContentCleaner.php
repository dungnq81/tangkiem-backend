<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\ScrapeSource;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered chapter content cleaner.
 *
 * Pipeline:
 * 1.  Custom Patterns (global + per-source)       → FREE
 * 2.  Built-in Regex (zero-width, whitespace)      → FREE
 * 2b. Split wall-of-text at sentence boundaries    → FREE
 * 2c. Normalize \n → <br> (plain text content)     → FREE
 * 3.  AI (optional, costs tokens):
 *     a. Pattern Discovery — find junk via sample  → PAID (minimal)
 *     b. Paragraph Formatting — fix line breaks    → PAID (when needed)
 */
class AiContentCleaner
{
    /**
     * AI call options for content cleaning: fast, no retry/fallback.
     * Content cleaning handles failure gracefully (keeps original), so
     * aggressive retry is wasteful and causes 504 Gateway Timeout.
     */
    private const FAST_AI_OPTIONS = [
        'timeout'      => 30,
        'maxRetries'   => 1,
        'skipFallback' => true,
    ];

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

        // Step 2b: Split wall-of-text at sentence boundaries (FREE)
        [$content, $wallRemovals] = $this->splitWallOfTextTracked($content);
        $removals = array_merge($removals, $wallRemovals);

        // Step 2c: Normalize \n → <br> for plain text content (FREE)
        [$content, $nlRemovals] = $this->normalizeNewlinesTracked($content);
        $removals = array_merge($removals, $nlRemovals);

        // Step 3: AI-powered cleaning (PAID — optional)
        if ($useAi && AiService::isEnabled('content_clean')) {
            // 3a: Pattern Discovery — find junk via sample, apply locally
            [$content, $aiRemovals] = $this->aiDiscoverAndClean($content);
            $removals = array_merge($removals, $aiRemovals);

            // 3b: Paragraph Formatting — fix line breaks if content is wall-of-text
            [$content, $formatRemovals] = $this->aiFixParagraphs($content);
            $removals = array_merge($removals, $formatRemovals);
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
     * Step 2b: Split wall-of-text at sentence boundaries.
     *
     * Detects content with NO newlines at all (pure wall-of-text, typically from
     * scraping where whitespace was stripped). Adds \n after sentence-ending
     * punctuation when immediately followed by a new sentence start.
     *
     * Only triggers when: content > 500 chars AND contains zero \n characters.
     *
     * @return array{0: string, 1: array<string>}
     */
    protected function splitWallOfTextTracked(string $content): array
    {
        $removals = [];

        // Only trigger for wall-of-text: no newlines, long content
        if (mb_strlen($content) <= 500 || str_contains($content, "\n")) {
            return [$content, []];
        }

        $before = $content;

        // Add \n after sentence-ending punctuation followed by start of new sentence
        // Handles: "...mà ngồi.Bóng người..." → "...mà ngồi.\nBóng người..."
        // Supports: . 。 ！ ？ ! ? " 」 』
        $content = preg_replace(
            '/([.。！？!?""」』])(?=[^\s.。！？!?""」』\d])/u',
            "$1\n",
            $content,
        ) ?? $content;

        if ($before !== $content) {
            $addedBreaks = substr_count($content, "\n") - substr_count($before, "\n");
            $removals[] = "Ngắt wall-of-text: +{$addedBreaks} xuống dòng";
        }

        return [$content, $removals];
    }

    /**
     * Step 2c: Normalize plain text newlines to <br> tags.
     *
     * Only applies to content that uses plain \n for line breaks
     * (no existing <p>, <div>, or <br> tags detected).
     * Uses PHP's nl2br() to convert \n → <br>\n.
     *
     * @return array{0: string, 1: array<string>}
     */
    protected function normalizeNewlinesTracked(string $content): array
    {
        $removals = [];

        // Skip if content already uses HTML block/break tags
        if (preg_match('/<(?:p|div|br)[>\s\/]/i', $content)) {
            return [$content, []];
        }

        // Content uses plain \n for line breaks — convert to <br>
        $before = $content;
        $content = nl2br($content, false); // false = <br> instead of <br />

        if ($before !== $content) {
            $removals[] = 'Chuyển xuống dòng \n → <br>';
        }

        return [$content, $removals];
    }

    /**
     * Step 3: AI Pattern Discovery (costs tokens — but minimal).
     *
     * Strategy: Instead of sending full content to AI for rewriting (expensive),
     * send a small SAMPLE and ask AI to identify junk patterns.
     * Then apply those patterns locally via str_ireplace (free & fast).
     *
     * Benefits:
     * - 1 AI call regardless of content length
     * - ~10x fewer tokens vs full rewrite
     * - Local processing is instant
     *
     * @return array{0: string, 1: array<string>} [cleaned content, removal descriptions]
     */
    protected function aiDiscoverAndClean(string $content): array
    {
        $removals = [];

        // Build a representative sample: first ~2000 chars + last ~1000 chars
        $contentLength = mb_strlen($content);

        if ($contentLength <= 3000) {
            $sample = $content;
        } else {
            $head = mb_substr($content, 0, 2000);
            $tail = mb_substr($content, -1000);
            $sample = $head . "\n\n[...nội dung truyện...]\n\n" . $tail;
        }

        $systemPrompt = <<<'PROMPT'
Bạn là AI phát hiện text rác CÒN SÓT trong nội dung chương truyện.

BỐI CẢNH: Nội dung đã được dọn dẹp bằng regex và patterns cơ bản (đã xóa link, watermark rõ ràng, ký tự ẩn). Nhiệm vụ của bạn là tìm text rác MÀ REGEX KHÔNG BẮT ĐƯỢC.

Nhiệm vụ: Tìm các đoạn text rác tinh vi còn sót:
- Quảng cáo/khuyến mãi nhúng xen vào giữa nội dung truyện
- Watermark biến thể (viết tắt, sai chính tả cố ý, khoảng cách bất thường)
- Lời mời đọc/tải app ẩn trong câu truyện
- Tên website nguồn được chèn khéo léo vào đoạn văn
- Footnote, ghi chú của người đăng không thuộc nội dung gốc

Quy tắc:
1. CHỈ trả về những đoạn text CẦN XÓA, KHÔNG trả về nội dung truyện
2. Mỗi item phải là EXACT text xuất hiện trong nội dung (để dùng str_replace xóa)
3. KHÔNG bao gồm: tên nhân vật, địa danh, tên truyện, nội dung cốt truyện
4. Nếu không tìm thấy text rác, trả về mảng rỗng []

Trả về JSON array of strings. Ví dụ:
["nhớ ghé ủng hộ tác giả nha", "bản dịch bởi team ABC"]
PROMPT;

        try {
            $patterns = $this->aiService->callJson(
                $systemPrompt,
                $sample,
                temperature: 0.1,
                extra: self::FAST_AI_OPTIONS,
            );

            // Validate: must be a flat array of strings
            if (! is_array($patterns) || empty($patterns)) {
                return [$content, []];
            }

            // Apply each discovered pattern locally on the full content
            foreach ($patterns as $pattern) {
                if (! is_string($pattern) || mb_strlen($pattern) < 3 || mb_strlen($pattern) > 500) {
                    continue; // Skip invalid or suspicious patterns
                }

                $before = $content;
                $content = str_ireplace($pattern, '', $content);

                if ($before !== $content) {
                    $diff = mb_strlen($before) - mb_strlen($content);
                    $label = mb_strlen($pattern) > 40 ? mb_substr($pattern, 0, 40) . '…' : $pattern;
                    $removals[] = "AI phát hiện \"{$label}\": -{$diff} ký tự";
                }
            }

            // Clean up any leftover blank lines from removals
            $before = $content;
            $content = preg_replace('/(\r?\n\s*){4,}/', "\n\n\n", $content) ?? $content;
            $content = trim($content);
            if ($before !== $content) {
                $extraDiff = mb_strlen($before) - mb_strlen($content);
                if ($extraDiff > 0) {
                    $removals[] = "Dọn dẹp khoảng trống: -{$extraDiff} ký tự";
                }
            }
        } catch (\Throwable $e) {
            Log::warning('AI pattern discovery failed, skipping AI step', [
                'error' => $e->getMessage(),
            ]);
        }

        return [$content, $removals];
    }

    // ═══════════════════════════════════════════════════════════════
    // Step 3b: AI Paragraph Formatting
    // ═══════════════════════════════════════════════════════════════

    /**
     * Step 3b: AI paragraph formatting — fix missing line breaks.
     *
     * Only triggers when content appears to be "wall of text" (too few paragraphs
     * relative to content length). Sends content to AI asking it to ONLY add
     * \n\n between paragraphs — no content changes allowed.
     *
     * Safety: verifies AI didn't modify content by stripping whitespace and comparing.
     *
     * @return array{0: string, 1: array<string>} [formatted content, removal descriptions]
     */
    protected function aiFixParagraphs(string $content): array
    {
        $contentLength = mb_strlen($content);

        // Heuristic: check if content needs paragraph formatting
        // Count existing paragraphs (blocks separated by blank lines)
        $paragraphs = preg_split('/\n\s*\n/', trim($content));
        $paragraphCount = count($paragraphs);

        // ~1 paragraph per 500 chars is reasonable for novels
        // If content already has enough paragraphs, skip
        $expectedMin = max(2, intdiv($contentLength, 500));

        if ($paragraphCount >= $expectedMin) {
            return [$content, []];
        }

        Log::info('AI paragraph formatting triggered', [
            'content_length'     => $contentLength,
            'current_paragraphs' => $paragraphCount,
            'expected_min'       => $expectedMin,
        ]);

        $systemPrompt = <<<'PROMPT'
Bạn là AI định dạng văn bản truyện. Nhiệm vụ DUY NHẤT: thêm ngắt đoạn giữa các đoạn văn.

Quy tắc TUYỆT ĐỐI:
1. KHÔNG thay đổi, thêm, xóa, hoặc sửa BẤT KỲ chữ/từ nào
2. CHỈ thêm dòng trống (xuống 2 dòng) giữa các đoạn văn
3. Ngắt đoạn tại: hết lời thoại, chuyển cảnh, hết ý/đoạn mô tả, đổi người nói
4. Giữ nguyên các ngắt dòng đã có
5. Trả về CHÍNH XÁC nội dung gốc chỉ thêm ngắt đoạn đúng vị trí
6. TUYỆT ĐỐI không thêm markdown, heading, dấu gạch, hoặc ký tự mới
PROMPT;

        $maxChunkSize = 4000;
        $removals = [];

        if ($contentLength <= $maxChunkSize) {
            // Single chunk
            $formatted = $this->aiFormatChunk($systemPrompt, $content);

            if ($formatted !== null && $this->isFormattingOnly($content, $formatted)) {
                $newBreaks = substr_count($formatted, "\n\n") - substr_count($content, "\n\n");
                if ($newBreaks > 0) {
                    $removals[] = "AI định dạng đoạn văn: +{$newBreaks} ngắt đoạn";

                    return [$formatted, $removals];
                }
            }

            return [$content, []];
        }

        // Multi-chunk: split at sentence boundaries
        $chunks = $this->splitAtSentences($content, $maxChunkSize);
        $formattedChunks = [];
        $totalNewBreaks = 0;

        foreach ($chunks as $chunk) {
            $formatted = $this->aiFormatChunk($systemPrompt, $chunk);

            if ($formatted !== null && $this->isFormattingOnly($chunk, $formatted)) {
                $newBreaks = substr_count($formatted, "\n\n") - substr_count($chunk, "\n\n");
                $totalNewBreaks += max(0, $newBreaks);
                $formattedChunks[] = $formatted;
            } else {
                $formattedChunks[] = $chunk; // Keep original if verification fails
            }
        }

        if ($totalNewBreaks > 0) {
            $content = implode("\n\n", $formattedChunks);
            $removals[] = "AI định dạng đoạn văn: +{$totalNewBreaks} ngắt đoạn";
        }

        return [$content, $removals];
    }

    /**
     * Send a single chunk to AI for paragraph formatting.
     */
    protected function aiFormatChunk(string $systemPrompt, string $chunk): ?string
    {
        try {
            return $this->aiService->callText(
                $systemPrompt,
                $chunk,
                temperature: 0.1,
                extra: self::FAST_AI_OPTIONS,
            );
        } catch (\Throwable $e) {
            Log::warning('AI paragraph formatting chunk failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Verify AI only added whitespace — no content changes.
     *
     * Strips ALL whitespace from both original and formatted text,
     * then compares. If they differ, AI modified content (rejected).
     */
    protected function isFormattingOnly(string $original, string $formatted): bool
    {
        $originalStripped = preg_replace('/\s+/u', '', $original);
        $formattedStripped = preg_replace('/\s+/u', '', $formatted);

        $isValid = $originalStripped === $formattedStripped;

        if (! $isValid) {
            Log::warning('AI paragraph formatting rejected: content was modified', [
                'original_stripped_len'  => mb_strlen($originalStripped),
                'formatted_stripped_len' => mb_strlen($formattedStripped),
            ]);
        }

        return $isValid;
    }

    /**
     * Split content into chunks at sentence boundaries.
     *
     * Tries to break at sentence-ending punctuation (。！？.!?)
     * or existing newlines near the chunk size limit.
     *
     * @return array<string>
     */
    protected function splitAtSentences(string $content, int $maxChunkSize): array
    {
        if (mb_strlen($content) <= $maxChunkSize) {
            return [$content];
        }

        $chunks = [];
        $remaining = $content;

        while (mb_strlen($remaining) > $maxChunkSize) {
            $chunk = mb_substr($remaining, 0, $maxChunkSize);

            // Find the last sentence-ending punctuation or newline
            $candidates = [
                mb_strrpos($chunk, '。'),
                mb_strrpos($chunk, '！'),
                mb_strrpos($chunk, '？'),
                mb_strrpos($chunk, "\n"),
                mb_strrpos($chunk, '. '),
                mb_strrpos($chunk, '! '),
                mb_strrpos($chunk, '? '),
            ];

            $lastBreak = 0;
            foreach ($candidates as $pos) {
                if ($pos !== false && $pos > $lastBreak) {
                    $lastBreak = $pos;
                }
            }

            if ($lastBreak > $maxChunkSize * 0.5) {
                $chunks[] = mb_substr($remaining, 0, $lastBreak + 1);
                $remaining = ltrim(mb_substr($remaining, $lastBreak + 1));
            } else {
                // No good break point, split at chunk size
                $chunks[] = $chunk;
                $remaining = ltrim(mb_substr($remaining, $maxChunkSize));
            }
        }

        if (! empty(trim($remaining))) {
            $chunks[] = $remaining;
        }

        return $chunks;
    }
}
