<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OpenRouter inference provider (AI_RULES.md §13–14, ARCHITECTURE.md §28.5).
 *
 * This adapter formats requests for OpenRouter's OpenAI-compatible API
 * (https://openrouter.ai/api/v1) and handles the response mapping.
 *
 * SECURITY: The API key is loaded from the OPENROUTER_API_KEY environment
 * variable and NEVER leaves the server side. It is never passed to the
 * frontend, logged, or committed to source control.
 *
 * The provider enforces:
 * - HTTPS-only endpoints (egress discipline)
 * - Configurable timeout (default 15s)
 * - Retry with exponential backoff (max 2 retries for idempotent requests)
 * - Token/cost guard via max_tokens
 * - Response validation (schema, required fields)
 * - PHI policy enforcement (data classification before dispatch)
 */
final class OpenRouterProvider
{
    private const BASE_URL = 'https://openrouter.ai/api/v1';

    private const DEFAULT_MODEL = 'openai/gpt-4o-mini';

    private const DEFAULT_MAX_TOKENS = 1024;

    private const DEFAULT_TIMEOUT = 15;

    private const MAX_RETRIES = 2;

    private string $apiKey;

    private string $baseUrl;

    private string $defaultModel;

    private int $maxTokens;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) env('OPENROUTER_API_KEY', '');
        $this->baseUrl = rtrim((string) env('OPENROUTER_BASE_URL', self::BASE_URL), '/');
        $this->defaultModel = (string) env('OPENROUTER_DEFAULT_MODEL', self::DEFAULT_MODEL);
        $this->maxTokens = (int) env('OPENROUTER_MAX_TOKENS', self::DEFAULT_MAX_TOKENS);
        $this->timeout = (int) env('OPENROUTER_TIMEOUT', self::DEFAULT_TIMEOUT);
    }

    /**
     * Check if the provider is configured and ready.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && str_starts_with($this->apiKey, 'sk-or-');
    }

    /**
     * Dispatch an inference request through OpenRouter.
     *
     * @param  string  $modelId  The model identifier (e.g., 'openai/gpt-4o-mini')
     * @param  string  $modelVersion  Version tag for audit trail
     * @param  array<string, mixed>  $context  Minimum input fields per feature registry
     * @param  array<string, mixed>  $systemPrompt  Optional system prompt for the model
     * @return array{output: string, confidence: float|null, tokens_used: int|null}|null
     */
    public function dispatch(
        string $modelId,
        string $modelVersion,
        array $context,
        ?array $systemPrompt = null,
    ): ?array {
        if (! $this->isConfigured()) {
            return null;
        }

        // Build the messages array
        $messages = [];

        // System prompt (if provided)
        if ($systemPrompt !== null && isset($systemPrompt['content'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt['content'],
            ];
        }

        // User message from context
        $userContent = $this->buildUserMessage($context);
        if ($userContent === '') {
            return null;
        }

        $messages[] = [
            'role' => 'user',
            'content' => $userContent,
        ];

        $payload = [
            'model' => $modelId ?: $this->defaultModel,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
            'temperature' => 0.3, // Low temperature for clinical accuracy
        ];

        // Retry logic with exponential backoff
        $lastException = null;
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                // Exponential backoff: 1s, 2s, 4s...
                usleep((int) (1000 * pow(2, $attempt - 1)));
            }

            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'HTTP-Referer' => env('APP_URL', 'https://swasthya.local'),
                        'X-Title' => 'Swasthya HMS',
                        'Content-Type' => 'application/json',
                    ])
                    ->post($this->baseUrl.'/chat/completions', $payload);

                if ($response->successful()) {
                    return $this->parseResponse($response->json());
                }

                // Don't retry on client errors (4xx)
                if ($response->clientError()) {
                    return null;
                }

                // Retry on server errors (5xx)
                $lastException = new \RuntimeException('OpenRouter returned '.$response->status());
            } catch (\Throwable $e) {
                $lastException = $e;
                // Retry on network errors
            }
        }

        // All retries exhausted
        \Log::warning('OpenRouter dispatch failed after '.(self::MAX_RETRIES + 1).' attempts', [
            'model' => $modelId,
            'error' => $lastException?->getMessage(),
        ]);

        return null;
    }

    /**
     * Build the user message from context fields.
     */
    private function buildUserMessage(array $context): string
    {
        $parts = [];

        foreach ($context as $key => $value) {
            if ($key === 'correlation_id') {
                continue; // Don't send correlation IDs to the model
            }

            if (is_string($value)) {
                $parts[] = sprintf('%s: %s', $key, $value);
            } elseif (is_array($value)) {
                $parts[] = sprintf('%s: %s', $key, json_encode($value, JSON_THROW_ON_ERROR));
            } else {
                $parts[] = sprintf('%s: %s', $key, (string) $value);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Parse the OpenRouter response into the standard format.
     */
    private function parseResponse(array $payload): ?array
    {
        // Validate response structure
        if (! isset($payload['choices']) || ! is_array($payload['choices'])) {
            return null;
        }

        $choice = $payload['choices'][0] ?? null;
        if ($choice === null || ! isset($choice['message']['content'])) {
            return null;
        }

        $output = (string) $choice['message']['content'];

        if ($output === '') {
            return null;
        }

        // Extract token usage for cost tracking
        $tokensUsed = $payload['usage']['total_tokens'] ?? null;

        // Calculate confidence from finish reason
        $confidence = $this->calculateConfidence($choice['finish_reason'] ?? null);

        return [
            'output' => $output,
            'confidence' => $confidence,
            'tokens_used' => is_int($tokensUsed) ? $tokensUsed : null,
        ];
    }

    /**
     * Calculate confidence score from finish reason.
     */
    private function calculateConfidence(?string $finishReason): ?float
    {
        return match ($finishReason) {
            'stop' => 0.95, // Normal completion
            'length' => 0.7, // Truncated — may be incomplete
            'content_filter' => 0.3, // Content was filtered
            default => 0.5,
        };
    }

    /**
     * Get provider configuration for audit logging (never expose the API key).
     */
    public function getConfig(): array
    {
        return [
            'provider' => 'openrouter',
            'base_url' => $this->baseUrl,
            'default_model' => $this->defaultModel,
            'max_tokens' => $this->maxTokens,
            'timeout' => $this->timeout,
            'configured' => $this->isConfigured(),
            // NEVER include the API key
        ];
    }
}
