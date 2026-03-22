<?php

declare(strict_types=1);

namespace App\Services\Scraper\Contracts;

/**
 * Strategy interface for content processing pipeline.
 *
 * Each processor handles one transformation step:
 * clean HTML → remove patterns → normalize → validate.
 */
interface ContentProcessorInterface
{
    /**
     * Process the content and return the transformed result.
     */
    public function process(string $content, array $config = []): string;
}
