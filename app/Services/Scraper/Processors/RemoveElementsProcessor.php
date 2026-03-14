<?php

declare(strict_types=1);

namespace App\Services\Scraper\Processors;

use App\Services\Scraper\Contracts\ContentProcessorInterface;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Remove unwanted HTML elements by CSS selector.
 *
 * Operates on FULL page HTML before content extraction.
 * Uses DOM manipulation (not regex) for reliable nested element removal.
 */
class RemoveElementsProcessor implements ContentProcessorInterface
{
    public function process(string $content, array $config = []): string
    {
        $removeSelectors = $config['remove_selectors'] ?? null;
        if (empty($removeSelectors)) {
            return $content;
        }

        $selectors = is_string($removeSelectors)
            ? array_filter(array_map('trim', explode("\n", $removeSelectors)))
            : (array) $removeSelectors;

        if (empty($selectors)) {
            return $content;
        }

        try {
            $crawler = new Crawler('<div id="__wrapper__">' . $content . '</div>');
            $wrapper = $crawler->filter('#__wrapper__');

            foreach ($selectors as $selector) {
                $selector = trim($selector);
                if (empty($selector)) {
                    continue;
                }

                try {
                    $nodes = $wrapper->filter($selector);
                    $domNodes = [];
                    $nodes->each(function (Crawler $node) use (&$domNodes) {
                        $domNodes[] = $node->getNode(0);
                    });

                    // Remove in reverse to avoid index-shifting during DOM mutation
                    foreach (array_reverse($domNodes) as $domNode) {
                        if ($domNode->parentNode) {
                            $domNode->parentNode->removeChild($domNode);
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug('Invalid remove selector', [
                        'selector' => $selector,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            return $wrapper->html();
        } catch (\Exception $e) {
            Log::warning('Failed to remove elements', ['error' => $e->getMessage()]);
            return $content;
        }
    }
}
