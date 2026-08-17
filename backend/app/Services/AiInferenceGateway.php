<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Phase 21 — The inference BOUNDARY (AI_RULES.md §13–14, §17;
 * ARCHITECTURE.md §28.5).
 *
 * Transport-level gate: inference is dispatched ONLY to model endpoints that
 * are present in the approved allowlist (`config('ai.approved_models')`,
 * from the AI_APPROVED_MODEL_ENDPOINTS env — a JSON map of
 * model_id → {endpoint, version}). The feature-level gates (registered,
 * enabled, model_approved, evaluation evidence) live in AiService; this
 * class is the LAST line — even a fully approved feature cannot transmit to
 * an endpoint that is not allowlisted. Endpoints must be HTTPS (egress
 * discipline; INTEROPERABILITY.md §14).
 *
 * With no allowlist configured (the current state — no model is approved),
 * dispatch ALWAYS returns null: no data ever leaves the platform to an
 * unapproved model. Callers treat null as "inference unavailable" and fail
 * open loudly (AI_RULES.md §17).
 */
final class AiInferenceGateway
{
    /**
     * @param  array<string, mixed>  $context  the MINIMUM input fields the
     *                                         feature's registry entry permits
     * @return array{output: string, confidence: float|null}|null
     */
    public function dispatch(string $modelId, string $modelVersion, array $context): ?array
    {
        $approved = config('ai.approved_models', []);

        if (! is_array($approved) || ! isset($approved[$modelId])) {
            // No approved endpoint for this model — NOTHING is transmitted.
            return null;
        }

        $endpoint = $approved[$modelId]['endpoint'] ?? null;
        $version = $approved[$modelId]['version'] ?? null;

        if (! is_string($endpoint) || ! str_starts_with($endpoint, 'https://')) {
            return null;
        }

        if ($version !== null && (string) $version !== $modelVersion) {
            // The registered model version is not the approved version.
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post($endpoint, [
                    'model' => $modelId,
                    'model_version' => $modelVersion,
                    'context' => $context,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();

            $output = is_array($payload) && is_string($payload['output'] ?? null) ? $payload['output'] : null;

            if ($output === null) {
                return null;
            }

            $confidence = isset($payload['confidence']) && is_numeric($payload['confidence'])
                ? (float) $payload['confidence']
                : null;

            return ['output' => $output, 'confidence' => $confidence];
        } catch (\Throwable) {
            // Degraded: inference unreachable — never block care on it.
            return null;
        }
    }
}
