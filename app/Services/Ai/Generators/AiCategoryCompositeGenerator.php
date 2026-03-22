<?php

declare(strict_types=1);

namespace App\Services\Ai\Generators;

use App\Models\Category;
use App\Services\Ai\Contracts\AbstractAiGenerator;
use App\Support\SeoLimits;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI composite generator: Category Content + SEO in a SINGLE API call.
 *
 * Generates all in one request:
 * - description: Short description (plain text, ~500 chars)
 * - content: Detailed HTML content (200-600 words)
 * - meta_title, meta_description: SEO metadata
 *
 * Used by both UI button and AiRunScheduled command.
 */
class AiCategoryCompositeGenerator extends AbstractAiGenerator
{
    /**
     * Generate content + SEO for a category in one API call.
     *
     * @return array{description: string, content: string, meta_title: string, meta_description: string}
     *
     * @throws \RuntimeException When AI fails
     */
    public function generate(Category $category): array
    {
        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($category);

        // Categories are generic genres (Tiên Hiệp, Kiếm Hiệp...) — AI training data
        // is sufficient, no internet search needed. This enables responseMimeType:
        // 'application/json' which forces valid JSON output every time.
        $useSearch = $this->shouldUseSearch(false);

        Log::debug('AI Category Composite generator starting', [
            'category_id' => $category->id,
            'name'        => $category->name,
        ]);

        $result = trim($this->aiService->callText(
            $systemPrompt,
            $userPrompt,
            useSearch: $useSearch,
            responseMimeType: 'application/json',
        ));

        try {
            return $this->parseResult($result);
        } catch (\RuntimeException $e) {
            Log::warning('AI Category Composite parse failed', [
                'category_id' => $category->id,
                'raw_start'   => mb_substr($result, 0, 500),
            ]);

            throw $e;
        }
    }

    protected function buildSystemPrompt(): string
    {
        $promptTitle = SeoLimits::PROMPT_TITLE;
        $promptDesc = SeoLimits::PROMPT_DESCRIPTION;

        return <<<PROMPT
Bạn là AI chuyên viết nội dung giới thiệu thể loại truyện cho website đọc truyện tiếng Việt.

BỐI CẢNH QUAN TRỌNG:
- Website chuyên về truyện dịch từ tiếng Trung (tiểu thuyết mạng Trung Quốc / 网络文学)
- Các thể loại như Tiên Hiệp, Huyền Huyễn, Quan Trường, Đô Thị, Võng Du... đều là thể loại truyện Trung Quốc
- KHÔNG gán nguồn gốc thể loại cho Việt Nam. Nếu thể loại có nguồn gốc Trung Quốc, phải ghi rõ
- Viết nội dung bằng tiếng Việt cho độc giả Việt Nam, nhưng thông tin về thể loại phải chính xác

NHIỆM VỤ: Tạo MÔ TẢ + NỘI DUNG CHI TIẾT + SEO cho thể loại truyện trong 1 lần.

=== PHẦN 1: MÔ TẢ NGẮN (description) ===
- 1-3 câu, tối đa 500 ký tự
- Giới thiệu ngắn gọn thể loại này là gì, đặc điểm nổi bật
- Văn bản thuần (plain text), KHÔNG dùng HTML

=== PHẦN 2: NỘI DUNG CHI TIẾT (content) ===
- 200-600 từ
- Giải thích chi tiết về thể loại: nguồn gốc, đặc trưng, phong cách
- Các tác phẩm tiêu biểu, tác giả nổi bật của thể loại
- Tại sao thể loại này hấp dẫn độc giả
- Dạng HTML đơn giản (<p>, <strong>, <em>, <ul>, <li>). KHÔNG dùng <h1>, <h2>

=== PHẦN 3: SEO METADATA ===
D. meta_title: tối đa {$promptTitle} ký tự
   - Format: "Truyện [Tên thể loại] - [Mô tả ngắn]"
   - Ví dụ: "Truyện Tiên Hiệp - Đọc Online Hay Nhất"

E. meta_description: tối đa {$promptDesc} ký tự
   - Giới thiệu thể loại, từ khóa liên quan, thu hút click

QUY TẮC:
1. Viết bằng tiếng Việt tự nhiên, mượt mà
2. Nội dung phải chính xác về nguồn gốc và đặc trưng thể loại
3. KHÔNG bịa thông tin sai về thể loại
4. KHÔNG gán nguồn gốc Việt Nam cho thể loại Trung Quốc
5. LUÔN tạo nội dung. KHÔNG từ chối. Nếu mô tả hiện tại không chính xác, hãy tạo mô tả mới chính xác hơn

ĐỊNH DẠNG TRẢ VỀ: JSON thuần, không markdown, không code block
{
  "description": "Mô tả ngắn...",
  "content": "<p>Nội dung chi tiết HTML...</p>",
  "meta_title": "...",
  "meta_description": "..."
}

CHỈ trả về JSON, không thêm gì khác.
PROMPT;
    }

    protected function buildUserPrompt(Category $category): string
    {
        $parts = [
            "Tên thể loại: {$category->name}",
        ];

        if ($category->parent) {
            $parts[] = "Thể loại cha: {$category->parent->name}";
        }

        $childNames = $category->children()
            ->limit(10)
            ->pluck('name')
            ->implode(', ');

        if (! empty($childNames)) {
            $parts[] = "Thể loại con: {$childNames}";
        }

        $storyTitles = $category->stories()
            ->limit(10)
            ->pluck('title')
            ->implode(', ');

        if (! empty($storyTitles)) {
            $parts[] = "Các truyện tiêu biểu: {$storyTitles}";
        }

        $parts[] = '';
        $parts[] = 'Hãy tạo mô tả + nội dung chi tiết + SEO cho thể loại truyện này.';

        return implode("\n", $parts);
    }

    protected function parseResult(string $result): array
    {
        $data = $this->parseJson($result);

        return [
            'description'      => Str::limit((string) ($data['description'] ?? ''), 500, ''),
            'content'          => (string) ($data['content'] ?? ''),
            'meta_title'       => Str::limit($data['meta_title'] ?? '', SeoLimits::MAX_TITLE, ''),
            'meta_description' => Str::limit($data['meta_description'] ?? '', SeoLimits::MAX_DESCRIPTION, ''),
        ];
    }
}
