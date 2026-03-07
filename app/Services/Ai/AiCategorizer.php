<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\StoryOrigin;

use App\Models\Category;
use App\Models\Tag;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered story classification.
 *
 * Suggests: categories, tags (3 types), origin, primary_category.
 * Only picks from existing DB entries + config values.
 */
class AiCategorizer
{
    public function __construct(
        protected AiService $aiService,
    ) {}

    /**
     * Suggest classification for a story based on title and description.
     *
     * @return array{
     *   categories: int[],
     *   primary_category_id: ?int,
     *   tags: int[],
     *   warning_tags: int[],
     *   attribute_tags: int[],
     *   origin: ?string,
     *   category_names: string[],
     *   tag_names: string[],
     * }
     */
    public function suggest(string $title, ?string $description = null, ?string $authorName = null): array
    {
        // 1. Load available options from DB + config
        $categories = Category::query()->pluck('name', 'slug')->toArray();
        $tags = Tag::active()->tags()->pluck('name', 'slug')->toArray();
        $warningTags = Tag::active()->warnings()->pluck('name', 'slug')->toArray();
        $attributeTags = Tag::active()->attributes()->pluck('name', 'slug')->toArray();

        $origins = StoryOrigin::options();

        // 2. Build prompt
        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt(
            $title, $description, $authorName,
            $categories, $tags, $warningTags, $attributeTags,
            $origins,
        );

        // 3. Call AI
        $result = $this->aiService->callJson($systemPrompt, $userPrompt);

        Log::debug('AI categorizer raw result', ['result' => $result]);

        // 4. Map slugs → IDs and validate values
        return $this->mapResult($result);
    }

    /**
     * Build system prompt for classification.
     */
    protected function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Bạn là AI phân loại truyện tiếng Việt. Dựa vào tiêu đề + mô tả + tên tác giả,
hãy chọn phân loại phù hợp nhất.

QUY TẮC BẮT BUỘC:
1. CHỈ chọn từ danh sách có sẵn, KHÔNG tự tạo slug/tên mới
2. Chọn 1-5 thể loại phù hợp nhất, ưu tiên chất lượng hơn số lượng
3. primary_category là thể loại CHÍNH (quan trọng nhất, dùng làm category điều hướng)
4. Tags: chọn tags phân loại chung phù hợp
5. Warning tags: chỉ thêm nếu có dấu hiệu rõ ràng (18+, bạo lực nặng, ...)
6. Attribute tags: thuộc tính đặc biệt (xuyên không, trọng sinh, harem, ...)
7. Origin: đoán quốc gia gốc dựa trên tên tác giả, style truyện
8. Nếu không chắc chắn về một field, trả null

Trả về JSON đúng format:
{
    "categories": ["slug1", "slug2"],
    "primary_category": "slug",
    "tags": ["slug1", "slug2"],
    "warning_tags": [],
    "attribute_tags": ["slug1"],
    "origin": "china"
}
PROMPT;
    }

    /**
     * Build user prompt with story info and available options.
     */
    protected function buildUserPrompt(
        string $title,
        ?string $description,
        ?string $authorName,
        array $categories,
        array $tags,
        array $warningTags,
        array $attributeTags,
        array $origins,
    ): string {
        $desc = $description ?: '(chưa có mô tả)';
        $author = $authorName ?: '(không rõ)';

        $catList = json_encode($categories, JSON_UNESCAPED_UNICODE);
        $tagList = json_encode($tags, JSON_UNESCAPED_UNICODE);
        $warnList = json_encode($warningTags, JSON_UNESCAPED_UNICODE);
        $attrList = json_encode($attributeTags, JSON_UNESCAPED_UNICODE);
        $originList = json_encode($origins, JSON_UNESCAPED_UNICODE);

        return <<<USER
Tiêu đề: {$title}
Tác giả: {$author}
Mô tả: {$desc}

=== DANH SÁCH CÓ SẴN (chỉ chọn slug) ===
Thể loại (slug => tên): {$catList}
Tags: {$tagList}
Cảnh báo: {$warnList}
Thuộc tính: {$attrList}
Nguồn gốc (key => tên): {$originList}
USER;
    }

    /**
     * Map AI response slugs → database IDs.
     *
     * @return array<string, mixed>
     */
    protected function mapResult(array $result): array
    {
        // Map category slugs → IDs
        $categorySlugs = $result['categories'] ?? [];
        $categoryMap = Category::query()
            ->whereIn('slug', $categorySlugs)
            ->pluck('id', 'slug')
            ->toArray();
        $categoryIds = array_values($categoryMap);

        // Primary category
        $primarySlug = $result['primary_category'] ?? null;
        $primaryCategoryId = $primarySlug ? ($categoryMap[$primarySlug] ?? null) : null;

        // If primary not in selected categories but exists, add it
        if ($primaryCategoryId && ! in_array($primaryCategoryId, $categoryIds)) {
            $categoryIds[] = $primaryCategoryId;
        }

        // Map tag slugs → IDs (by type)
        $tagSlugs = $result['tags'] ?? [];
        $tagIds = Tag::active()->tags()->whereIn('slug', $tagSlugs)->pluck('id')->toArray();

        $warningSlugs = $result['warning_tags'] ?? [];
        $warningTagIds = Tag::active()->warnings()->whereIn('slug', $warningSlugs)->pluck('id')->toArray();

        $attributeSlugs = $result['attribute_tags'] ?? [];
        $attributeTagIds = Tag::active()->attributes()->whereIn('slug', $attributeSlugs)->pluck('id')->toArray();

        // Validate origin
        $origin = $result['origin'] ?? null;
        if ($origin) {
            try {
                StoryOrigin::from($origin);
            } catch (\ValueError) {
                $origin = null;
            }
        }

        // Collect names for notification display
        $categoryNames = Category::whereIn('id', $categoryIds)->pluck('name')->toArray();
        $tagNames = Tag::whereIn('id', array_merge($tagIds, $warningTagIds, $attributeTagIds))
            ->pluck('name')
            ->toArray();

        return [
            'categories'          => $categoryIds,
            'primary_category_id' => $primaryCategoryId,
            'tags'                => $tagIds,
            'warning_tags'        => $warningTagIds,
            'attribute_tags'      => $attributeTagIds,
            'origin'              => $origin,
            'category_names'      => $categoryNames,
            'tag_names'           => $tagNames,
        ];
    }
}
