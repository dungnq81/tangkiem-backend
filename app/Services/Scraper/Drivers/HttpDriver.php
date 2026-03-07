<?php

declare(strict_types=1);

namespace App\Services\Scraper\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpDriver implements DriverInterface
{
    /**
     * Get default browser-mimicking headers for use in Http::pool().
     *
     * @return array<string, string>
     */
    public function getDefaultHeaders(): array
    {
        return [
            'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36',
            'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language'           => 'vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept-Encoding'           => 'gzip, deflate',
            'Cache-Control'             => 'max-age=0',
            'Connection'                => 'keep-alive',
            'sec-ch-ua'                 => '"Google Chrome";v="133", "Chromium";v="133", "Not_A Brand";v="24"',
            'sec-ch-ua-mobile'          => '?0',
            'sec-ch-ua-platform'        => '"Windows"',
            'Sec-Fetch-Dest'            => 'document',
            'Sec-Fetch-Mode'            => 'navigate',
            'Sec-Fetch-Site'            => 'none',
            'Sec-Fetch-User'            => '?1',
            'Upgrade-Insecure-Requests' => '1',
        ];
    }

    /**
     * Detect CF in response body (public wrapper for use in Http::pool results).
     *
     * @throws CloudflareDetectedException if CF signatures found
     */
    public function detectCloudflarePublic(string $url, string $body): void
    {
        $this->detectCloudflare($url, $body);
    }
    /**
     * Fetch HTML content from URL using Laravel HTTP client.
     *
     * Sends realistic browser headers to reduce bot detection.
     * Supports custom cookies (e.g., cf_clearance) via $headers['Cookie'].
     */
    public function fetchHtml(string $url, array $headers = []): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';

        $defaultHeaders = $this->getDefaultHeaders();

        // User-provided headers override defaults (e.g., Cookie, Referer)
        $mergedHeaders = array_merge($defaultHeaders, $headers);

        // Extract cookies separately (Guzzle handles Cookie header specially)
        $cookieString = $mergedHeaders['Cookie'] ?? $mergedHeaders['cookie'] ?? null;
        unset($mergedHeaders['Cookie'], $mergedHeaders['cookie']);

        $request = Http::withHeaders($mergedHeaders)
            ->timeout(30)
            ->retry(3, 2000, when: function (\Exception $e) {
                // Don't retry on 403/503 — likely CF, cURL retries won't help
                if ($e instanceof \Illuminate\Http\Client\RequestException) {
                    return ! in_array($e->response?->status(), [403, 503], true);
                }

                return true; // Retry connection errors
            }, throw: false);

        // Add cookies if provided (e.g., cf_clearance from user's browser)
        if ($cookieString) {
            $request = $request->withHeaders(['Cookie' => $cookieString]);
        }

        // cURL options for better browser mimicry
        $curlOptions = [
            CURLOPT_ENCODING  => '',           // Auto-handle gzip/br
            CURLOPT_FOLLOWLOCATION => true,    // Follow redirects (CF often redirects)
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0, // HTTP/2 like real Chrome
        ];

        // Local dev: bypass DNS + SSL issues
        if (app()->environment('local')) {
            $resolve = [];

            if ($host) {
                $ip = $this->resolveHostViaGoogle($host);
                if ($ip) {
                    $resolve[] = "{$host}:443:{$ip}";
                    $resolve[] = "{$host}:80:{$ip}";
                }
            }

            $request = $request->withoutVerifying();

            if ($resolve) {
                $curlOptions[CURLOPT_RESOLVE] = $resolve;
            }
        }

        $request = $request->withOptions(['curl' => $curlOptions]);

        try {
            $response = $request->get($url);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $status = $e->response?->status() ?? 0;
            $this->throwWithHint($url, $status, $e->response?->body() ?? '');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \RuntimeException(
                "Không thể kết nối tới {$url} — Kiểm tra DNS hoặc kết nối mạng. ({$e->getMessage()})"
            );
        }

        if ($response->failed()) {
            $status = $response->status();
            $this->throwWithHint($url, $status, $response->body());
        }

        // Detect CF challenge even on 200 status (some CF pages return 200)
        $body = $response->body();
        $this->detectCloudflare($url, $body);

        return $body;
    }

    /**
     * Throw RuntimeException with actionable hint based on HTTP status.
     */
    private function throwWithHint(string $url, int $status, string $body = ''): never
    {
        // Check if this is a CF block before throwing generic error
        if (in_array($status, [403, 503], true)) {
            $this->detectCloudflare($url, $body, $status);
        }

        $hint = match (true) {
            $status === 429 => 'Rate limited.',
            $status >= 500  => "Server error ({$status}).",
            default         => '',
        };

        Log::error('HTTP fetch failed', [
            'url'    => $url,
            'status' => $status,
        ]);

        throw new \RuntimeException("HTTP {$status}: {$url}\n{$hint}");
    }

    /**
     * Detect Cloudflare protection in response body.
     *
     * @throws CloudflareDetectedException if CF signatures found
     */
    private function detectCloudflare(string $url, string $body, int $status = 200): void
    {
        if (empty($body)) {
            return;
        }

        // Quick check — use specific CF signatures to avoid false positives
        // (e.g., a blog post mentioning "cloudflare" should NOT trigger this)
        $hasCfSignal = str_contains($body, '<title>Just a moment')
            || str_contains($body, 'cf-browser-verification')
            || str_contains($body, '_cf_chl')
            || str_contains($body, '<title>Attention Required');

        if (! $hasCfSignal) {
            return;
        }

        // Determine CF type
        $cfType = 'js_challenge'; // default

        if (str_contains($body, 'cf-turnstile') || str_contains($body, 'challenges.cloudflare.com')) {
            $cfType = 'turnstile';
        } elseif (str_contains($body, 'managed_challenge') || str_contains($body, 'cf-challenge-running')) {
            $cfType = 'managed_challenge';
        }

        Log::info('Cloudflare detected by HttpDriver', [
            'url'     => $url,
            'cf_type' => $cfType,
            'status'  => $status,
        ]);

        throw new CloudflareDetectedException(
            cfType: $cfType,
            url: $url,
            cfMessage: "HTTP {$status} — Cloudflare {$cfType} detected via cURL",
        );
    }

    /**
     * Resolve hostname via Google DNS-over-HTTPS (bypasses local DNS).
     */
    public function resolveHostViaGoogle(string $host): ?string
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => "https://8.8.8.8/resolve?name={$host}&type=A",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => ['Host: dns.google'],
            ]);
            $result = curl_exec($ch);
            curl_close($ch);

            if ($result) {
                $data = json_decode($result, true);
                foreach ($data['Answer'] ?? [] as $answer) {
                    if (($answer['type'] ?? 0) === 1) { // A record
                        return $answer['data'];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('DNS resolve via Google failed', ['host' => $host, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
