<?php

declare( strict_types=1 );

namespace App\Services\Scraper\Drivers;

use App\Services\Ai\AiService;
use Illuminate\Support\Facades\Log;

/**
 * AI-powered data extractor: sends cleaned HTML + prompt to AI API,
 * receives structured JSON array of items.
 *
 * Uses shared AiService for API calls (Gemini/Groq).
 * Keeps scraper-specific prompt building and response post-processing.
 */
class AiExtractor {
	/**
	 * Entity type → expected fields mapping for system prompt.
	 */
	private const array ENTITY_FIELDS = [
		'category' => 'name, url, description',
		'author'   => 'name, url',
		'story'    => 'title, url, author, categories, description, cover_image',
		'chapter'  => 'title, url, chapter_number',
	];

	public function __construct(
		protected AiService $aiService,
	) {
	}

	/**
	 * Extract structured data from HTML using AI.
	 *
	 * @return array<int, array<string, string|null>>
	 */
	public function extract(
		string $html,
		?string $prompt,
		string $entityType,
		?string $provider = null,
		?string $model = null,
	): array {
		// Clean HTML to reduce tokens
		$cleanedHtml     = HtmlCleaner::clean( $html );
		$estimatedTokens = HtmlCleaner::estimateTokens( $cleanedHtml );

		// Truncate if too large for AI model (max ~30K tokens ≈ 90K chars)
		$maxChars = 90_000;
		if ( mb_strlen( $cleanedHtml ) > $maxChars ) {
			Log::warning( 'TOC HTML truncated for AI', [
				'original_len'     => mb_strlen( $cleanedHtml ),
				'truncated_to'     => $maxChars,
				'estimated_tokens' => $estimatedTokens,
			] );
			$cleanedHtml     = mb_substr( $cleanedHtml, 0, $maxChars );
			$estimatedTokens = HtmlCleaner::estimateTokens( $cleanedHtml );
		}

		Log::debug( 'AI extraction starting', [
			'provider'         => $provider ?? 'default',
			'model'            => $model ?? 'default',
			'original_size'    => strlen( $html ),
			'cleaned_size'     => strlen( $cleanedHtml ),
			'estimated_tokens' => $estimatedTokens,
		] );

		// Build scraper-specific prompts
		$systemPrompt = $this->buildSystemPrompt( $entityType );
		$userPrompt   = $this->buildUserPrompt( $prompt, $cleanedHtml );

		// Call AI via shared service
		$result = $this->aiService->callJson(
			systemPrompt: $systemPrompt,
			userPrompt: $userPrompt,
			provider: $provider,
			model: $model,
			temperature: 0.1,
		);

		// Post-process: ensure flat list of items for scraper
		return $this->normalizeExtractedItems( $result );
	}

	/**
	 * Build system prompt with entity type context.
	 */
	protected function buildSystemPrompt( string $entityType ): string {
		$fields = self::ENTITY_FIELDS[ $entityType ] ?? 'name, url';

		return <<<PROMPT
        Bạn là AI trích xuất dữ liệu có cấu trúc từ HTML.
        Nhiệm vụ: trích xuất danh sách "{$entityType}" từ HTML được cung cấp.

        Fields cần trích xuất: {$fields}

        Quy tắc BẮT BUỘC:
        1. Trả về ĐÚNG JSON array, KHÔNG có text/markdown nào khác
        2. Mỗi phần tử là 1 object với các fields ở trên
        3. URL phải giữ nguyên gốc (relative hoặc absolute)
        4. Bỏ qua navigation, footer, sidebar, ads — chỉ lấy content chính
        5. Nếu field không tìm thấy, set giá trị null
        6. Trả về mảng rỗng [] nếu không tìm thấy item nào

        VD response đúng:
        [{"name":"Item 1","url":"/item-1"},{"name":"Item 2","url":"/item-2"}]
        PROMPT;
	}

	/**
	 * Build user prompt combining custom prompt and HTML content.
	 */
	protected function buildUserPrompt( ?string $customPrompt, string $cleanedHtml ): string {
		$parts = [];

		if ( $customPrompt ) {
			$parts[] = "Hướng dẫn thêm: {$customPrompt}";
			$parts[] = '';
		}

		$parts[] = 'HTML content:';
		$parts[] = $cleanedHtml;

		return implode( "\n", $parts );
	}

	/**
	 * Normalize AI response into a flat list of extracted items.
	 *
	 * AiService::callJson may return structured objects or wrapped arrays.
	 * For the scraper, we always need a flat array of item objects.
	 *
	 * @return array<int, array<string, string|null>>
	 */
	protected function normalizeExtractedItems( array $result ): array {
		// Already a list of items
		if ( ! empty( $result ) && array_is_list( $result ) && is_array( $result[0] ?? null ) ) {
			return array_values( array_filter( $result, static fn( $item ) => is_array( $item ) && array_filter( $item ) ) );
		}

		// If a single item (not a list), wrap it
		if ( ! empty( $result ) && ! array_is_list( $result ) ) {
			// Check if it's a wrapper object {key: [...items]}
			foreach ( $result as $value ) {
				if ( is_array( $value ) && ( empty( $value ) || array_is_list( $value ) ) ) {
					return array_values( array_filter( $value, static fn( $item ) => is_array( $item ) && array_filter( $item ) ) );
				}
			}

			// Single item object
			return [ $result ];
		}

		return $result;
	}
}
