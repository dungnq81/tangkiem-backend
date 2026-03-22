<?php

declare(strict_types=1);

namespace App\Services\Ai\Client;

use App\Models\Setting;
use App\Services\Ai\AiService;
use App\Services\Ai\Data\AiClientException;

/**
 * AI Client Factory — Resolves provider slug → client instance.
 *
 * Uses the `api_format` field from config/ai.php to determine which client class to use:
 * - 'openai'    → OpenAiClient (OpenAI, Groq, Cerebras, DeepSeek, Grok)
 * - 'google'    → GeminiClient (Gemini)
 * - 'anthropic' → AnthropicClient (Claude)
 * - 'aimagicx'  → AiMagicxClient (AI Magicx aggregator)
 *
 * If `api_format` is not set, defaults to 'openai' (most common).
 */
class AiClientFactory
{
    /**
     * api_format → client class mapping.
     *
     * @var array<string, class-string<AiClientInterface>>
     */
    private static array $formatMap = [
        'openai'    => OpenAiClient::class,
        'google'    => GeminiClient::class,
        'anthropic' => AnthropicClient::class,
        'aimagicx'  => AiMagicxClient::class,
    ];

    /**
     * Create an AI client for a provider.
     *
     * @param  string|null  $provider  Provider slug (null = use active from settings).
     * @param  string|null  $model     Model ID (null = use default from config).
     *
     * @throws AiClientException If provider is invalid or has no API key.
     */
    public static function create(?string $provider = null, ?string $model = null): AiClientInterface
    {
        // Resolve provider
        $provider = $provider ?: (Setting::get('ai.provider') ?: AiService::DEFAULT_PROVIDER);

        $config = config("ai.providers.{$provider}");

        if (! $config) {
            throw new AiClientException(
                "Unknown AI provider: {$provider}",
                $provider,
            );
        }

        // Resolve API key
        $apiKey = $config['api_key'] ?? '';
        if ('' === $apiKey || null === $apiKey) {
            throw new AiClientException(
                "No API key configured for provider: {$provider}",
                $provider,
            );
        }

        // Resolve model
        if (null === $model || '' === $model) {
            $model = Setting::get('ai.model') ?: ($config['default_model'] ?? null);
        }

        if (! $model) {
            throw new AiClientException(
                "No model specified for provider: {$provider}",
                $provider,
            );
        }

        // Validate model belongs to provider
        $providerModels = $config['models'] ?? [];
        if (! empty($providerModels) && ! isset($providerModels[$model])) {
            throw new AiClientException(
                "Model '{$model}' is not available for provider '{$provider}'. Available: "
                    . implode(', ', array_keys($providerModels)),
                $provider,
                $model,
            );
        }

        // Resolve client class from api_format
        $format = $config['api_format'] ?? 'openai';
        $class = self::$formatMap[$format] ?? null;

        if (null === $class) {
            throw new AiClientException(
                "Unsupported API format: {$format} (provider: {$provider})",
                $provider,
                $model,
            );
        }

        return new $class(
            provider: $provider,
            model:    $model,
            apiKey:   $apiKey,
            baseUrl:  $config['base_url'] ?? '',
        );
    }

    /**
     * Create a client for a specific provider + model (shortcut).
     */
    public static function make(string $provider, string $model): AiClientInterface
    {
        return self::create($provider, $model);
    }

    /**
     * Register a custom api_format → client mapping.
     *
     * Useful for testing or extending with custom providers.
     *
     * @param  string  $format  API format name.
     * @param  string  $class   Client class (must implement AiClientInterface).
     */
    public static function register(string $format, string $class): void
    {
        if (is_subclass_of($class, AiClientInterface::class)) {
            self::$formatMap[$format] = $class;
        }
    }
}
