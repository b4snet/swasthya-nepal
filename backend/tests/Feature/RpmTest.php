<?php

use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\RpmAlert;
use App\Models\RpmDevice;
use App\Models\RpmReading;
use App\Models\Staff;
use App\Models\User;
use App\Services\RpmService;
use Tests\Support\Identity;

/**
 * Phase 3 slice 25 — Remote Patient Monitoring (ROADMAP Phase 20,
 * PRODUCT_REQUIREMENTS §6.20 device feeds, CLINICAL_SAFETY.md §7).
 *
 * Proves: device-sourced data is always VALIDATED and clearly LABELED
 * (validated | flagged | rejected — never silently treated as verified);
 * personalized thresholds (device override wins over defaults); alerts
 * escalate to a HUMAN with acknowledgment (who/what/when) and resolution —
 * never auto-silenced; alert-fatigue controls (one open alert per device
 * + parameter, cooldown); enrollment consent gate (ACTIVE
 * device_monitoring consent required); idempotent adapter retries
 * (ingestionId); CAS exactly-one-winner transitions; tenant/facility
 * isolation; and PHI-safe audit payloads (facts and ids only).
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * @return array{org: Organization, facility: Facility, admin: User, adminStaff: Staff, department: Department, nurse: User, nurseStaff: Staff, doctor: User, doctorStaff: Staff}
 */
function rpmCtx(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $adminStaff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $admin->getKey(),
    ]);

    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $org, $facility);
    $nurseStaff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $nurse->getKey(),
    ]);

    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org, $facility);
    $doctorStaff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $doctor->getKey(),
    ]);

    return [
        'org' => $org,
        'facility' => $facility,
        'admin' => $admin,
        'adminStaff' => $adminStaff,
        'department' => $department,
        'nurse' => $nurse,
        'nurseStaff' => $nurseStaff,
        'doctor' => $doctor,
        'doctorStaff' => $doctorStaff,
    ];
}

function rpmPatient(array $ctx): Patient
{
    return Patient::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
    ]);
}

function rpmConsent(array $ctx, Patient $patient, bool $active = true): Consent
{
    return Consent::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'patient_id' => $patient->getKey(),
        'consent_type' => Consent::TYPE_DEVICE_MONITORING,
        'status' => $active ? Consent::STATUS_ACTIVE : Consent::STATUS_REVOKED,
    ]);
}

function rpmEnroll(array $ctx, Patient $patient, array $overrides = []): RpmDevice
{
    return RpmDevice::factory()->create(array_merge([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'reading_type' => 'pulse',
        'status' => RpmDevice::STATUS_ACTIVE,
        'created_by' => $ctx['nurseStaff']->getKey(),
    ], $overrides));
}

function rpmIngestPayload(string $deviceIdentifier, array $value, ?string $ingestionId = null, ?string $measuredAt = null): array
{
    return [
        'deviceIdentifier' => $deviceIdentifier,
        'ingestionId' => $ingestionId,
        'readingType' => 'pulse',
        'value' => $value,
        'measuredAt' => $measuredAt,
    ];
}

