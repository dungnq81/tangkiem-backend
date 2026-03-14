<?php

declare(strict_types=1);

namespace App\Services\Ai\Client;

use App\Services\Ai\Data\AiClientException;

/**
 * Image Generation Interface — Contract for AI providers that support image generation.
 *
 * Separate from AiClientInterface because not all providers support image generation.
 * Currently implemented by:
 * - AiMagicxClient → POST /image-generation (Flux, DALL-E 3, Stable Diffusion)
 *
 * Note: Gemini image generation is handled directly in AiService.callImage()
 * using a different model (gemini-2.5-flash-image) and Gemini's native API format.
 */
interface ImageGenerationInterface
{
    /**
     * Generate an image from a text prompt.
     *
     * @param  string  $prompt      Text description of the image to generate.
     * @param  array   $options     Optional parameters:
     *                              - model: string (e.g., 'dall-e-3', 'fal-ai/flux/schnell')
     *                              - size: string (e.g., 'square', 'portrait_4_3', 'landscape_16_9')
     *                              - quality: string ('standard' or 'hd')
     *                              - style: string ('photorealistic', 'anime', 'digital_art', etc.)
     *                              - n: int (number of images, 1-4)
     *                              - negative_prompt: string
     *                              - timeout: int (seconds)
     *
     * @return string Base64-encoded image data (PNG/JPEG).
     *
     * @throws AiClientException On API error or network failure.
     */
    public function generateImage(string $prompt, array $options = []): string;
}
