<?php

declare(strict_types=1);

namespace App\Services\Scraper\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlaywrightDriver implements DriverInterface
{
    private string $serverUrl;

    private bool $healthChecked = false;

    /**
     * CF cookies captured after successful bypass (TIER 5A).
     * Can be reused with HttpDriver to avoid repeated CF challenges.
     *
     * @var array<string, string>
     */
    private array $cfCookies = [];

    public function __construct()
    {
        // Full URL takes priority (remote VPS / cPanel deployment)
        $host = config('services.playwright.url', 'http://localhost');
        $port = config('services.playwright.port', 3100);
        $this->serverUrl = rtrim($host, '/') . ':' . $port;
    }

    /**
     * Get CF cookies captured during bypass (for reuse with HttpDriver).
     *
     * @return array<string, string>
     */
    public function getCfCookies(): array
    {
        return $this->cfCookies;
    }

    /**
     * Fetch HTML from URL via Playwright server.
     *
     * Strategy:
     * 1. Fast mode: no CF bypass, with resource blocking
     * 2. If CF detected: retry with bypass enabled
     * 3. If Turnstile: throw immediately (unresolvable)
     */
    public function fetchHtml(string $url, array $headers = []): string
    {
        $this->ensureServerRunning();

        // First attempt: fast mode (no CF bypass, with resource blocking)
        $result = $this->callServer($url, $headers, cfBypass: false);

        // If CF detected -> retry with bypass enabled
        if ($result['cf']['detected'] ?? false) {
            $cfType = $result['cf']['type'] ?? 'unknown';

            Log::info('Playwright: CF detected, retrying with bypass', [
                'url'     => $url,
                'cf_type' => $cfType,
            ]);

            // Turnstile -> don't retry, it won't work
            if ($cfType === 'turnstile') {
                throw new CloudflareDetectedException(
                    cfType: $cfType,
                    url: $url,
                    cfMessage: $result['cf']['message'] ?? null,
                );
            }

            // JS/Managed challenge -> retry with cfBypass + disable resource blocking
            $result = $this->callServer($url, $headers, cfBypass: true, blockResources: false);

            // Still CF after bypass attempt?
            if ($result['cf']['detected'] ?? false) {
                throw new CloudflareDetectedException(
                    cfType: $result['cf']['type'] ?? 'unknown',
                    url: $url,
                    cfMessage: $result['cf']['message'] ?? null,
                );
            }

            Log::info('Playwright: CF bypass successful', [
                'url'   => $url,
                'total' => ($result['timing']['total'] ?? 0) . 'ms',
            ]);

            // TIER 5A: Capture CF cookies for reuse with HttpDriver
            $this->captureCfCookies($result);
        }

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException(
                'Playwright fetch failed: ' . ($result['error'] ?? 'Unknown error') . " — {$url}"
            );
        }

        return $result['html'] ?? '';
    }

    /**
     * TIER 5A: Extract CF cookies from Playwright response for reuse.
     */
    private function captureCfCookies(array $result): void
    {
        $cookies = $result['cookies'] ?? [];
        foreach ($cookies as $cookie) {
            $name = $cookie['name'] ?? '';
            $value = $cookie['value'] ?? '';
            if ($name && $value && str_starts_with($name, 'cf_')) {
                $this->cfCookies[$name] = $value;
            }
        }

        if (! empty($this->cfCookies)) {
            Log::info('Captured CF cookies for reuse', [
                'cookie_names' => array_keys($this->cfCookies),
            ]);
        }
    }

    /**
     * Call Playwright server's /fetch endpoint.
     */
    private function callServer(
        string $url,
        array $headers,
        bool $cfBypass = false,
        bool $blockResources = true,
    ): array {
        $payload = [
            'url'     => $url,
            'headers' => $headers,
            'options' => [
                'timeout'        => 30000,
                'waitFor'        => 'networkidle',
                'blockResources' => $blockResources,
                'cfBypass'       => $cfBypass,
                'returnCookies'  => $cfBypass, // Only return cookies when doing CF bypass
            ],
        ];

        try {
            $response = Http::timeout(90) // generous timeout for CF bypass waits
                ->post("{$this->serverUrl}/fetch", $payload);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Playwright server returned HTTP {$response->status()}"
                );
            }

            return $response->json() ?? [];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \RuntimeException(
                "Không thể kết nối Playwright Server ({$this->serverUrl}). "
                . "Chạy: pnpm pw:start\n"
                . "Error: {$e->getMessage()}"
            );
        }
    }

    /**
     * Verify Playwright server is running (once per driver instance).
     */
    private function ensureServerRunning(): void
    {
        if ($this->healthChecked) {
            return;
        }

        try {
            $response = Http::timeout(5)->get("{$this->serverUrl}/health");

            if ($response->successful() && ($response->json('status') === 'ok')) {
                $this->healthChecked = true;

                return;
            }
        } catch (\Throwable $e) {
            // Connection refused or timeout
        }

        throw new \RuntimeException(
            "Playwright Server chưa chạy trên {$this->serverUrl}.\n"
            . "Khởi động: pnpm pw:start\n"
            . '(hoặc: cd backend && node scripts/playwright-server.mjs)'
        );
    }
}