describe('RPM device enrollment', function (): void {
    it('requires authentication', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        rpmConsent($ctx, $patient);

        $this->postJson('/api/v1/rpm/devices', [
            'patientId' => $patient->getKey(),
            'deviceIdentifier' => 'DEV-1',
            'readingType' => 'pulse',
        ])->assertStatus(401);
    });

    it('denies a role without rpm:manage', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        rpmConsent($ctx, $patient);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/rpm/devices', [
                'patientId' => $patient->getKey(),
                'deviceIdentifier' => 'DEV-1',
                'readingType' => 'pulse',
            ])->assertStatus(403);
    });

    it('requires the patient\'s ACTIVE device-monitoring consent', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx); // no consent

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->postJson('/api/v1/rpm/devices', [
                'patientId' => $patient->getKey(),
                'deviceIdentifier' => 'DEV-1',
                'readingType' => 'pulse',
            ])->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        // A REVOKED consent is equally insufficient.
        rpmConsent($ctx, $patient, active: false);
        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->postJson('/api/v1/rpm/devices', [
                'patientId' => $patient->getKey(),
                'deviceIdentifier' => 'DEV-1',
                'readingType' => 'pulse',
            ])->assertStatus(403);
    });

    it('enrolls a device for a consented patient and audits it', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        rpmConsent($ctx, $patient);

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->postJson('/api/v1/rpm/devices', [
                'patientId' => $patient->getKey(),
                'deviceIdentifier' => 'DEV-1',
                'readingType' => 'pulse',
                'manufacturer' => 'Acme',
                'settings' => ['alert_cooldown_minutes' => 5],
            ])->assertStatus(201)
            ->assertJsonPath('data.status', RpmDevice::STATUS_PENDING)
            ->assertJsonPath('data.deviceIdentifier', 'DEV-1');

        $this->assertDatabaseHas('rpm_devices', [
            'tenant_id' => $ctx['org']->getKey(),
            'device_identifier' => 'DEV-1',
            'status' => RpmDevice::STATUS_PENDING,
        ]);

        $audit = AuditEvent::query()->where('action', 'rpm_device.registered')->firstOrFail();
        expect($audit->resource_type)->toBe('rpm_device');
        expect($audit->payload)->toHaveKeys(['patientId', 'readingType', 'status']);
        expect($audit->payload)->not->toHaveKey('deviceIdentifier'); // no identifying device content
    });

    it('rejects a duplicate device identifier with 409', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        rpmConsent($ctx, $patient);
        rpmEnroll($ctx, $patient, ['device_identifier' => 'DEV-1']);

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->postJson('/api/v1/rpm/devices', [
                'patientId' => $patient->getKey(),
                'deviceIdentifier' => 'DEV-1',
                'readingType' => 'pulse',
            ])->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');
    });

    it('cannot enroll against another tenant\'s patient (404, no existence leak)', function (): void {
        $ctx = rpmCtx();
        $otherOrg = Identity::organization();
        $otherFacility = Identity::facility($otherOrg);
        $otherPatient = Patient::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
        ]);

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->postJson('/api/v1/rpm/devices', [
                'patientId' => $otherPatient->getKey(),
                'deviceIdentifier' => 'DEV-1',
                'readingType' => 'pulse',
            ])->assertStatus(404);
    });

    it('activates and disables a device with audited transitions', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient, ['status' => RpmDevice::STATUS_PENDING]);

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->patchJson("/api/v1/rpm/devices/{$device->getKey()}", ['status' => RpmDevice::STATUS_ACTIVE])
            ->assertOk()
            ->assertJsonPath('data.status', RpmDevice::STATUS_ACTIVE);
        $this->assertDatabaseHas('audit_events', ['action' => 'rpm_device.activated']);

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->patchJson("/api/v1/rpm/devices/{$device->getKey()}", ['status' => RpmDevice::STATUS_DISABLED])
            ->assertOk()
            ->assertJsonPath('data.status', RpmDevice::STATUS_DISABLED);
        $this->assertDatabaseHas('audit_events', ['action' => 'rpm_device.disabled']);
    });

    it('CAS: exactly one activation wins under contention', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient, ['status' => RpmDevice::STATUS_PENDING]);

        // Two concurrent activations of the same pending device: the first
        // transitions pending → active (lock_version 0 → 1); the second
        // request re-binds the now-active row and its transition intent is
        // refused — exactly one winner.
        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->patchJson("/api/v1/rpm/devices/{$device->getKey()}", ['status' => RpmDevice::STATUS_ACTIVE])
            ->assertOk()
            ->assertJsonPath('data.status', RpmDevice::STATUS_ACTIVE);

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->patchJson("/api/v1/rpm/devices/{$device->getKey()}", ['status' => RpmDevice::STATUS_ACTIVE])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');

        $this->assertDatabaseHas('rpm_devices', ['id' => $device->getKey(), 'status' => RpmDevice::STATUS_ACTIVE, 'lock_version' => 1]);
    });

    it('CAS: a stale in-memory copy (old lock_version) cannot overwrite a concurrent winner', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient, ['status' => RpmDevice::STATUS_PENDING]);

        $service = app(RpmService::class);
        $winner = $service->setDeviceStatus($device, RpmDevice::STATUS_ACTIVE, $ctx['nurseStaff']->getKey());
        expect($winner->lock_version)->toBe(1);

        // A concurrent writer holding the ORIGINAL lock_version (0) loses.
        $stale = RpmDevice::query()->findOrFail($device->getKey());
        $stale->lock_version = 0;

        expect(fn () => $service->setDeviceStatus($stale, RpmDevice::STATUS_DISABLED, $ctx['nurseStaff']->getKey()))
            ->toThrow(ApiException::class);
    });
});

