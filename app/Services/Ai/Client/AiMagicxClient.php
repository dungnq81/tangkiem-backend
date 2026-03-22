<?php

declare(strict_types=1);

namespace App\Services\Ai\Client;

use App\Services\Ai\Data\AiClientException;
use App\Services\Ai\Data\AiResponse;
use Illuminate\Support\Facades\Http;

/**
 * AI Magicx Client — Custom API format for AI Magicx aggregator.
 *
 * AI Magicx routes to OpenAI, Anthropic, and Google models via a unified API.
 * NOT OpenAI-compatible: uses /chat endpoint, `message` field (string), camelCase params.
 *
 * Also supports image generation via /image-generation endpoint
 * (Flux, DALL-E 3, Stable Diffusion models).
 *
 * Base URL: https://beta.aimagicx.com/api/v1
 * Auth: Authorization: Bearer mgx-sk-xxx
 * Docs: https://docs.aimagicx.com/api/chat
 */
class AiMagicxClient implements AiClientInterface, ImageGenerationInterface
{
    /**
     * Mapping from standard aspect ratios (Gemini format) to AI Magicx size names.
     */
    private const ASPECT_RATIO_MAP = [
        '1:1'  => 'square',
        '2:3'  => 'portrait_4_3',     // Closest match (no 2:3 in API)
        '3:2'  => 'landscape_4_3',    // Closest match
        '3:4'  => 'portrait_4_3',
        '4:3'  => 'landscape_4_3',
        '4:5'  => 'portrait_4_3',     // Closest match
        '5:4'  => 'landscape_4_3',    // Closest match
        '9:16' => 'portrait_16_9',
        '16:9' => 'landscape_16_9',
        '21:9' => 'landscape_16_9',   // Closest match
    ];

    /** Default image model if none specified. */
    private const DEFAULT_IMAGE_MODEL = 'fal-ai/flux/schnell';

    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // Chat Completion (AiClientInterface)
    // ═══════════════════════════════════════════════════════════════

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        $body = [
            'model'   => $this->model,
            'message' => $userMessage,
            'system'  => $systemPrompt,
            'stream'  => false,
        ];

        $temperature = $options['temperature'] ?? 0.1;
        $body['temperature'] = min(max($temperature, 0), 2.0);

        if (isset($options['max_tokens'])) {
            $body['maxTokens'] = min((int) $options['max_tokens'], 4096);
        }

        $url = rtrim($this->baseUrl, '/') . '/chat';
        $timeout = $options['timeout'] ?? 60;

        try {
            $response = Http::connectTimeout(10)
                ->timeout($timeout)
                ->withToken($this->apiKey)
                ->post($url, $body);
        } catch (\Throwable $e) {
            throw new AiClientException(
                "Network error: {$e->getMessage()}",
                $this->provider,
                $this->model,
                0,
                $e,
            );
        }

        if ($response->failed()) {
            $json = $response->json();
            $errorMsg = $json['error']['message'] ?? "HTTP {$response->status()}";

            throw new AiClientException(
                $errorMsg,
                $this->provider,
                $this->model,
                $response->status(),
            );
        }

        $json = $response->json();

        // AI Magicx wraps response in { success, data: { choices, usage, ... }, error, meta }
        $data = $json['data'] ?? $json;
        $choice = $data['choices'][0] ?? [];

        return new AiResponse(
            content:      $choice['message']['content'] ?? '',
            provider:     $this->provider,
            model:        $data['model'] ?? $this->model,
            promptTokens: $data['usage']['prompt_tokens'] ?? 0,
            outputTokens: $data['usage']['completion_tokens'] ?? 0,
            finishReason: $choice['finish_reason'] ?? 'stop',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Image Generation (ImageGenerationInterface)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Generate image via AI Magicx /image-generation endpoint.
     *
     * Supports Flux, DALL-E 3, Stable Diffusion models.
     * Downloads the generated image URL and returns base64-encoded data
     * for consistency with Gemini's callImage() return format.
     *
     * @param  string  $prompt   Text prompt (max 4000 chars).
     * @param  array   $options  Optional: model, size/aspectRatio, quality, style, n, negative_prompt, timeout.
     * @return string  Base64-encoded image data.
     */
    public function generateImage(string $prompt, array $options = []): string
    {
        $body = [
            'prompt' => mb_substr($prompt, 0, 4000),
            'model'  => $options['model'] ?? self::DEFAULT_IMAGE_MODEL,
            'n'      => 1,
        ];

        // Resolve size from aspect ratio or direct size name
        $body['size'] = $this->resolveImageSize($options);

        if (isset($options['quality'])) {
            $body['quality'] = $options['quality'];
        }

        if (isset($options['style'])) {
            $body['style'] = $options['style'];
        }

        if (isset($options['negative_prompt'])) {
            $body['negative_prompt'] = $options['negative_prompt'];
        }

        $url = rtrim($this->baseUrl, '/') . '/image-generation';
        $timeout = $options['timeout'] ?? 120;

        try {
            $response = Http::connectTimeout(10)
                ->timeout($timeout)
                ->withToken($this->apiKey)
                ->post($url, $body);
        } catch (\Throwable $e) {
            throw new AiClientException(
                "Image generation network error: {$e->getMessage()}",
                $this->provider,
                $body['model'],
                0,
                $e,
            );
        }

        if ($response->failed()) {
            $json = $response->json();
            $errorMsg = $json['error']['message'] ?? "HTTP {$response->status()}";

            throw new AiClientException(
                $errorMsg,
                $this->provider,
                $body['model'],
                $response->status(),
            );
        }

        $json = $response->json();

        // Response: { success, data: { data: [{url, b64_json}, ...], ... } }
        $responseData = $json['data'] ?? $json;
        $images = $responseData['data'] ?? [];

        if (empty($images)) {
            throw new AiClientException(
                'AI Magicx Image API không trả về dữ liệu ảnh.',
                $this->provider,
                $body['model'],
            );
        }

        $image = $images[0];

        // If API returns base64 directly, use it
        if (! empty($image['b64_json'])) {
            return $image['b64_json'];
        }

        // If API returns URL, download and convert to base64
        if (! empty($image['url'])) {
            return $this->downloadImageAsBase64($image['url']);
        }

        throw new AiClientException(
            'AI Magicx Image response missing both url and b64_json.',
            $this->provider,
            $body['model'],
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Accessors
    // ═══════════════════════════════════════════════════════════════

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    // ═══════════════════════════════════════════════════════════════
    // Private Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Resolve image size from options.
     *
     * Accepts either:
     * - 'size' key with AI Magicx name (e.g., 'portrait_4_3')
     * - 'aspectRatio' key with standard ratio (e.g., '2:3') — auto-mapped
     */
    private function resolveImageSize(array $options): string
    {
        // Direct size name
        if (isset($options['size'])) {
            return $options['size'];
        }

        // Map from standard aspect ratio
        if (isset($options['aspectRatio'])) {
            return self::ASPECT_RATIO_MAP[$options['aspectRatio']] ?? 'square';
        }

        return 'square';
    }

    /**
     * Download an image URL and return as base64-encoded string.
     */
    private function downloadImageAsBase64(string $url): string
    {
        try {
            $response = Http::connectTimeout(10)->timeout(30)->get($url);

            if ($response->failed()) {
                throw new \RuntimeException("Failed to download image: HTTP {$response->status()}");
            }

            return base64_encode($response->body());
        } catch (\Throwable $e) {
            throw new AiClientException(
                "Failed to download generated image: {$e->getMessage()}",
                $this->provider,
                $this->model,
            );
        }
    }
}
