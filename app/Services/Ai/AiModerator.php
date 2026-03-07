<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * AI-powered comment moderation.
 *
 * Checks comment content for safety (spam, toxic, NSFW, ads).
 * Returns structured verdict.
 */
class AiModerator
{
    private const MIN_CONTENT_LENGTH = 10;

    public function __construct(
        protected AiService $aiService,
    ) {}

    /**
     * Check if a comment is safe.
     *
     * @return array{is_safe: bool, reason: ?string, category: string}
     */
    public function check(string $content): array
    {
        // Skip very short comments (too little context)
        if (mb_strlen(trim($content)) < self::MIN_CONTENT_LENGTH) {
            return [
                'is_safe'  => true,
                'reason'   => null,
                'category' => 'safe',
            ];
        }

        try {
            $result = $this->aiService->callJson(
                systemPrompt: $this->buildSystemPrompt(),
                userPrompt: $content,
                temperature: 0.1,
            );

            $isSafe = (bool) ($result['is_safe'] ?? true);
            $reason = $result['reason'] ?? null;
            $category = $result['category'] ?? ($isSafe ? 'safe' : 'unknown');

            Log::debug('AI moderator result', [
                'content_preview' => mb_substr($content, 0, 100),
                'is_safe'         => $isSafe,
                'category'        => $category,
            ]);

            return [
                'is_safe'  => $isSafe,
                'reason'   => $reason,
                'category' => $category,
            ];
        } catch (\Throwable $e) {
            Log::warning('AI moderation failed, defaulting to safe', [
                'error' => $e->getMessage(),
            ]);

            // Fail open: if AI is unavailable, allow the comment
            return [
                'is_safe'  => true,
                'reason'   => null,
                'category' => 'error',
            ];
        }
    }

    /**
     * Build system prompt for moderation.
     */
    protected function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Bạn là AI kiểm duyệt bình luận cho website đọc truyện tiếng Việt.

Đánh giá comment có AN TOÀN không. Phân loại:
- "safe": Bình thường, hợp lệ
- "spam": Spam, quảng cáo, link rác
- "toxic": Ngôn từ thù ghét, xúc phạm, kỳ thị
- "nsfw": Nội dung khiêu dâm, nhạy cảm
- "ad": Quảng cáo sản phẩm, dịch vụ
- "flood": Lặp lại ký tự, vô nghĩa

QUY TẮC:
1. Comment thảo luận truyện, nhân vật, tình tiết → SAFE
2. Comment khen/chê truyện bình thường → SAFE
3. Ngôn ngữ teen, viết tắt, emoji → SAFE (văn hóa internet VN)
4. Chỉ đánh "unsafe" khi CÓ BẰNG CHỨNG RÕ RÀNG
5. Khi không chắc chắn → cho là SAFE

Trả về JSON:
{"is_safe": true/false, "reason": "lý do ngắn gọn hoặc null", "category": "safe/spam/toxic/nsfw/ad/flood"}
PROMPT;
    }
}