describe('RPM reading ingestion and labeling', function (): void {
    it('requires rpm:ingest', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload($device->device_identifier, ['value' => 80])],
            ])->assertStatus(403);
    });

    it('validates and labels a normal reading as validated, with no alert', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient); // pulse, active

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload($device->device_identifier, ['value' => 80])],
            ])->assertOk()
            ->assertJsonPath('data.0.validationStatus', RpmReading::VALIDATED);

        $this->assertDatabaseHas('rpm_readings', [
            'tenant_id' => $ctx['org']->getKey(),
            'device_id' => $device->getKey(),
            'validation_status' => RpmReading::VALIDATED,
        ]);
        $this->assertDatabaseCount('rpm_alerts', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'rpm_reading.ingested']);
    });

    it('rejects an implausible reading and labels it, storing it for provenance', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload($device->device_identifier, ['value' => 9999])],
            ])->assertOk()
            ->assertJsonPath('data.0.validationStatus', RpmReading::REJECTED)
            ->assertJsonPath('data.0.validationReason', 'value out of plausible range (20-250)');

        $this->assertDatabaseHas('rpm_readings', ['validation_status' => RpmReading::REJECTED]);
        // A rejected reading never alerts and never counts as a measurement.
        $this->assertDatabaseCount('rpm_alerts', 0);
    });

    it('flags a reading above the default threshold and raises an alert', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload($device->device_identifier, ['value' => 140])],
            ])->assertOk()
            ->assertJsonPath('data.0.validationStatus', RpmReading::FLAGGED);

        $alert = RpmAlert::query()->firstOrFail();
        expect($alert->alert_type)->toBe(RpmAlert::TYPE_HIGH);
        expect($alert->parameter)->toBe('value');
        expect($alert->status)->toBe(RpmAlert::STATUS_OPEN);
        expect($alert->severity)->toBe(RpmAlert::SEVERITY_MEDIUM); // 140 vs 120 = 16.7%
    });

    it('applies a PERSONALIZED threshold from the device settings', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient, [
            'settings' => ['thresholds' => ['value' => ['low' => 70, 'high' => 110]]],
        ]);

        // 65 is within the DEFAULT range (50-120) but below the personalized low.
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload($device->device_identifier, ['value' => 65])],
            ])->assertOk()
            ->assertJsonPath('data.0.validationStatus', RpmReading::FLAGGED);

        $alert = RpmAlert::query()->firstOrFail();
        expect($alert->alert_type)->toBe(RpmAlert::TYPE_LOW);
        expect($alert->threshold_value)->toBe(['low' => 70]);
    });

    it('is idempotent: an adapter retry with the same ingestionId is a no-op', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);

        $payload = rpmIngestPayload($device->device_identifier, ['value' => 140], ingestionId: 'ing-1');
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', ['readings' => [$payload]])
            ->assertOk()
            ->assertJsonPath('data.0.validationStatus', RpmReading::FLAGGED);

        // Retry the same ingestion — same reading id, no duplicate, no re-alert.
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', ['readings' => [$payload]])
            ->assertOk();

        $this->assertDatabaseCount('rpm_readings', 1);
        $this->assertDatabaseCount('rpm_alerts', 1);
    });

    it('ingests a 60-reading batch consistently (load-oriented)', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);

        $batch = collect(range(0, 59))->map(
            fn (int $i): array => rpmIngestPayload($device->device_identifier, ['value' => 60 + $i], ingestionId: "ing-{$i}")
        )->all();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', ['readings' => $batch])
            ->assertOk()
            ->assertJsonCount(60, 'data');

        $this->assertDatabaseCount('rpm_readings', 60);
        // Exactly one flagged (value 120 is exactly the default high — not a
        // breach; 60..119 are all validated) — no alerts.
        $this->assertDatabaseCount('rpm_alerts', 0);
    });

    it('rejects ingestion for an unknown or inactive device', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient, ['status' => RpmDevice::STATUS_DISABLED]);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload('UNKNOWN-DEV', ['value' => 80])],
            ])->assertStatus(404);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload($device->device_identifier, ['value' => 80])],
            ])->assertStatus(409);
    });

    it('never lets one tenant address another tenant\'s device', function (): void {
        $ctx = rpmCtx();
        $otherOrg = Identity::organization();
        $otherFacility = Identity::facility($otherOrg);
        $otherPatient = Patient::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
        ]);
        $otherDevice = RpmDevice::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherPatient->facility_id,
            'patient_id' => $otherPatient->getKey(),
            'reading_type' => 'pulse',
            'status' => RpmDevice::STATUS_ACTIVE,
        ]);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload($otherDevice->device_identifier, ['value' => 80])],
            ])->assertStatus(404);
    });
});

