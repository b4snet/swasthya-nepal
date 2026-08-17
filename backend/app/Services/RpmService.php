<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Consent;
use App\Models\Patient;
use App\Models\RpmAlert;
use App\Models\RpmDevice;
use App\Models\RpmReading;
use App\Support\ErrorCodes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Phase 3 slice 25 — Remote Patient Monitoring (ROADMAP Phase 20,
 * PRODUCT_REQUIREMENTS §6.20 device feeds, CLINICAL_SAFETY.md §7).
 *
 * The RPM contract in one sentence: DEVICE-sourced data is always VALIDATED
 * and clearly LABELED (validated | flagged | rejected — never silently
 * treated as verified), and every threshold breach escalates to a HUMAN who
 * acknowledges and resolves it — alert fatigue tuned by per-device
 * deduplication and cooldown.
 *
 * Consent gate: a device can only be ENROLLED while the patient has an
 * ACTIVE device_monitoring consent. Ingestion itself is keyed to an ACTIVE
 * enrolled device (the adapter credential is out of scope here — the
 * endpoint runs under the application's own auth).
 *
 * Idempotency: (tenant_id, ingestion_id) is unique — an adapter retry is a
 * no-op that returns the ORIGINAL reading and never re-alerts.
 *
 * Concurrency: device status changes and alert acknowledgments are CAS on
 * (status, lock_version) — exactly one winner under contention.
 */
final class RpmService
{
    /**
     * Plausibility bounds per reading type (defaults; a reading outside
     * these is REJECTED — structurally impossible, stored for provenance).
     *
     * @var array<string, array<string, array{0: float, 1: float}>>
     */
    private const PLAUSIBILITY = [
        'bp' => ['systolic' => [30, 300], 'diastolic' => [20, 200]],
        'pulse' => ['value' => [20, 250]],
        'temp' => ['value' => [30, 45]],
        'spo2' => ['value' => [50, 100]],
        'glucose' => ['value' => [20, 600]],
        'weight' => ['value' => [0.5, 400]],
    ];

    /**
     * Default alert thresholds per reading type (personalized device
     * settings.thresholds override these). null bounds = no default alert.
     *
     * @var array<string, array<string, array{high?: float, low?: float}>>
     */
    private const DEFAULT_THRESHOLDS = [
        'bp' => ['systolic' => ['high' => 180, 'low' => 90], 'diastolic' => ['high' => 110, 'low' => 60]],
        'pulse' => ['value' => ['high' => 120, 'low' => 50]],
        'temp' => ['value' => ['high' => 39.5, 'low' => 35]],
        'spo2' => ['value' => ['high' => 100, 'low' => 92]],
        'glucose' => ['value' => ['high' => 180, 'low' => 70]],
        'weight' => ['value' => []],
    ];

    /**
     * Enroll a device adapter against a patient. Requires the patient's
     * ACTIVE device_monitoring consent (CLINICAL_SAFETY.md §7 — no silent
     * data collection). Duplicate (tenant, device_identifier) is 409.
     *
     * @param  array<string, mixed>  $payload
     */
    public function enrollDevice(Patient $patient, array $payload, string $staffId): RpmDevice
    {
        $this->assertMonitoringConsent($patient);

        return $this->guardUnique(fn (): RpmDevice => DB::transaction(function () use ($patient, $payload, $staffId): RpmDevice {
            return RpmDevice::query()->create([
                'tenant_id' => $patient->tenant_id,
                'facility_id' => $patient->facility_id,
                'patient_id' => $patient->getKey(),
                'device_identifier' => $payload['deviceIdentifier'],
                'model' => $payload['model'] ?? null,
                'manufacturer' => $payload['manufacturer'] ?? null,
                'reading_type' => $payload['readingType'],
                'status' => RpmDevice::STATUS_PENDING,
                'settings' => $payload['settings'] ?? [],
                'adapter' => $payload['adapter'] ?? null,
                'created_by' => $staffId,
                'lock_version' => 0,
            ]);
        }));
    }

    /**
     * pending → active ⇄ disabled (CAS). Disabled devices never ingest.
     */
    public function setDeviceStatus(RpmDevice $device, string $to, string $staffId): RpmDevice
    {
        $from = match ($to) {
            RpmDevice::STATUS_ACTIVE => [RpmDevice::STATUS_PENDING, RpmDevice::STATUS_DISABLED],
            RpmDevice::STATUS_DISABLED => [RpmDevice::STATUS_ACTIVE],
            default => [],
        };

        if ($from === []) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Invalid device status transition (current status: '.$device->status.').',
                409,
            );
        }

        $affected = DB::transaction(function () use ($device, $from, $to, $staffId): int {
            return RpmDevice::query()
                ->whereKey($device->getKey())
                ->whereIn('status', $from)
                ->where('lock_version', $device->lock_version)
                ->update([
                    'status' => $to,
                    'lock_version' => $device->lock_version + 1,
                    'updated_by' => $staffId,
                ]);
        });

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The device state changed concurrently (expected '.implode('|', $from).', got '.$device->status.').',
                409,
            );
        }

        return $device->refresh();
    }

    /**
     * Ingest a batch of device readings (≤100). Each reading is validated
     * and LABELED; validated/flagged readings that breach a personalized
     * threshold generate a human-mediated alert (dedup + cooldown).
     *
     * @param  list<array<string, mixed>>  $payloads
     * @return list<RpmReading>
     */
    public function ingest(array $payloads, string $tenantId, ?string $userId = null): array
    {
        return DB::transaction(function () use ($payloads, $tenantId, $userId): array {
            $created = [];

            foreach ($payloads as $payload) {
                $created[] = $this->ingestOne($payload, $tenantId, $userId);
            }

            return $created;
        });
    }

    /**
     * open → acknowledged (CAS). The human-mediated acknowledgment records
     * WHO/WHEN and a required note — an alert is never auto-silenced.
     */
    public function acknowledgeAlert(RpmAlert $alert, string $staffId, string $note): RpmAlert
    {
        $affected = DB::transaction(function () use ($alert, $staffId, $note): int {
            return RpmAlert::query()
                ->whereKey($alert->getKey())
                ->where('status', RpmAlert::STATUS_OPEN)
                ->where('lock_version', $alert->lock_version)
                ->update([
                    'status' => RpmAlert::STATUS_ACKNOWLEDGED,
                    'lock_version' => $alert->lock_version + 1,
                    'acknowledged_by' => $staffId,
                    'acknowledged_at' => now(),
                    'acknowledged_note' => $note,
                ]);
        });

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The alert is not open for acknowledgment (current status: '.$alert->status.').',
                409,
            );
        }

        return $alert->refresh();
    }

    /**
     * acknowledged → resolved (CAS). Resolution requires prior
     * acknowledgment — the escalation discipline is: someone saw it, then
     * it was acted on.
     */
    public function resolveAlert(RpmAlert $alert, string $staffId): RpmAlert
    {
        $affected = DB::transaction(function () use ($alert, $staffId): int {
            return RpmAlert::query()
                ->whereKey($alert->getKey())
                ->where('status', RpmAlert::STATUS_ACKNOWLEDGED)
                ->where('lock_version', $alert->lock_version)
                ->update([
                    'status' => RpmAlert::STATUS_RESOLVED,
                    'lock_version' => $alert->lock_version + 1,
                    'resolved_by' => $staffId,
                    'resolved_at' => now(),
                ]);
        });

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The alert must be acknowledged before it can be resolved (current status: '.$alert->status.').',
                409,
            );
        }

        return $alert->refresh();
    }

    // ------------------------------------------------------------------ //
    // ingestion internals
    // ------------------------------------------------------------------ //

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ingestOne(array $payload, string $tenantId, ?string $userId): RpmReading
    {
        $identifier = (string) ($payload['deviceIdentifier'] ?? '');
        $ingestionId = isset($payload['ingestionId']) ? (string) $payload['ingestionId'] : null;
        $readingType = (string) ($payload['readingType'] ?? '');
        $value = $payload['value'] ?? [];

        if ($identifier === '' || $readingType === '' || ! is_array($value)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Each reading requires a deviceIdentifier, readingType and value.', 422);
        }

        // The tenant comes from the server-resolved context (never the
        // client): the adapter cannot address another tenant's device.
        $device = RpmDevice::query()
            ->where('tenant_id', $tenantId)
            ->where('device_identifier', $identifier)
            ->first();

        if ($device === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Unknown device identifier for this tenant.', 404);
        }

        if ($device->status !== RpmDevice::STATUS_ACTIVE) {
            throw new ApiException(ErrorCodes::CONFLICT, 'The device is not active (status: '.$device->status.').', 409);
        }

        if ($device->reading_type !== $readingType) {
            throw new ApiException(ErrorCodes::CONFLICT, 'The reading type does not match the device (expected '.$device->reading_type.').', 409);
        }

        // Idempotent retry: the adapter re-sent an ingestion it already saw.
        if ($ingestionId !== null) {
            $existing = RpmReading::query()
                ->where('tenant_id', $device->tenant_id)
                ->where('ingestion_id', $ingestionId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        [$status, $reason] = $this->validateReading($device, $value);

        $reading = RpmReading::query()->create([
            'tenant_id' => $device->tenant_id,
            'facility_id' => $device->facility_id,
            'patient_id' => $device->patient_id,
            'device_id' => $device->getKey(),
            'reading_type' => $readingType,
            'value' => $value,
            'units' => $payload['units'] ?? null,
            'measured_at' => $payload['measuredAt'] ?? now(),
            'source' => 'device',
            'validation_status' => $status,
            'validation_reason' => $reason,
            'provenance' => $payload['provenance'] ?? ['adapter' => $device->adapter],
            'ingestion_id' => $ingestionId,
            'created_by' => $userId,
        ])->refresh();

        $device->forceFill(['last_seen_at' => now()])->save();

        if ($status !== RpmReading::REJECTED) {
            $this->maybeCreateAlerts($device, $reading);
        }

        return $reading;
    }

    /**
     * Label the reading: structurally invalid / implausible → rejected;
     * plausible but breaching a personalized (or default) threshold →
     * flagged; else validated.
     *
     * @param  array<string, mixed>  $value
     * @return array{0: string, 1: string|null}
     */
    private function validateReading(RpmDevice $device, array $value): array
    {
        $bounds = self::PLAUSIBILITY[$device->reading_type] ?? null;

        if ($bounds === null) {
            return [RpmReading::REJECTED, 'unsupported reading type'];
        }

        foreach ($bounds as $parameter => [$min, $max]) {
            $observed = $value[$parameter] ?? null;

            if (! is_numeric($observed)) {
                return [RpmReading::REJECTED, "missing or non-numeric parameter {$parameter}"];
            }

            $observed = (float) $observed;

            if ($observed < $min || $observed > $max) {
                return [RpmReading::REJECTED, "{$parameter} out of plausible range ({$min}-{$max})"];
            }
        }

        // Within plausible range — is it outside the personalized threshold?
        foreach (array_keys($bounds) as $parameter) {
            $threshold = $this->thresholdFor($device, $parameter);

            if ($threshold === null) {
                continue;
            }

            $observed = (float) $value[$parameter];

            if (isset($threshold['high']) && $observed > (float) $threshold['high']) {
                return [RpmReading::FLAGGED, "{$parameter} above threshold"];
            }

            if (isset($threshold['low']) && $observed < (float) $threshold['low']) {
                return [RpmReading::FLAGGED, "{$parameter} below threshold"];
            }
        }

        return [RpmReading::VALIDATED, null];
    }

    /**
     * @return array{high?: float, low?: float}|null
     */
    private function thresholdFor(RpmDevice $device, string $parameter): ?array
    {
        $personal = $device->thresholdFor($parameter);

        if ($personal !== null) {
            return $this->normalizeThreshold($personal);
        }

        return $this->normalizeThreshold(self::DEFAULT_THRESHOLDS[$device->reading_type][$parameter] ?? []);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{high?: float, low?: float}|null
     */
    private function normalizeThreshold(array $raw): ?array
    {
        $normalized = [];

        foreach (['high', 'low'] as $bound) {
            if (isset($raw[$bound]) && is_numeric($raw[$bound])) {
                $normalized[$bound] = (float) $raw[$bound];
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Create an alert for a flagged/validated reading that breaches a
     * threshold. Dedup: one OPEN alert per (device, parameter). Cooldown:
     * no new alert for the same (device, parameter) within
     * settings.alert_cooldown_minutes (default 15) of the previous alert —
     * alert fatigue tuned (ROADMAP Phase 20 acceptance).
     */
    private function maybeCreateAlerts(RpmDevice $device, RpmReading $reading): void
    {
        $bounds = self::PLAUSIBILITY[$device->reading_type] ?? [];

        foreach (array_keys($bounds) as $parameter) {
            $threshold = $this->thresholdFor($device, $parameter);

            if ($threshold === null) {
                continue;
            }

            $observed = (float) ($reading->value[$parameter] ?? NAN);

            if (! is_finite($observed)) {
                continue;
            }

            $type = null;

            if (isset($threshold['high']) && $observed > (float) $threshold['high']) {
                $type = RpmAlert::TYPE_HIGH;
            } elseif (isset($threshold['low']) && $observed < (float) $threshold['low']) {
                $type = RpmAlert::TYPE_LOW;
            }

            if ($type === null) {
                continue;
            }

            $limit = $type === RpmAlert::TYPE_HIGH ? (float) $threshold['high'] : (float) $threshold['low'];

            if ($this->isAlertSuppressed($device, $parameter)) {
                continue;
            }

            RpmAlert::query()->create([
                'tenant_id' => $device->tenant_id,
                'facility_id' => $device->facility_id,
                'patient_id' => $device->patient_id,
                'device_id' => $device->getKey(),
                'reading_id' => $reading->getKey(),
                'alert_type' => $type,
                'parameter' => $parameter,
                'threshold_value' => [$type === RpmAlert::TYPE_HIGH ? 'high' : 'low' => $limit],
                'observed_value' => [$parameter => $observed],
                'severity' => $this->severity($observed, $limit),
                'status' => RpmAlert::STATUS_OPEN,
                'created_by' => $reading->created_by,
                'lock_version' => 0,
            ]);
        }
    }

    private function isAlertSuppressed(RpmDevice $device, string $parameter): bool
    {
        // An OPEN alert for this device+parameter already exists — never
        // stack duplicate unacknowledged alerts for the same parameter.
        $open = RpmAlert::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->getKey())
            ->where('parameter', $parameter)
            ->where('status', RpmAlert::STATUS_OPEN)
            ->exists();

        if ($open) {
            return true;
        }

        // Cooldown: any recent alert (any status) for this device+parameter.
        $cooldownMinutes = $device->alertCooldownMinutes();

        return RpmAlert::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->getKey())
            ->where('parameter', $parameter)
            ->where('created_at', '>=', now()->subMinutes($cooldownMinutes))
            ->exists();
    }

    private function severity(float $observed, float $limit): string
    {
        if ($limit == 0.0) {
            return RpmAlert::SEVERITY_LOW;
        }

        $ratio = abs($observed - $limit) / abs($limit);

        return match (true) {
            $ratio >= 0.20 => RpmAlert::SEVERITY_HIGH,
            $ratio >= 0.10 => RpmAlert::SEVERITY_MEDIUM,
            default => RpmAlert::SEVERITY_LOW,
        };
    }

    private function assertMonitoringConsent(Patient $patient): void
    {
        $consented = Consent::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->getKey())
            ->where('consent_type', Consent::TYPE_DEVICE_MONITORING)
            ->where('status', Consent::STATUS_ACTIVE)
            ->exists();

        if (! $consented) {
            throw new ApiException(
                ErrorCodes::FORBIDDEN,
                'Device enrollment requires the patient\'s active device-monitoring consent.',
                403,
            );
        }
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
                    'A device with this identifier is already enrolled for the tenant.',
                    409,
                );
            }

            throw $e;
        }
    }
}
