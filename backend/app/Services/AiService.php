<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AiDraft;
use App\Models\AiFeature;
use App\Models\Patient;
use App\Support\ErrorCodes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Phase 21 — Governed assistive AI (AI_RULES.md §1–§19, MASTER_RULES.md
 * §33, §38).
 *
 * The governance spine in one sentence: an AI function does nothing until
 * its REGISTRY entry is complete (tier, owner, pinned model id/version,
 * purpose/non-goals, min inputs, output schema, confidence threshold,
 * fallback, review cadence, audit class, evaluation evidence), its KILL
 * SWITCH is on, its model is APPROVED, and the inference boundary's
 * allowlist permits the endpoint — and even then its output is a DRAFT that
 * reaches a record only when a clinician SIGNS it. There is NO autonomous
 * action path: every mutation is an explicit human act (activate, switch,
 * sign, withdraw), every failure degrades loudly and blocks nothing.
 *
 * Data privacy (AI_RULES.md §14): the only fields sent to the boundary are
 * the MINIMUM inputs the registry entry permits — never a full record by
 * default; prompts/outputs/audit never carry other patients' data and are
 * tenant-scoped rows like everything else.
 */
final class AiService
{
    public function __construct(
        private readonly AiInferenceGateway $gateway,
    ) {}

    /**
     * Register a new AI function (registry entry). One entry per function
     * per facility (DB unique backstop); a duplicate is 409.
     *
     * @param  array<string, mixed>  $payload
     */
    public function registerFeature(array $payload): AiFeature
    {
        return $this->guardUnique(fn (): AiFeature => DB::transaction(fn (): AiFeature => AiFeature::query()->create([
            'tenant_id' => $payload['tenant_id'],
            'facility_id' => $payload['facility_id'],
            'function' => $payload['function'],
            'name' => $payload['name'],
            'tier' => $payload['tier'],
            'owner_staff_id' => $payload['ownerStaffId'] ?? null,
            'model_id' => $payload['modelId'],
            'model_version' => $payload['modelVersion'],
            'purpose' => $payload['purpose'],
            'non_goals' => $payload['nonGoals'] ?? null,
            'min_inputs' => $payload['minInputs'] ?? [],
            'output_schema' => $payload['outputSchema'] ?? [],
            'confidence_threshold' => $payload['confidenceThreshold'] ?? null,
            'fallback_mode' => $payload['fallbackMode'] ?? 'manual',
            'enabled' => false,
            'model_approved' => (bool) ($payload['modelApproved'] ?? false),
            'evaluation_ref' => $payload['evaluationRef'] ?? null,
            'review_cadence' => $payload['reviewCadence'] ?? 'quarterly',
            'audit_class' => $payload['auditClass'] ?? null,
            'status' => AiFeature::STATUS_REGISTERED,
            'lock_version' => 0,
            'created_by' => $payload['created_by'] ?? null,
        ])));
    }

