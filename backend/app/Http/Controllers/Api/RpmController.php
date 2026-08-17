<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rpm\AcknowledgeAlertRequest;
use App\Http\Requests\Rpm\IngestReadingRequest;
use App\Http\Requests\Rpm\StoreDeviceRequest;
use App\Http\Requests\Rpm\UpdateDeviceStatusRequest;
use App\Models\Patient;
use App\Models\RpmAlert;
use App\Models\RpmDevice;
use App\Models\RpmReading;
use App\Services\RpmService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remote Patient Monitoring (ROADMAP Phase 20, PRODUCT_REQUIREMENTS §6.20
 * device feeds): device enrollment, VALIDATED and clearly LABELED
 * ingestion, personalized thresholds, human-mediated alerts with
 * acknowledgment, and the monitoring views.
 *
 * The clinical spine stays in RpmService; this controller is the thin
 * auth/scoping/envelope adapter (the established pattern). The ingestion
 * surface is batch + idempotent (adapter retries are no-ops). Responses
 * carry the labeled clinical data to AUTHORIZED roles; audit payloads carry
 * facts and ids only (values never reach the audit trail).
 */
final class RpmController extends Controller
{
    public function __construct(
        private readonly RpmService $rpm,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST rpm/devices — enroll a device adapter (requires the patient's
     * ACTIVE device_monitoring consent).
     */
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $staffId = $this->currentStaffId($request);

        /** @var Patient|null $patient */
        $patient = Patient::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->find($request->validated('patientId'));

        if ($patient === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Patient not found.', 404);
        }

        AccessCheck::scoped($patient, write: true);

        $device = $this->rpm->enrollDevice($patient, $request->validated(), (string) $staffId);

        $this->audit->record(
            'rpm_device.registered',
            'rpm_device',
            $device->getKey(),
            ['patientId' => $device->patient_id, 'readingType' => $device->reading_type, 'status' => $device->status],
            $request,
        );

        return Envelope::success(data: self::presentDevice($device), status: 201, request: $request);
    }

    /**
     * GET rpm/devices — the tenant's enrolled devices (monitoring view).
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $facilityId = $context->facilityId();

        $devices = RpmDevice::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', (string) $facilityId))
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(static fn (RpmDevice $d): array => self::presentDevice($d))
            ->all();

        return Envelope::success(data: $devices, request: $request);
    }

    /**
     * PATCH rpm/devices/{device} — activate/disable (CAS, audited).
     */
    public function update(UpdateDeviceStatusRequest $request, RpmDevice $rpmDevice): JsonResponse
    {
        AccessCheck::scoped($rpmDevice, write: true);
        $staffId = $this->currentStaffId($request);

        $device = $this->rpm->setDeviceStatus($rpmDevice, $request->validated('status'), (string) $staffId);

        $this->audit->record(
            $device->status === RpmDevice::STATUS_ACTIVE ? 'rpm_device.activated' : 'rpm_device.disabled',
            'rpm_device',
            $device->getKey(),
            ['patientId' => $device->patient_id, 'status' => $device->status],
            $request,
        );

        return Envelope::success(data: self::presentDevice($device), request: $request);
    }

    /**
     * POST rpm/readings — device-adapter batch ingestion (≤100, idempotent
     * by ingestionId). Readings are validated and LABELED by the service.
     */
    public function ingest(IngestReadingRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $tenantId = (string) $context->tenantId();
        $facilityId = $context->facilityId();
        $staffId = $this->currentStaffId($request);

        // Facility isolation: a facility-scoped ingest token may only reach
        // devices in its own facility (mirrors the RLS invisibility of
        // another facility's rows).
        $unknown = collect($request->validated('readings'))
            ->first(fn (array $r): bool => RpmDevice::query()
                ->where('tenant_id', $tenantId)
                ->where('device_identifier', (string) $r['deviceIdentifier'])
                ->when($facilityId !== null, fn ($q) => $q->where('facility_id', (string) $facilityId))
                ->doesntExist());

        if ($unknown !== null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Unknown device identifier for this tenant.', 404);
        }

        $readings = $this->rpm->ingest($request->validated('readings'), $tenantId, $staffId);

        $this->audit->record(
            'rpm_reading.ingested',
            'rpm_reading',
            null,
            [
                'count' => count($readings),
                'validated' => collect($readings)->where('validation_status', RpmReading::VALIDATED)->count(),
                'flagged' => collect($readings)->where('validation_status', RpmReading::FLAGGED)->count(),
                'rejected' => collect($readings)->where('validation_status', RpmReading::REJECTED)->count(),
            ],
            $request,
        );

        return Envelope::success(
            data: collect($readings)->map(static fn (RpmReading $r): array => self::presentReading($r))->all(),
            request: $request,
        );
    }

    /**
     * GET rpm/patients/{patient}/readings — the monitoring series for a
     * patient (authorized clinical view).
     */
    public function readings(Request $request, Patient $patient): JsonResponse
    {
        $context = TenantContext::current();
        $facilityId = $context->facilityId();

        if ($patient->tenant_id !== (string) $context->tenantId()) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Patient not found.', 404);
        }

