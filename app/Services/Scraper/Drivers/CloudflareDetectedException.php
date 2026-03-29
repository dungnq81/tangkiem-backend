<?php

declare(strict_types=1);

namespace App\Services\Scraper\Drivers;

/**
 * Exception thrown when Cloudflare protection is detected.
 *
 * Carries CF type info so callers can decide how to handle:
 *   - js_challenge: auto-solvable with browser
 *   - managed_challenge: may auto-solve with stealth browser
 *   - turnstile: requires manual intervention
 */
class CloudflareDetectedException extends \RuntimeException
{
    public function __construct(
        public readonly string $cfType,
        public readonly string $url,
        public readonly ?string $cfMessage = null,
        ?\Throwable $previous = null,
    ) {
        $hint = match ($cfType) {
            'turnstile' => 'Cloudflare Turnstile',
            'managed_challenge' => 'Cloudflare Managed Challenge',
            'js_challenge' => 'Cloudflare JS Challenge',
            default => "Cloudflare protection ({$cfType})",
        };

        parent::__construct(
            "Cloudflare detected [{$cfType}]: {$url}\n{$hint}",
            403,
            $previous,
        );
    }
}