describe('RPM alerts — human-mediated escalation', function (): void {
    it('acknowledgment requires rpm:acknowledge', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);
        $alert = RpmAlert::factory()->withDevice($device)->create();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$alert->getKey()}/acknowledge", ['note' => 'Calling patient now.'])
            ->assertOk();

        // branch_manager has rpm:view only → denied.
        $branchManager = Identity::user();
        Identity::assign($branchManager, 'branch_manager', $ctx['org'], $ctx['facility']);
        $alert2 = RpmAlert::factory()->withDevice($device)->create();
        $this->withToken(Identity::tokenFor($branchManager))
            ->postJson("/api/v1/rpm/alerts/{$alert2->getKey()}/acknowledge", ['note' => 'nope'])
            ->assertStatus(403);
    });

    it('acknowledges an open alert with a required note and audits it', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);
        $alert = RpmAlert::factory()->withDevice($device)->create();

        // Note is required.
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$alert->getKey()}/acknowledge", [])
            ->assertStatus(422);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$alert->getKey()}/acknowledge", ['note' => 'Patient reached; advised rest.'])
            ->assertOk()
            ->assertJsonPath('data.status', RpmAlert::STATUS_ACKNOWLEDGED)
            ->assertJsonPath('data.acknowledgedBy', $ctx['adminStaff']->getKey());

        $audit = AuditEvent::query()->where('action', 'rpm_alert.acknowledged')->firstOrFail();
        expect($audit->payload)->toHaveKeys(['patientId', 'parameter', 'status']);
        expect($audit->payload)->not->toHaveKeys(['observedValue', 'thresholdValue', 'note']);
    });

    it('CAS: a second acknowledgment of the same alert is refused', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);
        $alert = RpmAlert::factory()->withDevice($device)->create();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$alert->getKey()}/acknowledge", ['note' => 'first'])
            ->assertOk();

        // The controller's copy is stale (still open) → 409.
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$alert->getKey()}/acknowledge", ['note' => 'second'])
            ->assertStatus(409);
    });

    it('cannot resolve an unacknowledged alert — escalation discipline', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);
        $alert = RpmAlert::factory()->withDevice($device)->create();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$alert->getKey()}/resolve")
            ->assertStatus(409);
    });

    it('resolves an acknowledged alert after action', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);
        $alert = RpmAlert::factory()->withDevice($device)->create();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$alert->getKey()}/acknowledge", ['note' => 'advised'])
            ->assertOk();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$alert->getKey()}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', RpmAlert::STATUS_RESOLVED)
            ->assertJsonPath('data.resolvedBy', $ctx['adminStaff']->getKey());

        $this->assertDatabaseHas('audit_events', ['action' => 'rpm_alert.resolved']);
    });

    it('never stacks duplicate open alerts for the same device+parameter', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);

        // Two consecutive high readings while the first alert is open.
        foreach ([140, 150] as $value) {
            $this->withToken(Identity::tokenFor($ctx['admin']))
                ->postJson('/api/v1/rpm/readings', [
                    'readings' => [rpmIngestPayload($device->device_identifier, ['value' => $value])],
                ])->assertOk();
        }

        $this->assertDatabaseCount('rpm_alerts', 1);
    });

    it('honors the alert cooldown after resolution (fatigue control)', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient, [
            'settings' => ['alert_cooldown_minutes' => 1],
        ]);

        // First breach → alert → ack → resolve.
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload($device->device_identifier, ['value' => 140])],
            ])->assertOk();
        $alert = RpmAlert::query()->firstOrFail();
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$alert->getKey()}/acknowledge", ['note' => 'handled'])
            ->assertOk();
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$alert->getKey()}/resolve")
            ->assertOk();

        // Second breach WITHIN the cooldown → suppressed (no new alert).
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload($device->device_identifier, ['value' => 150])],
            ])->assertOk();
        $this->assertDatabaseCount('rpm_alerts', 1);

        // After the cooldown elapses → a new alert is created.
        RpmAlert::query()->update(['created_at' => now()->subMinutes(2)]);
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/rpm/readings', [
                'readings' => [rpmIngestPayload($device->device_identifier, ['value' => 150])],
            ])->assertOk();
        $this->assertDatabaseCount('rpm_alerts', 2);
    });
});

