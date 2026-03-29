<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\Request;

/**
 * ExtractsSiteContext — Extracts api_domain_id from the current request.
 *
 * The ValidateApiDomain middleware attaches the ApiDomain model
 * to $request->attributes. This trait provides a clean way
 * to read it in any API controller.
 */
trait ExtractsSiteContext
{
    /**
     * Get api_domain_id from the current request, or null in local dev.
     */
    protected function getSiteId(Request $request): ?int
    {
        return $request->attributes->get('api_domain')?->id;
    }
}
