<?php

declare(strict_types=1);

namespace App\Services\Ai\Client;

use App\Services\Ai\Data\AiClientException;
use App\Services\Ai\Data\AiResponse;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI-Compatible Client — Handles all OpenAI-format APIs.
 *
 * Works with: OpenAI, Groq, Cerebras, DeepSeek, xAI Grok.
 * All use the same /v1/chat/completions endpoint format.
 */
class OpenAiClient implements AiClientInterface
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {}

    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        $body = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'temperature' => $options['temperature'] ?? 0.1,
        ];

        if (isset($options['max_tokens'])) {
            $body['max_tokens'] = $options['max_tokens'];
        }

        if (isset($options['response_format'])) {
            $body['response_format'] = $options['response_format'];
        }

        $url = rtrim($this->baseUrl, '/') . '/chat/completions';
        $timeout = $options['timeout'] ?? 60;

        try {
            $response = Http::timeout($timeout)
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

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