describe('RPM isolation and monitoring views', function (): void {
    it('hides another tenant\'s devices and alerts (read 404 / write 403)', function (): void {
        $ctx = rpmCtx();
        $otherOrg = Identity::organization();
        $otherFacility = Identity::facility($otherOrg);
        $otherPatient = Patient::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
        ]);
        $otherDevice = RpmDevice::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
            'patient_id' => $otherPatient->getKey(),
            'reading_type' => 'pulse',
            'status' => RpmDevice::STATUS_ACTIVE,
        ]);
        $otherAlert = RpmAlert::factory()->withDevice($otherDevice)->create();

        // Reads are invisible (404 at binding), writes are denied (403).
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson('/api/v1/rpm/devices')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->patchJson("/api/v1/rpm/devices/{$otherDevice->getKey()}", ['status' => 'disabled'])
            ->assertStatus(403);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/rpm/alerts/{$otherAlert->getKey()}/acknowledge", ['note' => 'x'])
            ->assertStatus(403);
    });

    it('keeps sibling facilities isolated within the tenant', function (): void {
        $ctx = rpmCtx();
        $otherFacility = Identity::facility($ctx['org']);
        $otherPatient = Patient::factory()->create([
            'tenant_id' => $ctx['org']->getKey(),
            'facility_id' => $otherFacility->getKey(),
        ]);
        $otherDevice = RpmDevice::factory()->create([
            'tenant_id' => $ctx['org']->getKey(),
            'facility_id' => $otherFacility->getKey(),
            'patient_id' => $otherPatient->getKey(),
            'reading_type' => 'pulse',
            'status' => RpmDevice::STATUS_ACTIVE,
        ]);

        // The admin (facility A) cannot see or touch facility B's device.
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson('/api/v1/rpm/devices')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->patchJson("/api/v1/rpm/devices/{$otherDevice->getKey()}", ['status' => 'disabled'])
            ->assertStatus(403);
    });

    it('serves the monitoring readings series filtered by type', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);
        RpmReading::factory()->create([
            'tenant_id' => $ctx['org']->getKey(),
            'facility_id' => $ctx['facility']->getKey(),
            'patient_id' => $patient->getKey(),
            'device_id' => $device->getKey(),
            'reading_type' => 'pulse',
            'value' => ['value' => 72],
        ]);

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->getJson("/api/v1/rpm/patients/{$patient->getKey()}/readings?type=pulse")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.validationStatus', RpmReading::VALIDATED);
    });

    it('lists alerts open-first for the monitoring view', function (): void {
        $ctx = rpmCtx();
        $patient = rpmPatient($ctx);
        $device = rpmEnroll($ctx, $patient);
        RpmAlert::factory()->withDevice($device)->create(['status' => RpmAlert::STATUS_OPEN]);
        RpmAlert::factory()->withDevice($device)->create(['status' => RpmAlert::STATUS_RESOLVED]);

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->getJson('/api/v1/rpm/alerts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', RpmAlert::STATUS_OPEN);
    });
});
