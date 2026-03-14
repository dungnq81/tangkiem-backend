<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Story;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI-powered SEO metadata generator for stories.
 *
 * Generates optimized SEO metadata:
 * - meta_title: ≤60 chars, compelling and keyword-rich
 * - meta_description: ≤160 chars, click-worthy snippet
 * - meta_keywords: comma-separated relevant keywords
 *
 * Flow:
 * 1. Uses title + categories + description + content as context
 * 2. If no content available → search internet
 * 3. If nothing found → throw error
 */
class AiSeoGenerator
{
    private const MAX_CHAPTERS = 2;

    private const MAX_CONTENT_CHARS = 2000;

    /** Sentinel value the AI returns when it cannot find info online. */
    private const NOT_FOUND_MARKER = 'NOT_FOUND';

    public function __construct(
        protected AiService $aiService,
    ) {}

    /**
     * Generate SEO metadata for a story.
     *
     * @return array{meta_title: string, meta_description: string, meta_keywords: string}
     *
     * @throws \RuntimeException When no content AND internet search finds nothing
     */
    public function generate(Story $story): array
    {
        $context = $this->buildContext($story);
        $hasContent = ! empty($context['chapter_text']) || ! empty($story->content) || ! empty($story->description);

        $systemPrompt = $this->buildSystemPrompt($hasContent);
        $userPrompt = $this->buildUserPrompt($story, $context);

        Log::debug('AI SEO generator starting', [
            'story_id'    => $story->id,
            'title'       => $story->title,
            'has_content' => $hasContent,
            'use_search'  => ! $hasContent,
        ]);

        $result = trim($this->aiService->callText(
            $systemPrompt,
            $userPrompt,
            useSearch: ! $hasContent,
            responseMimeType: 'application/json',
        ));

        // If AI couldn't find info online
        if (! $hasContent && str_contains($result, self::NOT_FOUND_MARKER)) {
            throw new \RuntimeException(
                'Truyện chưa có nội dung và không tìm thấy thông tin trên internet. '
                . 'Hãy cập nhật nội dung trước rồi thử lại.'
            );
        }

        return $this->parseResult($result, $story);
    }

    /**
     * Build context from story data.
     */
    protected function buildContext(Story $story): array
    {
        $categories = $story->categories->pluck('name')->implode(', ') ?: '';
        $tags = $story->tags->pluck('name')->implode(', ') ?: '';
        $author = $story->author?->name ?: '';
        $description = $story->description ?: '';
        $content = $story->content ?: '';
        $chapterText = $this->extractChapterContent($story);

        return [
            'categories'   => $categories,
            'tags'         => $tags,
            'author'       => $author,
            'description'  => $description,
            'content'      => $content,
            'chapter_text' => $chapterText,
        ];
    }

    /**
     * Extract plain text from first N chapters.
     */
    protected function extractChapterContent(Story $story): string
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

            $text = strip_tags($text);
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            $remaining = self::MAX_CONTENT_CHARS - $totalLength;
            if ($remaining <= 0) {
                break;
            }

            if (mb_strlen($text) > $remaining) {
                $text = Str::limit($text, $remaining, '...');
            }

