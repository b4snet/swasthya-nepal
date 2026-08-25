<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Icu\AdmitToIcuRequest;
use App\Http\Requests\Icu\DocumentCareRequest;
use App\Http\Requests\Icu\RecordObservationRequest;
use App\Http\Requests\Icu\StoreIcuBedRequest;
use App\Http\Requests\Icu\TransferOutIcuRequest;
use App\Models\IcuAdmission;
use App\Models\IcuAlert;
use App\Models\IcuBed;
use App\Services\OtIcuBloodBankService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 20 — ICU / Critical Care (PRODUCT_REQUIREMENTS §6.11,
 * DATABASE.md §3.49): acuity-based bed assignment, high-frequency
 * observations with COMPUTED warning scores, alerts that MUST be
 * acknowledged (score escalations, threshold breaches, and MISSED
 * observations — a missed ICU observation is a patient-safety event by
 * design), critical-care documentation, and step-down/discharge with
 * handover. Audit payloads carry facts and ids only — never observation
 * values or note content.
 */
final class IcuController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OtIcuBloodBankService $icu,
    ) {}

    /**
     * GET icu-beds — the facility's ICU beds.
     */
    public function icuBeds(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $beds = IcuBed::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderBy('bed_code')
            ->get()
            ->map(fn (IcuBed $bed): array => [
                'id' => $bed->getKey(),
                'bedCode' => $bed->bed_code,
                'status' => $bed->status,
                'acuitySupported' => $bed->acuity_supported,
            ])
            ->values();

        return Envelope::success(data: $beds, request: $request);
    }

    /**
     * POST icu-beds — create an ICU bed.
     */
    public function storeIcuBed(StoreIcuBedRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $bed = $this->icu->createIcuBed(
            (string) $context->tenantId(),
            (string) $context->facilityId(),
            $request->validated('bedCode'),
            $request->validated('acuitySupported', 'level_3'),
            $request->validated('status', IcuBed::STATUS_AVAILABLE),
            $this->currentStaffId($context),
        );

        $this->audit->record('icu_bed.created', 'icu_bed', $bed->getKey(), [
            'bedCode' => $bed->bed_code,
        ], $request);

        return Envelope::success(data: [
            'id' => $bed->getKey(),
            'bedCode' => $bed->bed_code,
            'status' => $bed->status,
            'acuitySupported' => $bed->acuity_supported,
        ], status: 201, request: $request);
    }

    /**
     * POST icu-admissions — admit a patient with acuity-based bed
     * assignment.
     */
    public function admitToIcu(AdmitToIcuRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $admission = $this->icu->admitToIcu(
            (string) $context->tenantId(),
            (string) $context->facilityId(),
            $request->validated('patientId'),
            $request->validated('icuBedId'),
            $request->validated('source', 'ipd'),
            $request->validated('acuity', 'level_3'),
            (int) $request->validated('observationIntervalMinutes', 60),
            (string) $this->currentStaffId($context),
            $request->validated('admissionId'),
            $request->validated('handoverNotes'),
        );

        $this->audit->record('icu_admission.admitted', 'icu_admission', $admission->getKey(), [
            'patientId' => $admission->patient_id,
            'icuBedId' => $admission->icu_bed_id,
            'acuity' => $admission->acuity,
        ], $request);

        return Envelope::success(data: self::presentAdmission($admission), status: 201, request: $request);
    }

    /**
     * POST icu-admissions/{admission}/observations — record an observation
     * set; the warning score is computed and escalation alerts are opened.
     */
    public function recordObservation(RecordObservationRequest $request, IcuAdmission $icuAdmission): JsonResponse
    {
        AccessCheck::scoped($icuAdmission, write: true);
        $context = TenantContext::current();

        [$set, $score, $alerts] = $this->icu->recordObservation(
            $icuAdmission,
            (string) $this->currentStaffId($context),
            $request->validated('values', []),
            $request->validated('notes'),
            $request->validated('observedAt') !== null ? $this->parseDate($request->validated('observedAt')) : null,
        );

        $this->audit->record('icu_observation.recorded', 'icu_admission', $icuAdmission->getKey(), [
            'warningScore' => $score->score_total,
            'severity' => $score->severity,
            'alertsOpened' => count($alerts),
        ], $request);

        return Envelope::success(data: [
            'observationSetId' => $set->getKey(),
            'score' => [
                'total' => $score->score_total,
                'severity' => $score->severity,
                'breakdown' => $score->breakdown,
            ],
            'alerts' => collect($alerts)->map(fn (IcuAlert $alert): array => self::presentAlert($alert))->values(),
        ], status: 201, request: $request);
    }

    /**
     * POST icu-alerts/{alert}/acknowledge — acknowledge an open alert (WHO
     * saw it, WHEN).
     */
    public function acknowledgeAlert(Request $httpRequest, IcuAlert $icuAlert): JsonResponse
    {
        AccessCheck::scoped($icuAlert, write: true);
        $context = TenantContext::current();

        $acknowledged = $this->icu->acknowledgeAlert($icuAlert, (string) $this->currentStaffId($context));

        $this->audit->record('icu_alert.acknowledged', 'icu_alert', $acknowledged->getKey(), [
            'alertType' => $acknowledged->alert_type,
            'icuAdmissionId' => $acknowledged->icu_admission_id,
        ], $httpRequest);

        return Envelope::success(data: self::presentAlert($acknowledged), request: $httpRequest);
    }

    /**
     * POST icu-admissions/{admission}/notes — critical-care documentation.
     */
    public function documentCare(DocumentCareRequest $request, IcuAdmission $icuAdmission): JsonResponse
    {
        AccessCheck::scoped($icuAdmission, write: true);
        $context = TenantContext::current();

        $note = $this->icu->documentCare(
            $icuAdmission,
            $request->validated('noteType'),
            $request->validated('content'),
            (string) $this->currentStaffId($context),
            $request->validated('authoredAt') !== null ? $this->parseDate($request->validated('authoredAt')) : null,
        );

        $this->audit->record('icu_documentation.created', 'icu_admission', $icuAdmission->getKey(), [
            'noteType' => $note->note_type,
        ], $request);

        return Envelope::success(data: [
            'id' => $note->getKey(),
            'noteType' => $note->note_type,
            'authoredAt' => $note->authored_at?->toIso8601String(),
        ], status: 201, request: $request);
    }

    /**
     * POST icu-admissions/{admission}/transfer — step down / discharge with
     * handover; releases the ICU bed.
     */
    public function transferOut(TransferOutIcuRequest $request, IcuAdmission $icuAdmission): JsonResponse
    {
        AccessCheck::scoped($icuAdmission, write: true);
        $context = TenantContext::current();

        $transferred = $this->icu->transferOutOfIcu(
            $icuAdmission,
            (string) $this->currentStaffId($context),
            $request->validated('handoverNotes'),
        );

        $this->audit->record('icu_admission.transferred', 'icu_admission', $transferred->getKey(), [
            'patientId' => $transferred->patient_id,
            'icuBedId' => $transferred->icu_bed_id,
        ], $request);

        return Envelope::success(data: self::presentAdmission($transferred), request: $request);
    }

    /**
     * GET icu-admissions/{admission} — the admission with recent scores and
     * open alerts.
     */
    public function showAdmission(Request $request, IcuAdmission $icuAdmission): JsonResponse
    {
        AccessCheck::scoped($icuAdmission, write: false);
        $scores = DB::table('warning_scores')
            ->where('tenant_id', $icuAdmission->tenant_id)
            ->where('icu_admission_id', $icuAdmission->getKey())
            ->orderByDesc('computed_at')
            ->limit(10)
            ->get()
            ->map(fn ($s): array => ['id' => $s->id, 'total' => $s->score_total, 'severity' => $s->severity, 'computedAt' => $s->computed_at])
            ->values();

        $openAlerts = IcuAlert::query()
            ->where('tenant_id', $icuAdmission->tenant_id)
            ->where('icu_admission_id', $icuAdmission->getKey())
            ->where('status', IcuAlert::STATUS_OPEN)
            ->orderBy('created_at')
            ->get()
            ->map(fn (IcuAlert $alert): array => self::presentAlert($alert))
            ->values();

        return Envelope::success(data: [
            'id' => $icuAdmission->getKey(),
            'patientId' => $icuAdmission->patient_id,
            'icuBedId' => $icuAdmission->icu_bed_id,
            'acuity' => $icuAdmission->acuity,
            'status' => $icuAdmission->status,
            'nextObservationDueAt' => $icuAdmission->next_observation_due_at?->toIso8601String(),
            'recentScores' => $scores,
            'openAlerts' => $openAlerts,
        ], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentAdmission(IcuAdmission $admission): array
    {
        return [
            'id' => $admission->getKey(),
            'patientId' => $admission->patient_id,
            'icuBedId' => $admission->icu_bed_id,
            'source' => $admission->source,
            'acuity' => $admission->acuity,
            'status' => $admission->status,
            'admittedAt' => $admission->admitted_at?->toIso8601String(),
            'nextObservationDueAt' => $admission->next_observation_due_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentAlert(IcuAlert $alert): array
    {
        return [
            'id' => $alert->getKey(),
            'alertType' => $alert->alert_type,
            'severity' => $alert->severity,
            'message' => $alert->message,
            'status' => $alert->status,
            'acknowledgedAt' => $alert->acknowledged_at?->toIso8601String(),
        ];
    }

    /**
     * GET icu-admissions — list ICU admissions within scope, with optional status filter.
     */
    public function admissions(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $status = $request->query('status');

        $query = IcuAdmission::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()));

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $admissions = $query
            ->orderByDesc('admitted_at')
            ->limit(200)
            ->get()
            ->map(fn (IcuAdmission $ad): array => self::presentAdmission($ad))
            ->values();

        return Envelope::success(data: $admissions, request: $request);
    }

    private function currentStaffId(TenantContext $context): ?string
    {
        return $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', (string) $context->facilityId())
            ->where('status', '!=', 'departed')
            ->first()?->getKey();
    }

    private function parseDate(mixed $value): CarbonInterface
    {
        return CarbonImmutable::parse($value);
    }
}
