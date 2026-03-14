<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Story;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI-powered story content generator.
 *
 * Flow:
 * 1. Always uses title + categories as base context
 * 2. If chapters exist in DB → use chapter content as primary reference
 * 3. If no chapters in DB → search internet via Google Search grounding
 * 4. If internet search finds nothing → throw error (don't fabricate content)
 */
class AiSummarizer
{
    private const MAX_CHAPTERS = 3;

    private const MAX_CONTENT_CHARS = 4000;

    /** Sentinel value the AI returns when it cannot find info online. */
    private const NOT_FOUND_MARKER = 'NOT_FOUND';

    public function __construct(
        protected AiService $aiService,
    ) {}

    /**
     * Generate content for a story.
     *
     * @return string Generated HTML content (Vietnamese)
     *
     * @throws \RuntimeException When no chapters AND internet search finds nothing
     */
    public function generate(Story $story): string
    {
        $contentText = $this->extractContentFromChapters($story);
        $hasChapters = ! empty($contentText);

        $systemPrompt = $this->buildSystemPrompt($hasChapters);
        $userPrompt = $this->buildUserPrompt($story, $contentText);

        Log::debug('AI summarizer starting', [
            'story_id'     => $story->id,
            'title'        => $story->title,
            'content_len'  => mb_strlen($contentText),
            'has_chapters' => $hasChapters,
            'use_search'   => ! $hasChapters,
        ]);

        $result = trim($this->aiService->callText(
            $systemPrompt,
            $userPrompt,
            useSearch: ! $hasChapters,
        ));

        // If AI couldn't find info online, throw so the UI can show proper error
        if (! $hasChapters && str_contains($result, self::NOT_FOUND_MARKER)) {
            throw new \RuntimeException(
                'Truyện chưa có chương nào trong hệ thống và không tìm thấy nội dung trên internet. '
                . 'Hãy cập nhật chương trước rồi thử lại.'
            );
        }

        return $result;
    }

    /**
     * Extract plain text from the first N chapters.
     */
    protected function extractContentFromChapters(Story $story): string
    {
        $chapters = $story->chapters()
            ->with('content')
            ->orderBy('sort_key')
            ->limit(self::MAX_CHAPTERS)
            ->get();

        if ($chapters->isEmpty()) {
            return '';
        }

        $texts = [];
        $totalLength = 0;

        foreach ($chapters as $chapter) {
            $text = $chapter->content?->content ?? '';

            if (empty($text)) {
                continue;
            }

            // Strip HTML tags, normalize whitespace
            $text = strip_tags($text);
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            // Limit per-chapter contribution
            $remaining = self::MAX_CONTENT_CHARS - $totalLength;
            if ($remaining <= 0) {
                break;
            }

            if (mb_strlen($text) > $remaining) {
                $text = Str::limit($text, $remaining, '...');
            }

            $texts[] = "--- Chương {$chapter->chapter_number}: {$chapter->title} ---\n{$text}";
            $totalLength += mb_strlen($text);
        }

        return implode("\n\n", $texts);
    }

    /**
     * Build system prompt for content generation.
     */
    protected function buildSystemPrompt(bool $hasChapters): string
    {
        if ($hasChapters) {
            return <<<'PROMPT'
Bạn là AI viết nội dung giới thiệu truyện tiếng Việt chuyên nghiệp.

QUY TẮC:
1. Viết nội dung giới thiệu hấp dẫn, lôi cuốn dựa trên tiêu đề, thể loại và nội dung các chương đầu
2. Độ dài: 200-600 từ
3. Ngôn ngữ: Tiếng Việt tự nhiên, mượt mà
4. KHÔNG spoil nội dung quan trọng, chỉ gợi mở
5. Giới thiệu nhân vật chính, bối cảnh, xung đột chính
6. Kết thúc bằng câu tạo sự tò mò
7. Trả về dạng HTML đơn giản (dùng <p>, <strong>, <em>). KHÔNG dùng <h1>, <h2> hay markdown
8. CHỈ trả về nội dung HTML, không thêm gì khác
PROMPT;
        }

        return <<<'PROMPT'
Bạn là AI viết nội dung giới thiệu truyện tiếng Việt chuyên nghiệp.
Bạn có khả năng tìm kiếm thông tin trên internet.

NHIỆM VỤ:
Hệ thống chưa có nội dung chương của truyện này (do chưa cập nhật).
Bạn cần tìm kiếm thông tin truyện trên internet để viết nội dung giới thiệu.

QUY TẮC:
1. Tìm kiếm thông tin về truyện này trên internet (kiemhieptruyen, truyenqq, truyenfull, tangthuvien, metruyenchu, wikidich, truyenyy, v.v.)
2. Tham khảo mô tả, giới thiệu từ các nguồn tìm được
3. Viết lại nội dung giới thiệu hấp dẫn, tự nhiên, KHÔNG copy nguyên văn
4. Độ dài: 200-600 từ
5. Ngôn ngữ: Tiếng Việt tự nhiên, mượt mà
6. KHÔNG spoil nội dung quan trọng, chỉ gợi mở
7. Giới thiệu nhân vật chính, bối cảnh, xung đột chính
8. Kết thúc bằng câu tạo sự tò mò
9. Trả về dạng HTML đơn giản (dùng <p>, <strong>, <em>). KHÔNG dùng <h1>, <h2> hay markdown
10. CHỈ trả về nội dung HTML, không thêm gì khác

QUAN TRỌNG: Nếu bạn KHÔNG tìm thấy bất kỳ thông tin nào về truyện này trên internet,
hãy trả về CHÍNH XÁC dòng text: NOT_FOUND
Không bịa nội dung khi không có thông tin.
PROMPT;
    }

    /**
     * Build user prompt with story info and chapter content.
     */
    protected function buildUserPrompt(Story $story, string $contentText): string
    {
        $categories = $story->categories->pluck('name')->implode(', ') ?: '(chưa phân loại)';
        $author = $story->author?->name ?: '(chưa rõ)';

        $parts = [
            "Tiêu đề: {$story->title}",
            "Tác giả: {$author}",
            "Thể loại: {$categories}",
        ];

        if (! empty($contentText)) {
            $parts[] = '';
            $parts[] = '=== NỘI DUNG CÁC CHƯƠNG ĐẦU ===';
            $parts[] = $contentText;
            $parts[] = '';
            $parts[] = 'Hãy dựa vào tiêu đề, thể loại và nội dung các chương trên để viết nội dung giới thiệu truyện.';
        } else {
            $parts[] = '';
            $parts[] = 'Hệ thống chưa có chương nào của truyện này (chưa cập nhật).';
            $parts[] = 'Hãy tìm kiếm trên internet để tìm thông tin về truyện này, rồi viết nội dung giới thiệu.';
            $parts[] = 'Nếu không tìm thấy bất kỳ thông tin nào, trả về: NOT_FOUND';
        }

        return implode("\n", $parts);
    }
}
