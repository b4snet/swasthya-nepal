<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BloodBank\CompleteTransfusionRequest;
use App\Http\Requests\BloodBank\DiscardBloodUnitRequest;
use App\Http\Requests\BloodBank\IssueBloodUnitRequest;
use App\Http\Requests\BloodBank\PerformCrossmatchRequest;
use App\Http\Requests\BloodBank\RecordDonationRequest;
use App\Http\Requests\BloodBank\ReportReactionRequest;
use App\Http\Requests\BloodBank\RequestCrossmatchRequest;
use App\Http\Requests\BloodBank\StartTransfusionRequest;
use App\Http\Requests\BloodBank\StopTransfusionRequest;
use App\Http\Requests\BloodBank\StoreDonorRequest;
use App\Http\Requests\BloodBank\TestBloodUnitRequest;
use App\Models\BloodUnit;
use App\Models\Crossmatch;
use App\Models\Donor;
use App\Models\Transfusion;
use App\Services\OtIcuBloodBankService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3 slice 20 — Blood Bank (PRODUCT_REQUIREMENTS §6.12, DATABASE.md
 * §3.50): donor management, componentized units with expiry, testing,
 * compatibility + crossmatch, issue (expired or untested units are NEVER
 * issuable), transfusion with DUAL verification, reaction reporting, and
 * discard. A wrong unit is a life-threatening error — audit payloads carry
 * facts and ids only; donor names and DOB are never in payloads (protected
 * to the same standard as patient data).
 */
