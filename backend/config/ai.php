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
 */
return [

    'approved_models' => json_decode((string) env('AI_APPROVED_MODEL_ENDPOINTS', '{}'), true) ?: [],

];
