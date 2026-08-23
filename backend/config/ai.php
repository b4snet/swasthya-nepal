<?php

/**
 * AI governance configuration (AI_RULES.md §14, §17, §19).
 *
 * approved_models — the transport-level allowlist for the inference
 * boundary (AiInferenceGateway). Read from the AI_APPROVED_MODEL_ENDPOINTS
 * environment variable, a JSON map of model_id → {endpoint, version}, e.g.:
 *
 *   AI_APPROVED_MODEL_ENDPOINTS={"note-draft-v3":{"endpoint":"https://inference.internal.swasthya.local/v1/generate","version":"2026-07-15"}}
 *
 * Endpoints MUST be HTTPS and MUST be within the platform boundary
 * (egress allowlist discipline, INTEROPERABILITY.md §14). With no value
 * configured — the current state — NO model is approved and the inference
 * boundary never transmits (AI_RULES.md §14: no patient data to unapproved
 * models, period).
 *
 * OpenRouter support: models with provider="openrouter" are dispatched
 * through the OpenRouterProvider adapter. The API key is loaded from
 * OPENROUTER_API_KEY env var and NEVER leaves the server.
 *
 * Example OpenRouter model config:
 *   AI_APPROVED_MODEL_ENDPOINTS={"openai/gpt-4o-mini":{"provider":"openrouter","version":"2026-01-01","system_prompt":{"content":"You are a clinical documentation assistant."}}}
 */
return [

    'approved_models' => json_decode((string) env('AI_APPROVED_MODEL_ENDPOINTS', '{}'), true) ?: [],

    // OpenRouter configuration (server-side only)
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY', ''),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'default_model' => env('OPENROUTER_DEFAULT_MODEL', 'openai/gpt-4o-mini'),
        'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 1024),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 15),
    ],

    // Cost control: per-feature token limits
    'cost_control' => [
        'max_tokens_per_request' => (int) env('AI_MAX_TOKENS_PER_REQUEST', 2048),
        'max_requests_per_hour' => (int) env('AI_MAX_REQUESTS_PER_HOUR', 100),
        'max_tokens_per_day' => (int) env('AI_MAX_TOKENS_PER_DAY', 100000),
    ],

];