        if ($facilityId !== null && $patient->facility_id !== (string) $facilityId) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Patient not found.', 404);
        }

        $type = $request->query('type');
        $since = $request->query('since');

        $readings = RpmReading::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('patient_id', $patient->getKey())
            ->when(is_string($type) && $type !== '', fn ($q) => $q->where('reading_type', $type))
            ->when(is_string($since) && $since !== '', fn ($q) => $q->where('measured_at', '>=', $since))
            ->orderByDesc('measured_at')
            ->limit(200)
            ->get()
            ->map(static fn (RpmReading $r): array => self::presentReading($r))
            ->all();

        return Envelope::success(data: $readings, request: $request);
    }

    /**
     * GET rpm/alerts — the tenant's alerts, open first, then acknowledged.
     */
    public function alerts(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $facilityId = $context->facilityId();
        $status = $request->query('status');

        $alerts = RpmAlert::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', (string) $facilityId))
            ->when(
                is_string($status) && in_array($status, [RpmAlert::STATUS_OPEN, RpmAlert::STATUS_ACKNOWLEDGED, RpmAlert::STATUS_RESOLVED], true),
                fn ($q) => $q->where('status', $status),
            )
            ->orderByRaw("case status when 'open' then 0 when 'acknowledged' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->map(static fn (RpmAlert $a): array => self::presentAlert($a))
            ->all();

        return Envelope::success(data: $alerts, request: $request);
    }

    /**
     * POST rpm/alerts/{alert}/acknowledge — human-mediated acknowledgment
     * (WHO/WHEN/WHY; CAS open → acknowledged).
     */
    public function acknowledge(AcknowledgeAlertRequest $request, RpmAlert $rpmAlert): JsonResponse
    {
        AccessCheck::scoped($rpmAlert, write: true);
        $staffId = $this->currentStaffId($request);

        $alert = $this->rpm->acknowledgeAlert($rpmAlert, (string) $staffId, $request->validated('note'));

        $this->audit->record(
            'rpm_alert.acknowledged',
            'rpm_alert',
            $alert->getKey(),
            ['patientId' => $alert->patient_id, 'parameter' => $alert->parameter, 'status' => $alert->status],
            $request,
        );

        return Envelope::success(data: self::presentAlert($alert), request: $request);
    }

    /**
     * POST rpm/alerts/{alert}/resolve — close after action (CAS
     * acknowledged → resolved).
     */
    public function resolve(Request $request, RpmAlert $rpmAlert): JsonResponse
    {
        AccessCheck::scoped($rpmAlert, write: true);
        $staffId = $this->currentStaffId($request);

        $alert = $this->rpm->resolveAlert($rpmAlert, (string) $staffId);

        $this->audit->record(
            'rpm_alert.resolved',
            'rpm_alert',
            $alert->getKey(),
            ['patientId' => $alert->patient_id, 'parameter' => $alert->parameter, 'status' => $alert->status],
            $request,
        );

        return Envelope::success(data: self::presentAlert($alert), request: $request);
    }

    // ───────────────────────── presentation ─────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function presentDevice(RpmDevice $device): array
    {
        return [
            'id' => $device->getKey(),
            'patientId' => $device->patient_id,
            'deviceIdentifier' => $device->device_identifier,
            'model' => $device->model,
            'manufacturer' => $device->manufacturer,
            'readingType' => $device->reading_type,
            'status' => $device->status,
            'settings' => $device->settings,
            'adapter' => $device->adapter,
            'lastSeenAt' => $device->last_seen_at?->toIso8601String(),
            'lockVersion' => $device->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentReading(RpmReading $reading): array
    {
        return [
            'id' => $reading->getKey(),
            'patientId' => $reading->patient_id,
            'deviceId' => $reading->device_id,
            'readingType' => $reading->reading_type,
            'value' => $reading->value,
            'units' => $reading->units,
            'measuredAt' => $reading->measured_at->toIso8601String(),
            'receivedAt' => $reading->received_at->toIso8601String(),
            'validationStatus' => $reading->validation_status,
            'validationReason' => $reading->validation_reason,
            'provenance' => $reading->provenance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentAlert(RpmAlert $alert): array
    {
        return [
            'id' => $alert->getKey(),
            'patientId' => $alert->patient_id,
            'deviceId' => $alert->device_id,
            'readingId' => $alert->reading_id,
            'alertType' => $alert->alert_type,
            'parameter' => $alert->parameter,
            'thresholdValue' => $alert->threshold_value,
            'observedValue' => $alert->observed_value,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'acknowledgedBy' => $alert->acknowledged_by,
            'acknowledgedAt' => $alert->acknowledged_at?->toIso8601String(),
            'resolvedBy' => $alert->resolved_by,
            'resolvedAt' => $alert->resolved_at?->toIso8601String(),
            'lockVersion' => $alert->lock_version,
        ];
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
}
