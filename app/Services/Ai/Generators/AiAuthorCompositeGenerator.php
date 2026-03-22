<?php

declare(strict_types=1);

namespace App\Services\Ai\Generators;

use App\Models\Author;
use App\Services\Ai\Contracts\AbstractAiGenerator;
use App\Support\SeoLimits;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI composite generator: Author Content + SEO in a SINGLE API call.
 *
 * Generates all in one request:
 * - bio: Short biography HTML
 * - description: Detailed info HTML
 * - social_links: Social media links array
 * - meta_title, meta_description: SEO metadata
 *
 * Used by both UI button and AiRunScheduled command.
 */
class AiAuthorCompositeGenerator extends AbstractAiGenerator
{
    /**
     * Generate content + SEO for an author in one API call.
     *
     * @return array{bio: string, description: string, social_links: array, meta_title: string, meta_description: string}
     *
     * @throws \RuntimeException When internet search finds nothing
     */
    public function generate(Author $author): array
    {
        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($author);

        $useSearch = $this->shouldUseSearch(true);

        Log::debug('AI Author Composite generator starting', [
            'author_id'  => $author->id,
            'name'       => $author->name,
            'use_search' => $useSearch,
        ]);

        $result = trim($this->aiService->callText(
            $systemPrompt,
            $userPrompt,
            useSearch: $useSearch,
            responseMimeType: ! $useSearch ? 'application/json' : null,
        ));

        if ($this->isNotFound($result)) {
            throw new \RuntimeException(
                "Không tìm thấy thông tin về tác giả \"{$author->name}\" trên internet. "
                . 'Hãy cập nhật thông tin thủ công.'
            );
        }

        try {
            return $this->parseResult($result);
        } catch (\RuntimeException $e) {
            Log::warning('AI Author Composite parse failed', [
                'author_id' => $author->id,
                'raw_start' => mb_substr($result, 0, 500),
            ]);

            throw $e;
        }
    }

    protected function buildSystemPrompt(): string
    {
        $promptTitle = SeoLimits::PROMPT_TITLE;
        $promptDesc = SeoLimits::PROMPT_DESCRIPTION;

        return <<<PROMPT
Bạn là AI chuyên tìm kiếm và viết thông tin về tác giả truyện/tiểu thuyết.
Bạn có khả năng tìm kiếm thông tin trên internet.

NHIỆM VỤ: Tìm kiếm thông tin tác giả trên internet → tạo NỘI DUNG + SEO trong 1 lần.

=== PHẦN 1: NỘI DUNG TÁC GIẢ ===

A. bio (Tiểu sử ngắn):
   - 1-3 câu, tối đa 500 ký tự
   - Giới thiệu ngắn gọn: tên thật (nếu có), quốc tịch, thể loại chuyên viết
   - Dạng HTML đơn giản (<p>). KHÔNG dùng heading

B. description (Thông tin chi tiết):
   - 200-800 từ
   - Tiểu sử, cuộc đời, sự nghiệp viết, tác phẩm nổi bật
   - Phong cách viết, ảnh hưởng, thành tựu
   - Dạng HTML đơn giản (<p>, <strong>, <em>, <ul>, <li>). KHÔNG dùng <h1>, <h2>

C. social_links (Liên kết mạng xã hội):
   - Mảng JSON: [{"platform": "Tên nền tảng", "url": "https://..."}]
   - CHỈ trả về link thực sự tồn tại, KHÔNG bịa link
   - Nếu không tìm thấy → trả về []

=== PHẦN 2: SEO METADATA ===

D. meta_title: tối đa {$promptTitle} ký tự
   - Format: "[Tên tác giả] - [Mô tả ngắn]"
   - Ví dụ: "Ngã Cật Tây Hồng Thị - Tác giả Tiên Hiệp Hàng Đầu"

E. meta_description: tối đa {$promptDesc} ký tự
   - Giới thiệu tác giả, thể loại chuyên viết, tác phẩm nổi bật
   - Thu hút click, tạo sự tò mò

QUY TẮC:
1. Tìm kiếm trên internet (Wikipedia, Baidu Baike, kiemhieptruyen, tangthuvien, metruyenchu, wikidich, truyenfull, v.v.)
2. Tìm cả tên tiếng Việt và tên gốc (nếu có)
3. KHÔNG bịa thông tin khi không tìm thấy
4. KHÔNG đoán giới tính. Dùng "tác giả" hoặc gọi tên trực tiếp

ĐỊNH DẠNG TRẢ VỀ: JSON thuần, không markdown, không code block
{
  "bio": "<p>...</p>",
  "description": "<p>...</p>",
  "social_links": [{"platform": "...", "url": "..."}],
  "meta_title": "...",
  "meta_description": "..."
}

QUAN TRỌNG: Nếu KHÔNG tìm thấy bất kỳ thông tin nào về tác giả, trả về:
{"bio": "NOT_FOUND", "description": "NOT_FOUND", "social_links": [], "meta_title": "NOT_FOUND", "meta_description": "NOT_FOUND"}

CHỈ trả về JSON, không thêm gì khác.
PROMPT;
    }

    protected function buildUserPrompt(Author $author): string
    {
        $parts = [
            "Tên tác giả: {$author->name}",
        ];

        if (! empty($author->original_name)) {
            $parts[] = "Tên gốc: {$author->original_name}";
        }

        $stories = $author->stories()
            ->limit(10)
            ->pluck('title')
            ->implode(', ');

        if (! empty($stories)) {
            $parts[] = "Các tác phẩm trong hệ thống: {$stories}";
        }

        if (! empty($author->bio) && is_string($author->bio)) {
            $parts[] = '';
            $parts[] = '=== TIỂU SỬ HIỆN TẠI (tham khảo) ===';
            $parts[] = Str::limit(strip_tags($author->bio), 300);
        }

        $parts[] = '';
        $parts[] = 'Hãy tìm kiếm thông tin về tác giả này trên internet rồi tạo nội dung + SEO.';
        $parts[] = 'Nếu không tìm thấy bất kỳ thông tin nào, trả về NOT_FOUND.';

        return implode("\n", $parts);
    }

    protected function parseResult(string $result): array
    {
        $data = $this->parseJson($result);

        // Validate social_links
        $socialLinks = [];
        if (! empty($data['social_links']) && is_array($data['social_links'])) {
            foreach ($data['social_links'] as $link) {
                if (
                    is_array($link)
                    && ! empty($link['platform'])
                    && ! empty($link['url'])
                    && filter_var($link['url'], FILTER_VALIDATE_URL)
                ) {
                    $socialLinks[] = [
                        'platform' => (string) $link['platform'],
                        'url'      => (string) $link['url'],
                    ];
                }
            }
        }

        // Ensure bio/description are strings
        $bio = $data['bio'] ?? '';
        $description = $data['description'] ?? '';

        if (is_array($bio)) {
            $bio = $bio['content'] ?? $bio['text'] ?? json_encode($bio, JSON_UNESCAPED_UNICODE);
        }
        if (is_array($description)) {
            $description = $description['content'] ?? $description['text'] ?? json_encode($description, JSON_UNESCAPED_UNICODE);
        }

        return [
            'bio'              => Str::limit((string) $bio, 500, ''),
            'description'      => (string) $description,
            'social_links'     => $socialLinks,
            'meta_title'       => Str::limit($data['meta_title'] ?? '', SeoLimits::MAX_TITLE, ''),
            'meta_description' => Str::limit($data['meta_description'] ?? '', SeoLimits::MAX_DESCRIPTION, ''),
        ];
    }
}
