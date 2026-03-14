<?php

declare(strict_types=1);

namespace App\Services\Scraper\Data;

/**
 * Value Object: Proxy server configuration.
 */
final readonly class ProxyConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $username = null,
        public ?string $password = null,
        public string $protocol = 'http',
    ) {}

    /**
     * Format as proxy URL string for cURL / Playwright.
     */
    public function toUrl(): string
    {
        $auth = '';
        if ($this->username && $this->password) {
            $auth = "{$this->username}:{$this->password}@";
        }

        return "{$this->protocol}://{$auth}{$this->host}:{$this->port}";
    }

    /**
     * Create from URL string.
     */
    public static function fromUrl(string $url): self
    {
        $parts = parse_url($url);

        return new self(
            host: $parts['host'] ?? '',
            port: $parts['port'] ?? 80,
            username: $parts['user'] ?? null,
            password: $parts['pass'] ?? null,
            protocol: $parts['scheme'] ?? 'http',
        );
    }
}
