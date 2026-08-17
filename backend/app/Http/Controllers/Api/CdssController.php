<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cdss\CheckPrescriptionRequest;
use App\Http\Requests\Cdss\EvaluatePathwayRequest;
use App\Http\Requests\Cdss\OverrideCheckRequest;
use App\Http\Requests\Cdss\StoreCdssRuleRequest;
use App\Models\CdssCheckResult;
use App\Models\CdssRule;
use App\Models\Patient;
use App\Services\CdssService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clinical Decision Support (ROADMAP Phase 21, CLINICAL_SAFETY.md §6, §9).
 *
 * Knowledge-base-driven checks (allergy, drug-drug interaction, dose),
 * a versioned rules surface, and pathway suggestions — the propose →
 * human-decides shape. The check mutates nothing clinical; it persists the
 * raised alerts (cdss_check_results) and overrides are reason-captured and
 * audited. Degradation is LOUD: when the knowledge base cannot be
 * evaluated, the envelope reports it — care is never blocked
 * (AI_RULES.md §17).
 */
final class CdssController extends Controller
{
    public function __construct(
        private readonly CdssService $cdss,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET cdss/rules — the tenant's versioned knowledge base.
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $facilityId = $context->facilityId();

        $rules = CdssRule::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', (string) $facilityId))
            ->orderBy('rule_type')
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->map(static fn (CdssRule $rule): array => self::presentRule($rule))
            ->all();

        return Envelope::success(data: $rules, request: $request);
    }

    /**
     * POST cdss/rules — store a NEW VERSION of a rule (draft). Activation
     * supersedes the prior active version.
     */
    public function store(StoreCdssRuleRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $rule = $this->cdss->storeRule([
            ...$request->validated(),
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => (string) $context->facilityId(),
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'cdss_rule.stored',
            'cdss_rule',
            $rule->getKey(),
            ['ruleType' => $rule->rule_type, 'code' => $rule->code, 'version' => $rule->version, 'status' => $rule->status],
            $request,
        );

        return Envelope::success(data: self::presentRule($rule), status: 201, request: $request);
    }

    /**
     * POST cdss/rules/{cdssRule}/activate — draft → active (CAS). The prior
     * active version of the same code is superseded.
     */
    public function activate(Request $request, CdssRule $cdssRule): JsonResponse
    {
        AccessCheck::scoped($cdssRule, write: true);

        $rule = $this->cdss->activateRule($cdssRule, (string) $this->currentStaffId($request));

        $this->audit->record(
            'cdss_rule.activated',
            'cdss_rule',
            $rule->getKey(),
            ['ruleType' => $rule->rule_type, 'code' => $rule->code, 'version' => $rule->version],
            $request,
        );

        return Envelope::success(data: self::presentRule($rule), request: $request);
    }

    /**
     * POST cdss/checks/prescription — run the knowledge checks against a
     * proposed prescription. Returns the raised alerts; `meta.degraded`
     * is true when the knowledge base could not be evaluated (fail open,
     * loudly — care is never blocked).
     */
    public function checkPrescription(CheckPrescriptionRequest $request): JsonResponse
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

        AccessCheck::scoped($patient, write: false);

        $result = $this->cdss->checkPrescription($patient, $request->validated('lines'));

        $this->audit->record(
            $result['degraded'] ? 'cdss_check.degraded' : 'cdss_check.run',
            'cdss_check_result',
            null,
            [
                'patientId' => $patient->getKey(),
                'lineCount' => count($request->validated('lines')),
                'alertCount' => count($result['alerts']),
                'degraded' => $result['degraded'],
            ],
            $request,
        );

        return Envelope::success(
            data: $result['alerts'],
            meta: ['degraded' => $result['degraded']],
            request: $request,
        );
    }

    /**
     * POST cdss/checks/{cdssCheckResult}/override — record a prescriber
     * override with a MANDATORY reason (audited).
     */
    public function override(OverrideCheckRequest $request, CdssCheckResult $cdssCheckResult): JsonResponse
    {
        AccessCheck::scoped($cdssCheckResult, write: true);
        $staffId = (string) $this->currentStaffId($request);

        $result = $this->cdss->overrideResult($cdssCheckResult, $request->validated('reason'), $staffId);

        $this->audit->record(
            'cdss_check.overridden',
            'cdss_check_result',
            $result->getKey(),
            ['patientId' => $result->patient_id, 'alertType' => $result->alert_type, 'ruleCode' => $result->rule_code, 'severity' => $result->severity],
            $request,
        );

        return Envelope::success(data: self::presentCheck($result), request: $request);
    }

    /**
     * POST cdss/pathways/{cdssRule}/evaluate — evaluate a pathway rule
     * against a patient context. Advisory suggestions only; nothing is
     * applied to the record.
     */
    public function evaluatePathway(EvaluatePathwayRequest $request, CdssRule $cdssRule): JsonResponse
    {
        AccessCheck::scoped($cdssRule, write: false);

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

        $suggestions = $this->cdss->evaluatePathway($patient, $cdssRule, $request->validated('context'));

        $this->audit->record(
            'cdss_pathway.evaluated',
            'cdss_rule',
            $cdssRule->getKey(),
            ['patientId' => $patient->getKey(), 'code' => $cdssRule->code, 'version' => $cdssRule->version, 'suggestionCount' => count($suggestions)],
            $request,
        );

        return Envelope::success(data: $suggestions, request: $request);
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
    private static function presentRule(CdssRule $rule): array
    {
        return [
            'id' => $rule->getKey(),
            'ruleType' => $rule->rule_type,
            'code' => $rule->code,
            'name' => $rule->name,
            'severity' => $rule->severity,
            'version' => $rule->version,
            'status' => $rule->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentCheck(CdssCheckResult $result): array
    {
        return [
            'id' => $result->getKey(),
            'alertType' => $result->alert_type,
            'severity' => $result->severity,
            'code' => $result->rule_code,
            'ruleVersion' => $result->rule_version,
            'status' => $result->status,
        ];
    }
}
