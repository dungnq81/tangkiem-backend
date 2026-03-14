<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sitemap\SitemapManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SitemapController extends Controller
{
    /**
     * Sitemap Index — lists all sub-sitemaps.
     *
     * GET /api/v1/sitemap.xml
     */
    public function index(Request $request): Response
    {
        $manager = $this->resolveManager($request);

        return response($manager->index(), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Sub-sitemap — stories, chapters, categories, etc.
     *
     * GET /api/v1/sitemap-{name}.xml
     */
    public function show(Request $request, string $name): Response
    {
        $manager = $this->resolveManager($request);

        $xml = $manager->sub($name);

        if ($xml === null) {
            abort(404, 'Sitemap not found');
        }

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Resolve the SitemapManager for the requesting domain.
     *
     * Uses the ApiDomain attached by ValidateApiDomain middleware.
     * Falls back to APP_URL for local dev (no middleware).
     */
    protected function resolveManager(Request $request): SitemapManager
    {
        $apiDomain = $request->attributes->get('api_domain');

        if ($apiDomain) {
            return SitemapManager::forDomain($apiDomain->domain);
        }

        // Local dev fallback — no ApiDomain present
        return SitemapManager::forDomain(config('app.url'));
    }
}
