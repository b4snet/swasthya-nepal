<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\CreateAiDraftRequest;
use App\Http\Requests\Ai\InvokeAiFeatureRequest;
use App\Http\Requests\Ai\SetAiFeatureSwitchRequest;
use App\Http\Requests\Ai\SignAiDraftRequest;
use App\Http\Requests\Ai\StoreAiFeatureRequest;
use App\Models\AiDraft;
use App\Models\AiFeature;
use App\Models\Patient;
use App\Services\AiService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Governed assistive AI (ROADMAP Phase 21, AI_RULES.md §1–§19).
 *
 * Every AI capability ships ONLY through its registry entry (tier, owner,
 * pinned model id/version, purpose/non-goals, min inputs, output schema,
 * confidence threshold, fallback, review cadence, audit class, evaluation
 * evidence); every feature has a KILL SWITCH (off by default) and can be
 * disabled independently; invocation degrades LOUDLY and never blocks; and
 * a draft reaches a record only through clinician sign-off. There is no
 * autonomous-action path and no data leaves the platform to an unapproved
 * model (the inference boundary's allowlist is the last gate).
 */
final class AiController extends Controller
{
    public function __construct(
        private readonly AiService $ai,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET ai/features — the registry.
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $facilityId = $context->facilityId();

        $features = AiFeature::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', (string) $facilityId))
            ->orderBy('function')
            ->get()
            ->map(static fn (AiFeature $feature): array => self::presentFeature($feature))
            ->all();

        return Envelope::success(data: $features, request: $request);
    }

    /**
     * POST ai/features — register a new AI function (kill switch OFF by
     * default; activation requires evaluation evidence + approved model).
     */
    public function store(StoreAiFeatureRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $feature = $this->ai->registerFeature([
            ...$request->validated(),
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => (string) $context->facilityId(),
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'ai_feature.registered',
            'ai_feature',
            $feature->getKey(),
            ['function' => $feature->function, 'tier' => $feature->tier, 'modelId' => $feature->model_id, 'modelVersion' => $feature->model_version],
            $request,
        );

        return Envelope::success(data: self::presentFeature($feature), status: 201, request: $request);
    }

    /**
     * POST ai/features/{aiFeature}/activate — registered → active (CAS).
     * Requires evaluation evidence + approved model. The kill switch stays
     * OFF until explicitly enabled.
     */
    public function activate(Request $request, AiFeature $aiFeature): JsonResponse
    {
        AccessCheck::scoped($aiFeature, write: true);

        $feature = $this->ai->activateFeature($aiFeature, (string) $this->currentStaffId($request));

        $this->audit->record(
            'ai_feature.activated',
            'ai_feature',
            $feature->getKey(),
            ['function' => $feature->function, 'tier' => $feature->tier, 'modelId' => $feature->model_id],
            $request,
        );

        return Envelope::success(data: self::presentFeature($feature), request: $request);
    }

    /**
     * PATCH ai/features/{aiFeature}/switch — toggle the per-feature KILL
     * SWITCH (audited; disabling is always allowed).
     */
    public function switch(SetAiFeatureSwitchRequest $request, AiFeature $aiFeature): JsonResponse
    {
        AccessCheck::scoped($aiFeature, write: true);

        $feature = $this->ai->setEnabled($aiFeature, (bool) $request->validated('enabled'), (string) $this->currentStaffId($request));

        $this->audit->record(
            $feature->enabled ? 'ai_feature.enabled' : 'ai_feature.disabled',
            'ai_feature',
            $feature->getKey(),
            ['function' => $feature->function, 'enabled' => $feature->enabled],
            $request,
        );

        return Envelope::success(data: self::presentFeature($feature), request: $request);
    }

    /**
     * POST ai/features/{aiFeature}/invoke — run the full gate stack. When
     * the function is unavailable (not active / kill-switched / model not
     * approved / inference down / low confidence) the envelope degrades
     * LOUDLY with a 200 — care is never blocked (AI_RULES.md §17).
     */
    public function invoke(InvokeAiFeatureRequest $request, AiFeature $aiFeature): JsonResponse
    {
        AccessCheck::scoped($aiFeature, write: false);

        $correlationId = $request->header('X-Correlation-Id') ?? (string) str()->uuid();
        $invocation = $this->ai->invoke($aiFeature, $request->validated('context'), $correlationId);

        $this->audit->record(
            $invocation['available'] ? 'ai.invoked' : 'ai.invoke.degraded',
            'ai_feature',
            $aiFeature->getKey(),
            [
                'function' => $aiFeature->function,
                'tier' => $aiFeature->tier,
                'modelId' => $aiFeature->model_id,
                'modelVersion' => $aiFeature->model_version,
                'available' => $invocation['available'],
                'reason' => $invocation['reason'],
                'correlationId' => $correlationId,
            ],
            $request,
        );

        return Envelope::success(
            data: [
                'available' => $invocation['available'],
                'reason' => $invocation['reason'],
                'output' => $invocation['output'],
                'confidence' => $invocation['confidence'],
                'correlationId' => $correlationId,
            ],
            meta: ['degraded' => ! $invocation['available']],
            request: $request,
        );
    }

    /**
     * POST ai/drafts — create a grounded assistive draft (Tier 2). Requires
     * the full gate stack; when inference is unavailable the request is 503
     * (degraded loudly — the manual path remains fully available).
     */
    public function createDraft(CreateAiDraftRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $facilityId = $context->facilityId();

        $patient = Patient::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->find($request->validated('patientId'));

        if ($patient === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Patient not found.', 404);
        }

        if ($facilityId !== null && $patient->facility_id !== (string) $facilityId) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Patient not found.', 404);
        }

        $correlationId = $request->header('X-Correlation-Id') ?? (string) str()->uuid();

        $draft = $this->ai->createDraft($patient, $request->validated(), (string) $this->currentStaffId($request), $correlationId);

        $this->audit->record(
            'ai.draft.created',
            'ai_draft',
            $draft->getKey(),
            [
                'function' => $draft->function,
                'tier' => $draft->tier,
                'modelId' => $draft->model_id,
                'modelVersion' => $draft->model_version,
                'patientId' => $draft->patient_id,
                'correlationId' => $correlationId,
            ],
            $request,
        );

        return Envelope::success(data: self::presentDraft($draft), status: 201, request: $request);
    }

    /**
     * POST ai/drafts/{aiDraft}/sign — the clinician's review act:
     * `sign` (draft may enter the record) or `withdraw` (rejected). The
     * signer identity is recorded either way — review is real, never
     * nominal (AI_RULES.md §9).
     */
    public function sign(SignAiDraftRequest $request, AiDraft $aiDraft): JsonResponse
    {
        AccessCheck::scoped($aiDraft, write: true);
        $staffId = (string) $this->currentStaffId($request);

        $action = $request->validated('action');

        $draft = $action === 'sign'
            ? $this->ai->signDraft($aiDraft, $staffId)
            : $this->ai->withdrawDraft($aiDraft);

        $this->audit->record(
            $action === 'sign' ? 'ai.draft.signed' : 'ai.draft.withdrawn',
            'ai_draft',
            $draft->getKey(),
            [
                'function' => $draft->function,
                'tier' => $draft->tier,
                'modelId' => $draft->model_id,
                'modelVersion' => $draft->model_version,
                'patientId' => $draft->patient_id,
                'status' => $draft->status,
            ],
            $request,
        );

        return Envelope::success(data: self::presentDraft($draft), request: $request);
    }

    private function currentStaffId(Request $request): ?string
    {
        $context = TenantContext::current();

        return $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', (string) $context->facilityId())
            ->where('status', '!=', 'departed')
            ->first()?->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentFeature(AiFeature $feature): array
    {
        return [
            'id' => $feature->getKey(),
            'function' => $feature->function,
            'name' => $feature->name,
            'tier' => $feature->tier,
            'modelId' => $feature->model_id,
            'modelVersion' => $feature->model_version,
            'status' => $feature->status,
            'enabled' => $feature->enabled,
            'modelApproved' => $feature->model_approved,
            'evaluationRef' => $feature->evaluation_ref,
            'confidenceThreshold' => $feature->confidence_threshold,
            'fallbackMode' => $feature->fallback_mode,
            'reviewCadence' => $feature->review_cadence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentDraft(AiDraft $draft): array
    {
        return [
            'id' => $draft->getKey(),
            'function' => $draft->function,
            'tier' => $draft->tier,
            'modelId' => $draft->model_id,
            'modelVersion' => $draft->model_version,
            'patientId' => $draft->patient_id,
            'status' => $draft->status,
            'signerStaffId' => $draft->signer_staff_id,
            'signedAt' => $draft->signed_at?->toISOString(),
            'correlationId' => $draft->correlation_id,
        ];
    }
}