    /**
     * registered → active (CAS). Activation REQUIRES evaluation evidence
     * (AI_RULES.md §12: evaluation precedes deployment) and an approved
     * model. The kill switch stays OFF — a feature is active but not
     * enabled until `setEnabled(true)`.
     */
    public function activateFeature(AiFeature $feature, string $actorId): AiFeature
    {
        if (empty($feature->evaluation_ref) || ! $feature->model_approved) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Activation requires evaluation evidence and an approved model (AI_RULES.md §12).',
                409,
            );
        }

        $affected = DB::transaction(function () use ($feature, $actorId): int {
            return AiFeature::query()
                ->whereKey($feature->getKey())
                ->where('status', AiFeature::STATUS_REGISTERED)
                ->where('lock_version', $feature->lock_version)
                ->update([
                    'status' => AiFeature::STATUS_ACTIVE,
                    'lock_version' => $feature->lock_version + 1,
                    'updated_by' => $actorId,
                ]);
        });

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The feature is not registered (current status: '.$feature->status.').',
                409,
            );
        }

        return $feature->refresh();
    }

    /**
     * Per-feature KILL SWITCH (CAS toggle). Disabling is the same audited
     * act as enabling — and disabling is always allowed (MASTER_RULES.md
     * §38).
     */
    public function setEnabled(AiFeature $feature, bool $enabled, string $actorId): AiFeature
    {
        $affected = DB::transaction(function () use ($feature, $enabled, $actorId): int {
            return AiFeature::query()
                ->whereKey($feature->getKey())
                ->where('lock_version', $feature->lock_version)
                ->update([
                    'enabled' => $enabled,
                    'lock_version' => $feature->lock_version + 1,
                    'updated_by' => $actorId,
                ]);
        });

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The feature changed concurrently (expected lock_version '.$feature->lock_version.').',
                409,
            );
        }

        return $feature->refresh();
    }

    /**
     * Invoke an AI function through the full gate stack. Returns
     * availability + output; the caller (controller) renders a LOUD
     * degraded envelope when unavailable — never a block (AI_RULES.md §17).
     *
     * @param  array<string, mixed>  $context
     * @return array{available: bool, reason: string|null, output: string|null, confidence: float|null}
     */
    public function invoke(AiFeature $feature, array $context, string $correlationId): array
    {
        if ($feature->status !== AiFeature::STATUS_ACTIVE) {
            return ['available' => false, 'reason' => 'feature_not_active', 'output' => null, 'confidence' => null];
        }

        if (! $feature->enabled) {
            return ['available' => false, 'reason' => 'feature_disabled', 'output' => null, 'confidence' => null];
        }

        if (! $feature->model_approved || empty($feature->evaluation_ref)) {
            return ['available' => false, 'reason' => 'model_not_approved', 'output' => null, 'confidence' => null];
        }

        // Privilege boundary (AI_RULES.md §13–14): send ONLY the minimum
        // input fields this feature's registry entry permits.
        $allowed = is_array($feature->min_inputs) ? $feature->min_inputs : [];
        $minContext = array_intersect_key($context, array_flip(array_map('strval', $allowed)));

        $result = $this->gateway->dispatch(
            $feature->model_id,
            $feature->model_version,
            array_merge($minContext, ['correlation_id' => $correlationId]),
        );

        if ($result === null) {
            return ['available' => false, 'reason' => 'inference_unavailable', 'output' => null, 'confidence' => null];
        }

        $confidence = $result['confidence'];

        if ($confidence !== null && $feature->confidence_threshold !== null
            && $confidence < (float) $feature->confidence_threshold) {
            // Below calibrated threshold — refuse, never present as reliable
            // (AI_RULES.md §16).
            return ['available' => false, 'reason' => 'low_confidence', 'output' => null, 'confidence' => $confidence];
        }

        return [
            'available' => true,
            'reason' => null,
            'output' => $result['output'],
            'confidence' => $confidence,
        ];
    }

    /**
     * Create an assistive DRAFT (Tier 2). The draft is grounded in the
     * source refs the caller passes (provenance — AI_RULES.md §2, §15) and
     * pinned to the producing model id/version. It enters a record ONLY
     * after signDraft(). If inference is unavailable, the request fails
     * with SERVICE_UNAVAILABLE — care continues through the manual path
     * (fail open).
     *
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(Patient $patient, array $payload, string $actorId, string $correlationId): AiDraft
    {
        $feature = AiFeature::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('facility_id', $patient->facility_id)
            ->where('function', $payload['function'])
            ->first();

        if ($feature === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'No AI feature is registered for this function.', 404);
        }

        $invocation = $this->invoke($feature, $payload['context'] ?? [], $correlationId);

        if (! $invocation['available']) {
            throw new ApiException(
                ErrorCodes::SERVICE_UNAVAILABLE,
                'AI assistance unavailable ('.$invocation['reason'].') — the manual path remains fully available.',
                503,
            );
        }

        return AiDraft::query()->create([
            'tenant_id' => $patient->tenant_id,
            'facility_id' => $patient->facility_id,
            'patient_id' => $patient->getKey(),
            'encounter_id' => $payload['encounter_id'] ?? null,
            'function' => $feature->function,
            'tier' => $feature->tier,
            'model_id' => $feature->model_id,
            'model_version' => $feature->model_version,
            'source_refs' => $payload['sourceRefs'] ?? [],
            'output' => $invocation['output'],
            'confidence' => $invocation['confidence'],
            'status' => AiDraft::STATUS_DRAFT,
            'correlation_id' => $correlationId,
            'lock_version' => 0,
            'created_by' => $actorId,
        ]);
    }

    /**
     * draft → signed (CAS). The clinician's sign-off is the ONLY path for a
     * draft to enter a record — the accountable human act (AI_RULES.md §9).
     */
    public function signDraft(AiDraft $draft, string $signerId): AiDraft
    {
        $affected = DB::transaction(function () use ($draft, $signerId): int {
            return AiDraft::query()
                ->whereKey($draft->getKey())
                ->where('status', AiDraft::STATUS_DRAFT)
                ->where('lock_version', $draft->lock_version)
                ->update([
                    'status' => AiDraft::STATUS_SIGNED,
                    'lock_version' => $draft->lock_version + 1,
                    'signer_staff_id' => $signerId,
                    'signed_at' => now(),
                ]);
        });

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The draft is not awaiting sign-off (current status: '.$draft->status.').',
                409,
            );
        }

        return $draft->refresh();
    }

    /**
     * draft → withdrawn (CAS): the clinician reviewed and rejected the
     * draft. It never reaches the record.
     */
    public function withdrawDraft(AiDraft $draft): AiDraft
    {
        $affected = DB::transaction(function () use ($draft): int {
            return AiDraft::query()
                ->whereKey($draft->getKey())
                ->where('status', AiDraft::STATUS_DRAFT)
                ->where('lock_version', $draft->lock_version)
                ->update([
                    'status' => AiDraft::STATUS_WITHDRAWN,
                    'lock_version' => $draft->lock_version + 1,
                ]);
        });

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The draft is not awaiting review (current status: '.$draft->status.').',
                409,
            );
        }

        return $draft->refresh();
    }

    private function guardUnique(callable $create)
    {
        try {
            return $create();
        } catch (QueryException $e) {
            $pdo = $e->getPrevious();

            if ($pdo instanceof PDOException && str_starts_with((string) $pdo->getCode(), '23505')) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'A feature with this function name is already registered for the facility.',
                    409,
                );
            }

            throw $e;
        }
    }
}
