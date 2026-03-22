<?php

declare(strict_types=1);

namespace App\Services\Ai\Generators;

use App\Models\Story;
use App\Services\Ai\Contracts\AbstractAiGenerator;
use App\Support\SeoLimits;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI composite generator: Content + SEO in a SINGLE API call.
 *
 * Generates all in one request:
 * - content: HTML story introduction (200-600 words)
 * - meta_title, meta_description, meta_keywords: SEO metadata
 *
 * Used by both UI button and AiRunScheduled command.
 */
class AiStoryCompositeGenerator extends AbstractAiGenerator
{
    private const MAX_CHAPTERS = 3;

    private const MAX_CONTENT_CHARS = 4000;

    /**
     * Generate content + SEO for a story in one API call.
     *
     * @return array{content: string, meta_title: string, meta_description: string, meta_keywords: string}
     *
     * @throws \RuntimeException When no chapters AND internet search finds nothing
     */
    public function generate(Story $story): array
    {
        $contentText = $this->extractContentFromChapters($story);
        $hasChapters = ! empty($contentText);

        $systemPrompt = $this->buildSystemPrompt($hasChapters);
        $userPrompt = $this->buildUserPrompt($story, $contentText);

        $useSearch = $this->shouldUseSearch(! $hasChapters);

        Log::debug('AI Story Composite generator starting', [
            'story_id'     => $story->id,
            'title'        => $story->title,
            'content_len'  => mb_strlen($contentText),
            'has_chapters' => $hasChapters,
            'use_search'   => $useSearch,
        ]);

        $result = trim($this->aiService->callText(
            $systemPrompt,
            $userPrompt,
            useSearch: $useSearch,
            responseMimeType: ! $useSearch ? 'application/json' : null,
        ));

        if (! $hasChapters && $this->isNotFound($result)) {
            throw new \RuntimeException(
                'Truyện chưa có chương nào trong hệ thống và không tìm thấy nội dung trên internet. '
                . 'Hãy cập nhật chương trước rồi thử lại.'
            );
        }

        return $this->parseResult($result);
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

            $texts[] = "--- Chương {$chapter->chapter_number}: {$chapter->title} ---\n{$text}";
            $totalLength += mb_strlen($text);
        }

        return implode("\n\n", $texts);
    }

    protected function buildSystemPrompt(bool $hasChapters): string
    {
        $promptTitle = SeoLimits::PROMPT_TITLE;
        $promptDesc = SeoLimits::PROMPT_DESCRIPTION;

        $searchNote = $hasChapters
            ? ''
            : <<<'SEARCH'


BỔ SUNG: Hệ thống chưa có nội dung chương. Bạn có khả năng tìm kiếm trên internet.
Hãy tìm kiếm thông tin truyện trên internet (kiemhieptruyen, truyenqq, truyenfull, tangthuvien, metruyenchu, wikidich, v.v.).
Nếu KHÔNG tìm thấy bất kỳ thông tin nào, trả về:
{"content": "NOT_FOUND", "meta_title": "NOT_FOUND", "meta_description": "NOT_FOUND", "meta_keywords": "NOT_FOUND"}
Không bịa nội dung khi không có thông tin.
SEARCH;

        return <<<PROMPT
Bạn là AI chuyên viết nội dung giới thiệu và SEO cho website truyện tiếng Việt.
{$searchNote}
NHIỆM VỤ: Tạo NỘI DUNG GIỚI THIỆU + SEO METADATA trong 1 lần.

=== PHẦN 1: NỘI DUNG GIỚI THIỆU (content) ===
- Viết nội dung giới thiệu hấp dẫn, lôi cuốn
- Độ dài: 200-600 từ
- Ngôn ngữ: Tiếng Việt tự nhiên, mượt mà
- KHÔNG spoil nội dung quan trọng, chỉ gợi mở
- Giới thiệu nhân vật chính, bối cảnh, xung đột
- Kết thúc bằng câu tạo sự tò mò
- Dạng HTML đơn giản (<p>, <strong>, <em>). KHÔNG dùng <h1>, <h2>

=== PHẦN 2: SEO METADATA ===
1. meta_title: tối đa {$promptTitle} ký tự, chứa tên truyện + từ khóa hấp dẫn
   - Format: "[Tên truyện] - [Mô tả ngắn]"
2. meta_description: tối đa {$promptDesc} ký tự, mô tả ngắn gọn, hấp dẫn
   - Thu hút click, chứa từ khóa chính
3. meta_keywords: 5-10 từ khóa, cách nhau bằng dấu phẩy

ĐỊNH DẠNG TRẢ VỀ: JSON thuần, không markdown, không code block
{
  "content": "<p>Nội dung giới thiệu HTML...</p>",
  "meta_title": "...",
  "meta_description": "...",
  "meta_keywords": "..."
}

CHỈ trả về JSON, không thêm gì khác.
PROMPT;
    }

    protected function buildUserPrompt(Story $story, string $contentText): string
    {
        $categories = $story->categories->pluck('name')->implode(', ') ?: '(chưa phân loại)';
        $tags = $story->tags->pluck('name')->implode(', ');
        $author = $story->author?->name ?: '(chưa rõ)';

        $parts = [
            "Tên truyện: {$story->title}",
            "Tác giả: {$author}",
            "Thể loại: {$categories}",
        ];

        if (! empty($tags)) {
            $parts[] = "Tags: {$tags}";
        }

        if (! empty($story->description)) {
            $parts[] = '';
            $parts[] = '=== MÔ TẢ NGẮN ===';
            $parts[] = Str::limit(strip_tags($story->description), 500);
        }

        if (! empty($contentText)) {
            $parts[] = '';
            $parts[] = '=== NỘI DUNG CÁC CHƯƠNG ĐẦU ===';
            $parts[] = $contentText;
            $parts[] = '';
            $parts[] = 'Dựa vào tiêu đề, thể loại và nội dung chương để viết nội dung giới thiệu + SEO.';
        } else {
            $parts[] = '';
            $parts[] = 'Hệ thống chưa có chương nào của truyện này.';
            $parts[] = 'Hãy tìm kiếm trên internet rồi viết nội dung giới thiệu + SEO.';
            $parts[] = 'Nếu không tìm thấy, trả về NOT_FOUND.';
        }

        return implode("\n", $parts);
    }

    protected function parseResult(string $result): array
    {
        $data = $this->parseJson($result);

        return [
            'content'          => (string) ($data['content'] ?? ''),
            'meta_title'       => Str::limit($data['meta_title'] ?? '', SeoLimits::MAX_TITLE, ''),
            'meta_description' => Str::limit($data['meta_description'] ?? '', SeoLimits::MAX_DESCRIPTION, ''),
            'meta_keywords'    => Str::limit($data['meta_keywords'] ?? '', SeoLimits::MAX_KEYWORDS, ''),
        ];
    }
}
