<?php

declare(strict_types=1);

namespace App\Services\Ai\Client;

use App\Services\Ai\Data\AiClientException;
use App\Services\Ai\Data\AiResponse;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Client — Handles Anthropic Claude Messages API.
 *
 * Uses the Anthropic Messages API format (NOT OpenAI-compatible).
 * Endpoint: /v1/messages
 *
 * Key differences from OpenAI:
 * - Auth via `x-api-key` header (not Bearer token)
 * - System prompt is a top-level `system` field (not in messages)
 * - Response content is an array of blocks, not a single string
 * - Requires `anthropic-version` header
 */
class AnthropicClient implements AiClientInterface
{
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {}

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ];

        if (isset($options['temperature'])) {
            $body['temperature'] = $options['temperature'];
        }

        $url = rtrim($this->baseUrl, '/') . '/v1/messages';
        $timeout = $options['timeout'] ?? 60;

        try {
            $response = Http::timeout($timeout)
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type'      => 'application/json',
                ])
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
            $data = $response->json();
            $errorMsg = $data['error']['message'] ?? "HTTP {$response->status()}";

            throw new AiClientException(
                $errorMsg,
                $this->provider,
                $this->model,
                $response->status(),
            );
        }

        $data = $response->json();

        // Extract text from content blocks
        $content = '';
        foreach (($data['content'] ?? []) as $block) {
            if ('text' === ($block['type'] ?? '')) {
                $content .= $block['text'];
            }
        }

        $usage = $data['usage'] ?? [];

        return new AiResponse(
            content:      $content,
            provider:     $this->provider,
            model:        $data['model'] ?? $this->model,
            promptTokens: $usage['input_tokens'] ?? 0,
            outputTokens: $usage['output_tokens'] ?? 0,
            finishReason: $data['stop_reason'] ?? 'end_turn',
        );
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