final class BloodBankController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OtIcuBloodBankService $blood,
    ) {}

    /**
     * POST donors — register a blood donor.
     */
    public function storeDonor(StoreDonorRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $donor = $this->blood->registerDonor(
            (string) $context->tenantId(),
            (string) $context->facilityId(),
            $request->validated('donorNumber'),
            $request->validated('fullName'),
            $request->validated('dateOfBirth'),
            $request->validated('sex'),
            $request->validated('bloodGroup'),
            $request->validated('rhFactor'),
            $request->validated('phone'),
            $request->validated('screening', []),
            $this->currentStaffId($context),
        );

        // Facts only — never the donor's name or DOB.
        $this->audit->record('donor.registered', 'donor', $donor->getKey(), [
            'donorNumber' => $donor->donor_number,
            'bloodGroup' => $donor->blood_group,
        ], $request);

        return Envelope::success(data: self::presentDonor($donor), status: 201, request: $request);
    }

    /**
     * GET donors — the donor registry within scope (personal data is RLS-
     * protected; the response is the clinical surface, not an export).
     */
    public function donors(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $donors = Donor::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderBy('donor_number')
            ->get()
            ->map(fn (Donor $donor): array => self::presentDonor($donor))
            ->values();

        return Envelope::success(data: $donors, request: $request);
    }

    /**
     * POST donors/{donor}/donations — record a donation and process it into
     * componentized blood units.
     */
    public function recordDonation(RecordDonationRequest $request, Donor $donor): JsonResponse
    {
        AccessCheck::scoped($donor, write: true);
        $context = TenantContext::current();

        $components = collect($request->validated('components', []))
            ->map(fn (array $component): array => [
                'component_type' => $component['componentType'],
                'expiry_days' => $component['expiryDays'] ?? 35,
            ])
            ->values()
            ->all();

        [$donation, $units] = $this->blood->recordDonation(
            $donor,
            $request->validated('phlebotomistStaffId'),
            $components,
            (int) $request->validated('volumeMl', 450),
            $request->validated('donatedAt') !== null ? $this->parseDate($request->validated('donatedAt')) : null,
            $this->currentStaffId($context),
        );

        $this->audit->record('donation.recorded', 'donation', $donation->getKey(), [
            'unitCount' => count($units),
        ], $request);

        return Envelope::success(data: [
            'donationId' => $donation->getKey(),
            'units' => collect($units)->map(fn (BloodUnit $unit): array => self::presentUnit($unit))->values(),
        ], status: 201, request: $request);
    }

    /**
     * POST blood-units/{unit}/test — test a quarantined unit (passing →
     * available; failing → discarded).
     */
    public function testBloodUnit(TestBloodUnitRequest $request, BloodUnit $bloodUnit): JsonResponse
    {
        AccessCheck::scoped($bloodUnit, write: true);
        $context = TenantContext::current();

        $tested = $this->blood->testBloodUnit(
            $bloodUnit,
            (string) $this->currentStaffId($context),
            $request->validated('testResults', []),
            (bool) $request->validated('suitable', true),
        );

        $this->audit->record('blood_unit.tested', 'blood_unit', $tested->getKey(), [
            'unitNumber' => $tested->unit_number,
            'suitable' => $tested->status !== BloodUnit::STATUS_DISCARDED,
        ], $request);

        return Envelope::success(data: self::presentUnit($tested), request: $request);
    }

    /**
     * POST blood-units/{unit}/crossmatch — request a crossmatch of the unit
     * against a patient.
     */
    public function requestCrossmatch(RequestCrossmatchRequest $request, BloodUnit $bloodUnit): JsonResponse
    {
        AccessCheck::scoped($bloodUnit, write: true);
        $context = TenantContext::current();

        $crossmatch = $this->blood->requestCrossmatch(
            $bloodUnit,
            $request->validated('patientId'),
            (string) $this->currentStaffId($context),
        );

        $this->audit->record('crossmatch.requested', 'crossmatch', $crossmatch->getKey(), [
            'unitId' => $crossmatch->blood_unit_id,
            'patientId' => $crossmatch->patient_id,
        ], $request);

        return Envelope::success(data: self::presentCrossmatch($crossmatch), status: 201, request: $request);
    }

    /**
     * POST crossmatches/{crossmatch}/perform — record the compatibility
     * check and set the result.
     */
    public function performCrossmatch(PerformCrossmatchRequest $request, Crossmatch $crossmatch): JsonResponse
    {
        AccessCheck::scoped($crossmatch, write: true);
        $context = TenantContext::current();

        $performed = $this->blood->performCrossmatch(
            $crossmatch,
            (string) $this->currentStaffId($context),
            $request->validated('patientBloodGroup'),
            $request->validated('patientRhFactor'),
            (bool) $request->validated('aboRhCompatible'),
            $request->validated('antibodyScreen', 'negative'),
            $request->validated('notes'),
        );

        $this->audit->record('crossmatch.performed', 'crossmatch', $performed->getKey(), [
            'result' => $performed->status,
        ], $request);

        return Envelope::success(data: self::presentCrossmatch($performed), request: $request);
    }

    /**
     * POST blood-units/{unit}/issue — issue a tested, unexpired unit to a
     * patient after a compatible crossmatch.
     */
    public function issueBloodUnit(IssueBloodUnitRequest $request, BloodUnit $bloodUnit): JsonResponse
    {
        AccessCheck::scoped($bloodUnit, write: true);
        $context = TenantContext::current();

        $issued = $this->blood->issueBloodUnit(
            $bloodUnit,
            $request->validated('patientId'),
            $this->currentStaffId($context),
        );

        $this->audit->record('blood_unit.issued', 'blood_unit', $issued->getKey(), [
            'unitNumber' => $issued->unit_number,
            'patientId' => $issued->issued_to_patient_id,
        ], $request);

        return Envelope::success(data: self::presentUnit($issued), request: $request);
    }

    /**
     * POST transfusions — start a transfusion of an issued unit with
     * positive identification.
     */
    public function startTransfusion(StartTransfusionRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $unit = BloodUnit::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('id', $request->validated('bloodUnitId'))
            ->first();

        if ($unit === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Blood unit not found.', 404);
        }

        $transfusion = $this->blood->startTransfusion(
            $unit,
            $request->validated('patientId'),
            $request->validated('crossmatchId'),
            (string) $this->currentStaffId($context),
            $request->validated('encounterId'),
            $request->validated('startedAt') !== null ? $this->parseDate($request->validated('startedAt')) : null,
        );

        $this->audit->record('transfusion.started', 'transfusion', $transfusion->getKey(), [
            'unitId' => $transfusion->blood_unit_id,
            'patientId' => $transfusion->patient_id,
        ], $request);

        return Envelope::success(data: self::presentTransfusion($transfusion), status: 201, request: $request);
    }

    /**
     * POST transfusions/{transfusion}/verify — DUAL verification: a
     * DIFFERENT staff member verifies the unit and patient identity.
     */
    public function verifyTransfusion(Request $httpRequest, Transfusion $transfusion): JsonResponse
    {
        AccessCheck::scoped($transfusion, write: true);
        $context = TenantContext::current();

        $verified = $this->blood->verifyTransfusion($transfusion, (string) $this->currentStaffId($context));

        $this->audit->record('transfusion.verified', 'transfusion', $verified->getKey(), [
            'unitId' => $verified->blood_unit_id,
            'patientId' => $verified->patient_id,
        ], $httpRequest);

        return Envelope::success(data: self::presentTransfusion($verified), request: $httpRequest);
    }

    /**
     * POST transfusions/{transfusion}/complete — complete a dual-verified
     * transfusion; the unit becomes transfused.
     */
    public function completeTransfusion(CompleteTransfusionRequest $request, Transfusion $transfusion): JsonResponse
    {
        AccessCheck::scoped($transfusion, write: true);
        $context = TenantContext::current();

        $completed = $this->blood->completeTransfusion(
            $transfusion,
            (string) $this->currentStaffId($context),
            (int) $request->validated('volumeTransfusedMl'),
            $request->validated('stoppedAt') !== null ? $this->parseDate($request->validated('stoppedAt')) : null,
        );

        $this->audit->record('transfusion.completed', 'transfusion', $completed->getKey(), [
            'unitId' => $completed->blood_unit_id,
            'volumeTransfusedMl' => $completed->volume_transfused_ml,
        ], $request);

        return Envelope::success(data: self::presentTransfusion($completed), request: $request);
    }

    /**
     * POST transfusions/{transfusion}/stop — stop a started transfusion
     * early.
     */
    public function stopTransfusion(StopTransfusionRequest $request, Transfusion $transfusion): JsonResponse
    {
        AccessCheck::scoped($transfusion, write: true);
        $context = TenantContext::current();

        $stopped = $this->blood->stopTransfusion(
            $transfusion,
            (string) $this->currentStaffId($context),
            $request->validated('volumeTransfusedMl') !== null ? (int) $request->validated('volumeTransfusedMl') : null,
            $request->validated('stoppedAt') !== null ? $this->parseDate($request->validated('stoppedAt')) : null,
        );

        $this->audit->record('transfusion.stopped', 'transfusion', $stopped->getKey(), [
            'unitId' => $stopped->blood_unit_id,
        ], $request);

        return Envelope::success(data: self::presentTransfusion($stopped), request: $request);
    }

    /**
     * POST transfusions/{transfusion}/reaction — report a transfusion
     * reaction.
     */
    public function reportReaction(ReportReactionRequest $request, Transfusion $transfusion): JsonResponse
    {
        AccessCheck::scoped($transfusion, write: true);
        $context = TenantContext::current();

        $report = $this->blood->reportReaction(
            $transfusion,
            (string) $this->currentStaffId($context),
            $request->validated('severity'),
            $request->validated('symptoms', []),
            $request->validated('actionTaken'),
            $request->validated('occurredAt') !== null ? $this->parseDate($request->validated('occurredAt')) : null,
        );

        $this->audit->record('transfusion.reaction_reported', 'transfusion', $transfusion->getKey(), [
            'severity' => $report->severity,
        ], $request);

        return Envelope::success(data: [
            'id' => $report->getKey(),
            'severity' => $report->severity,
            'status' => $report->status,
        ], status: 201, request: $request);
    }

    /**
     * POST blood-units/{unit}/discard — discard a unit with reason
     * (terminal).
     */
    public function discardBloodUnit(DiscardBloodUnitRequest $request, BloodUnit $bloodUnit): JsonResponse
    {
        AccessCheck::scoped($bloodUnit, write: true);
        $context = TenantContext::current();

        $discarded = $this->blood->discardBloodUnit(
            $bloodUnit,
            (string) $this->currentStaffId($context),
            $request->validated('reason'),
        );

        $this->audit->record('blood_unit.discarded', 'blood_unit', $discarded->getKey(), [
            'unitNumber' => $discarded->unit_number,
        ], $request);

        return Envelope::success(data: self::presentUnit($discarded), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentDonor(Donor $donor): array
    {
        return [
            'id' => $donor->getKey(),
            'donorNumber' => $donor->donor_number,
            'bloodGroup' => $donor->blood_group,
            'rhFactor' => $donor->rh_factor,
            'status' => $donor->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentUnit(BloodUnit $unit): array
    {
        return [
            'id' => $unit->getKey(),
            'unitNumber' => $unit->unit_number,
            'componentType' => $unit->component_type,
            'bloodGroup' => $unit->blood_group,
            'rhFactor' => $unit->rh_factor,
            'expiryAt' => $unit->expiry_at?->toIso8601String(),
            'tested' => $unit->tested,
            'status' => $unit->status,
            'issuedToPatientId' => $unit->issued_to_patient_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentCrossmatch(Crossmatch $crossmatch): array
    {
        return [
            'id' => $crossmatch->getKey(),
            'bloodUnitId' => $crossmatch->blood_unit_id,
            'patientId' => $crossmatch->patient_id,
            'status' => $crossmatch->status,
            'requestedAt' => $crossmatch->requested_at?->toIso8601String(),
            'crossmatchedAt' => $crossmatch->crossmatched_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentTransfusion(Transfusion $transfusion): array
    {
        return [
            'id' => $transfusion->getKey(),
            'bloodUnitId' => $transfusion->blood_unit_id,
            'patientId' => $transfusion->patient_id,
            'status' => $transfusion->status,
            'startedAt' => $transfusion->started_at?->toIso8601String(),
            'verifiedAt' => $transfusion->verified_at?->toIso8601String(),
            'stoppedAt' => $transfusion->stopped_at?->toIso8601String(),
            'volumeTransfusedMl' => $transfusion->volume_transfused_ml,
        ];
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
