<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiDomain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiDomain
{
    /**
     * Cache TTL for domain lookups (5 minutes).
     */
    private const CACHE_TTL = 300;

    public function handle(Request $request, Closure $next, ?string $group = null): Response
    {
        $publicKey = $request->header('X-Public-Key');
        $secretKey = $request->header('X-Secret-Key');

        // Local dev bypass: skip validation when no key is provided
        // If keys ARE provided in local, still validate normally (for testing the full flow)
        if (! $publicKey && ! $secretKey && app()->environment('local')) {
            return $next($request);
        }

        // Must have one of the keys
        if (! $publicKey && ! $secretKey) {
            return $this->unauthorized('Missing API key. Provide X-Public-Key or X-Secret-Key header.');
        }

        $domain = null;

        if ($publicKey) {
            // Browser-side: validate with Origin header
            $domain = $this->findByPublicKey($publicKey);

            if (! $domain) {
                Log::warning('API: Invalid public key attempted', [
                    'key' => substr($publicKey, 0, 8) . '...',
                    'ip' => $request->ip(),
                ]);
                return $this->unauthorized('Invalid public key');
            }

            // CRITICAL: Origin must match registered domain
            $origin = $request->header('Origin') ?? $request->header('Referer');
            $originHost = $origin ? parse_url($origin, PHP_URL_HOST) : null;

            if (! $originHost || ! $this->domainMatches($originHost, $domain->domain)) {
                Log::warning('API: Domain mismatch', [
                    'expected' => $domain->domain,
                    'received' => $originHost,
                    'ip' => $request->ip(),
                ]);
                return $this->forbidden('Domain mismatch. Origin does not match registered domain.');
            }
        } elseif ($secretKey) {
            // Server-side: no Origin check needed
            $domain = $this->findBySecretKey($secretKey);

            if (! $domain) {
                Log::warning('API: Invalid secret key attempted', [
                    'key' => substr($secretKey, 0, 8) . '...',
                    'ip' => $request->ip(),
                ]);
                return $this->unauthorized('Invalid secret key');
            }
        }

        // Check validity (active, date range)
        if (! $domain->is_active) {
            return $this->forbidden('Domain access has been deactivated.', [
                'reason' => 'inactive',
            ]);
        }

        if ($domain->valid_from && $domain->valid_from->isFuture()) {
            return $this->forbidden('Domain access is not yet active.', [
                'reason' => 'not_yet_active',
            ]);
        }

        if ($domain->valid_until && $domain->valid_until->isPast()) {
            return $this->forbidden('Domain access has expired.', [
                'reason' => 'expired',
            ]);
        }

        // Check group permission
        if ($group && ! $domain->canAccessGroup($group)) {
            return $this->forbidden('Access to this API group is not allowed.', [
                'reason' => 'group_denied',
            ]);
        }

        // Attach domain info to request for later use
        $request->attributes->set('api_domain', $domain);

        return $next($request);
    }

    // ═══════════════════════════════════════════════════════════════
    // Domain Lookup (with cache)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Find domain by public key with caching.
     * NOTE: Only caches successful lookups to prevent cache pollution attacks.
     */
    protected function findByPublicKey(string $key): ?ApiDomain
    {
        $cacheKey = 'api_domain:pub:' . hash('xxh3', $key);

        $domain = Cache::get($cacheKey);

        if ($domain) {
            return $domain;
        }

        $domain = ApiDomain::where('public_key', $key)->first();

        // Only cache successful lookups — prevents cache pollution
        if ($domain) {
            Cache::put($cacheKey, $domain, self::CACHE_TTL);
        }

        return $domain;
    }

    /**
     * Find domain by secret key with caching.
     * NOTE: Only caches successful lookups to prevent cache pollution attacks.
     */
    protected function findBySecretKey(string $key): ?ApiDomain
    {
        $cacheKey = 'api_domain:sec:' . hash('xxh3', $key);

        $domain = Cache::get($cacheKey);

        if ($domain) {
            return $domain;
        }

        $domain = ApiDomain::where('secret_key', $key)->first();

        if ($domain) {
            Cache::put($cacheKey, $domain, self::CACHE_TTL);
        }

        return $domain;
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    protected function domainMatches(string $origin, string $registered): bool
    {
        // Normalize: remove www prefix, lowercase
        $origin = strtolower(preg_replace('/^www\./', '', $origin));
        $registered = strtolower(preg_replace('/^www\./', '', $registered));

        return $origin === $registered;
    }

    protected function unauthorized(string $message, array $details = []): Response
    {
        return response()->json(array_filter([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => $message,
            'details' => $details ?: null,
        ]), 401);
    }

    protected function forbidden(string $message, array $details = []): Response
    {
        return response()->json(array_filter([
            'success' => false,
            'error' => 'Forbidden',
            'message' => $message,
            'details' => $details ?: null,
        ]), 403);
    }
}
