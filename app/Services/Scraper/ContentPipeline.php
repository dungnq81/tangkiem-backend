<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Services\Scraper\Contracts\ContentProcessorInterface;
use App\Services\Scraper\Data\ExtractedContent;
use App\Services\Scraper\Processors\ContentValidationProcessor;
use App\Services\Scraper\Processors\NormalizeLineBreaksProcessor;
use App\Services\Scraper\Processors\RemoveElementsProcessor;
use App\Services\Scraper\Processors\RemoveTextPatternProcessor;

/**
 * Composable content processing pipeline (Pipeline Pattern).
 *
 * Chains multiple processors for content transformation:
 *   Clean HTML → Remove patterns → Normalize → Validate
 *
 * Each processor implements ContentProcessorInterface and handles
 * one transformation step. Processors can be added/removed/reordered
 * without modifying the pipeline itself.
 *
 * Usage:
 *   $pipeline = ContentPipeline::default();
 *   $result = $pipeline->process($htmlContent, $config);
 */
class ContentPipeline
{
    /** @var ContentProcessorInterface[] */
    protected array $processors = [];

    protected ?ContentValidationProcessor $validator = null;

    protected ?RemoveElementsProcessor $elementsCleaner = null;

    /**
     * Create the default pipeline with standard processors.
     */
    public static function default(): static
    {
        $pipeline = new static();
        $pipeline->addProcessor(new RemoveTextPatternProcessor());
        $pipeline->addProcessor(new NormalizeLineBreaksProcessor());

        $validator = new ContentValidationProcessor();
        $pipeline->validator = $validator;
        $pipeline->addProcessor($validator);

        return $pipeline;
    }

    /**
     * Add a processor to the end of the pipeline.
     */
    public function addProcessor(ContentProcessorInterface $processor): static
    {
        $this->processors[] = $processor;

        return $this;
    }

    /**
     * Prepend a processor to the beginning of the pipeline.
     */
    public function prependProcessor(ContentProcessorInterface $processor): static
    {
        array_unshift($this->processors, $processor);

        return $this;
    }

    /**
     * Run content through all processors in order.
     *
     * Returns ExtractedContent with processed content + validation metadata.
     */
    public function process(string $content, array $config = []): ExtractedContent
    {
        $processedContent = $content;

        foreach ($this->processors as $processor) {
            $processedContent = $processor->process($processedContent, $config);
        }

        return new ExtractedContent(
            content: $processedContent,
            contentHash: $this->validator?->getContentHash(),
            validationIssues: $this->validator?->getIssues() ?? [],
        );
    }

    /**
     * Clean full-page HTML by removing unwanted elements.
     *
     * Separate from process() because this operates on FULL page HTML
     * before content extraction, while process() operates on extracted content.
     */
    public function cleanPageHtml(string $html, array $config = []): string
    {
        $this->elementsCleaner ??= new RemoveElementsProcessor();

        return $this->elementsCleaner->process($html, $config);
    }
}
