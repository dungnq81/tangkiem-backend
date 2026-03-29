<?php

declare(strict_types=1);

namespace App\Services\Scraper\Data;

/**
 * DTO: Result of content extraction from a detail page.
 *
 * Carries extracted content + metadata (title, chapter number, validation).
 * Immutable — create a new instance to modify values.
 */
final readonly class ExtractedContent
{
    /**
     * @param  array<string>  $validationIssues  Content quality issues detected
     * @param  array<string, int>  $timing  Timing metrics (fetch_ms, extract_ms, total_ms)
     */
    public function __construct(
        public ?string $content = null,
        public ?string $title = null,
        public ?string $chapterNumber = null,
        public ?int $volumeNumber = null,
        public ?string $contentHash = null,
        public array $validationIssues = [],
        public array $timing = [],
    ) {}

    public function hasContent(): bool
    {
        return ! empty($this->content);
    }

    public function hasIssues(): bool
    {
        return ! empty($this->validationIssues);
    }

    public function hasCriticalIssues(): bool
    {
        return ! empty(array_intersect($this->validationIssues, ['empty_content', 'encoding_error']));
    }

    /**
     * Convert to array for storage in raw_data JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = array_filter([
            'content'        => $this->content,
            'title'          => $this->title,
            'chapter_number' => $this->chapterNumber,
            'volume_number'  => $this->volumeNumber,
        ], fn ($v) => $v !== null);

        if ($this->contentHash) {
            $data['_content_hash'] = $this->contentHash;
        }

        if (! empty($this->validationIssues)) {
            $data['_validation_issues'] = $this->validationIssues;
        }

        if (! empty($this->timing)) {
            $data['_timing'] = $this->timing;
        }

        return $data;
    }

    /**
     * Create from legacy array format (backward compatibility).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            content: $data['content'] ?? null,
            title: $data['title'] ?? null,
            chapterNumber: $data['chapter_number'] ?? null,
            volumeNumber: isset($data['volume_number']) ? (int) $data['volume_number'] : null,
            contentHash: $data['_content_hash'] ?? null,
            validationIssues: $data['_validation_issues'] ?? [],
            timing: $data['_timing'] ?? [],
        );
    }
}
