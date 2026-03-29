<?php

declare(strict_types=1);

namespace App\Services\Analytics\Data;

/**
 * DTO: A single page visit event.
 *
 * Immutable data object representing a collected visit before storage.
 * Created by AnalyticsCollector, consumed by RedisBuffer → DB insert.
 */
final readonly class VisitData
{
    /**
     * @param  array<string, mixed>  $meta  Extra metadata (response_code, etc.)
     */
    public function __construct(
        public string $visitedAt,
        public string $sessionHash,
        public string $pageType,
        public ?int $pageId,
        public string $deviceType,
        public string $browser,
        public string $os,
        public string $referrerType,
        public ?string $referrerDomain,
        public bool $isBot,
        public ?string $countryCode = null,
        public ?int $apiDomainId = null,
        public array $meta = [],
    ) {}

    /**
     * Convert to array for Redis/DB storage.
     *
     * Flattens meta fields (page_slug, ip_hash, user_id, utm_*) into
     * top-level keys so insertBatch can map them to DB columns.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $base = [
            'visited_at'      => $this->visitedAt,
            'session_hash'    => $this->sessionHash,
            'page_type'       => $this->pageType,
            'page_id'         => $this->pageId,
            'device_type'     => $this->deviceType,
            'browser'         => $this->browser,
            'os'              => $this->os,
            'referrer_type'   => $this->referrerType,
            'referrer_domain' => $this->referrerDomain,
            'is_bot'          => $this->isBot,
            'country_code'    => $this->countryCode,
            'api_domain_id'   => $this->apiDomainId,
        ];

        // Flatten meta fields into top-level so insertBatch maps them to columns
        return array_merge($base, $this->meta);
    }

    /**
     * Create from stored array (Redis → DB hydration).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            visitedAt: $data['visited_at'] ?? now()->toDateTimeString(),
            sessionHash: $data['session_hash'] ?? '',
            pageType: $data['page_type'] ?? 'unknown',
            pageId: isset($data['page_id']) ? (int) $data['page_id'] : null,
            deviceType: $data['device_type'] ?? 'desktop',
            browser: $data['browser'] ?? 'Unknown',
            os: $data['os'] ?? 'Unknown',
            referrerType: $data['referrer_type'] ?? 'direct',
            referrerDomain: $data['referrer_domain'] ?? null,
            isBot: (bool) ($data['is_bot'] ?? false),
            countryCode: $data['country_code'] ?? null,
            apiDomainId: isset($data['api_domain_id']) ? (int) $data['api_domain_id'] : null,
        );
    }
}