            $texts[] = $text;
            $totalLength += mb_strlen($text);
        }

        return implode(' ', $texts);
    }

    /**
     * Build system prompt.
     */
    protected function buildSystemPrompt(bool $hasContent): string
    {
        $base = <<<'PROMPT'
Bạn là chuyên gia SEO cho website truyện tiếng Việt.

NHIỆM VỤ: Tạo metadata SEO tối ưu cho trang truyện.

QUY TẮC SEO:
1. meta_title: tối đa 55 ký tự, chứa tên truyện + từ khóa hấp dẫn
   - Format gợi ý: "[Tên truyện] - [Từ khóa mô tả ngắn]"
   - Ví dụ: "Đấu Phá Thương Khung - Tiên Hiệp Hấp Dẫn Nhất"
2. meta_description: tối đa 155 ký tự, mô tả ngắn gọn, hấp dẫn, chứa từ khóa chính
   - Phải tạo sự tò mò, thu hút click
   - Bao gồm: nhân vật chính, thể loại, điểm nổi bật
3. meta_keywords: 5-10 từ khóa, cách nhau bằng dấu phẩy
   - Ưu tiên: tên truyện, tên tác giả, thể loại, từ khóa liên quan
   - Thêm từ khóa: "đọc truyện", "truyện hay", "truyện online"

ĐỊNH DẠNG TRẢ VỀ: JSON thuần, không markdown, không code block
{
  "meta_title": "...",
  "meta_description": "...",
  "meta_keywords": "..."
}

CHỈ trả về JSON, không thêm gì khác.
PROMPT;

        if (! $hasContent) {
            $base .= <<<'PROMPT'


BỔ SUNG: Truyện chưa có nội dung trong hệ thống (chưa cập nhật).
Hãy tìm kiếm thông tin truyện trên internet (kiemhieptruyen, truyenqq, truyenfull, tangthuvien, metruyenchu, wikidich, truyenyy, v.v.) để có ngữ cảnh viết SEO.

QUAN TRỌNG: Nếu KHÔNG tìm thấy bất kỳ thông tin nào về truyện này trên internet, trả về JSON:
{"meta_title": "NOT_FOUND", "meta_description": "NOT_FOUND", "meta_keywords": "NOT_FOUND"}
Không bịa thông tin khi không có dữ liệu.
PROMPT;
        }

        return $base;
    }

    /**
     * Build user prompt with story context.
     */
    protected function buildUserPrompt(Story $story, array $context): string
    {
        $parts = [
            "Tên truyện: {$story->title}",
        ];

        if (! empty($context['author'])) {
            $parts[] = "Tác giả: {$context['author']}";
        }

        if (! empty($context['categories'])) {
            $parts[] = "Thể loại: {$context['categories']}";
        }

        if (! empty($context['tags'])) {
            $parts[] = "Tags: {$context['tags']}";
        }

        if (! empty($context['description'])) {
            $parts[] = '';
            $parts[] = '=== MÔ TẢ NGẮN ===';
            $parts[] = Str::limit(strip_tags($context['description']), 500);
        }

        if (! empty($context['content'])) {
            $parts[] = '';
            $parts[] = '=== NỘI DUNG GIỚI THIỆU ===';
            $parts[] = Str::limit(strip_tags($context['content']), 1000);
        }

        if (! empty($context['chapter_text'])) {
            $parts[] = '';
            $parts[] = '=== NỘI DUNG CHƯƠNG ĐẦU ===';
            $parts[] = $context['chapter_text'];
        }

        if (empty($context['description']) && empty($context['content']) && empty($context['chapter_text'])) {
            $parts[] = '';
            $parts[] = 'Hệ thống chưa có nội dung nào của truyện này (chưa cập nhật).';
            $parts[] = 'Hãy tìm kiếm trên internet để tìm thông tin, rồi tạo SEO metadata.';
            $parts[] = 'Nếu không tìm thấy, trả về NOT_FOUND cho tất cả trường.';
        }

        $parts[] = '';
        $parts[] = 'Hãy tạo metadata SEO tối ưu cho trang truyện này. Trả về JSON.';

        return implode("\n", $parts);
    }

    /**
     * Parse AI response into structured array.
     */
    protected function parseResult(string $result, Story $story): array
    {
        // Clean markdown code blocks if present
        $result = preg_replace('/^```(?:json)?\s*/m', '', $result);
        $result = preg_replace('/```\s*$/m', '', $result);
        $result = trim($result);

        $data = json_decode($result, true);

        if (! is_array($data)) {
            Log::warning('AI SEO: Failed to parse JSON response', [
                'story_id' => $story->id,
                'raw'      => $result,
            ]);

            throw new \RuntimeException('AI trả về kết quả không hợp lệ. Vui lòng thử lại.');
        }

        // Enforce character limits
        return [
            'meta_title'       => Str::limit($data['meta_title'] ?? '', 60, ''),
            'meta_description' => Str::limit($data['meta_description'] ?? '', 160, ''),
            'meta_keywords'    => Str::limit($data['meta_keywords'] ?? '', 500, ''),
        ];
    }
}
