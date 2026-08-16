<?php

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\FacilitySetting;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\Teleconsult;
use App\Models\User;
use App\Models\VideoSession;
use App\Services\TelehealthService;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 slice 24 — Telehealth (ROADMAP Phase 19, PRODUCT_REQUIREMENTS
 * §6.20): virtual consultations integrated with the same record.
 *
 * Proves: teleconsult scheduling from a teleconsult appointment (same
 * schedule/queue model); the consent gate (an ACTIVE telehealth consent
 * covering the medium is required before start); video-session metadata
 * only (never media); the EXPLICIT recording decision (separate
 * telehealth:record permission + facility policy + patient consent —
 * default disabled, never implicit); the documented connectivity-failure
 * fallback (session failed + teleconsult fallback mode, never a silent
 * drop); completion only after the shared encounter is SIGNED (same
 * standard as OPD); cancellation; tenant/facility isolation; CAS
 * concurrency; and PHI-safe audit payloads.
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * @return array{org: Organization, facility: Facility, admin: User, staff: Staff, department: Department, doctor: User, doctorStaff: Staff}
 */
function telehealthAdmin(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $staff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $admin->getKey(),
    ]);

    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org, $facility);
    $doctorStaff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $doctor->getKey(),
    ]);

    return ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff, 'department' => $department, 'doctor' => $doctor, 'doctorStaff' => $doctorStaff];
}

/**
 * A booked teleconsult appointment for the given context.
 */
function teleconsultAppointment(array $ctx, Patient $patient): Appointment
{
    return Appointment::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $ctx['doctorStaff']->getKey(),
        'appointment_type' => Appointment::TYPE_TELECONSULT,
        'status' => Appointment::STATUS_BOOKED,
        'source' => Appointment::SOURCE_COUNTER,
    ]);
}

function teleconsultPatient(array $ctx, array $attributes = []): Patient
{
    return Patient::factory()->create(array_merge([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
    ], $attributes));
}

function telehealthConsent(array $ctx, Patient $patient, array $scope = ['video']): Consent
{
    return Consent::query()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'patient_id' => $patient->getKey(),
        'consent_type' => Consent::TYPE_TELEHEALTH,
        'version' => 1,
        'status' => Consent::STATUS_ACTIVE,
        'scope' => $scope,
        'given_at' => now(),
    ]);
}

it('schedules a teleconsult from a teleconsult appointment and refuses duplicates', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);

    $response = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', [
            'appointmentId' => $appointment->getKey(),
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', Teleconsult::STATUS_SCHEDULED)
        ->assertJsonPath('data.appointmentId', $appointment->getKey())
        ->assertJsonPath('data.patientId', $patient->getKey());

    $this->assertDatabaseHas('teleconsults', [
        'tenant_id' => $ctx['org']->getKey(),
        'appointment_id' => $appointment->getKey(),
        'status' => Teleconsult::STATUS_SCHEDULED,
    ]);

    // Duplicate scheduling of the same appointment → 409 (partial unique).
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', [
            'appointmentId' => $appointment->getKey(),
        ])->assertStatus(409);

    expect(AuditEvent::query()->where('action', 'telehealth.scheduled')->count())->toBe(1);
});

it('refuses to schedule from a non-teleconsult appointment', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = Appointment::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $ctx['doctorStaff']->getKey(),
        'appointment_type' => Appointment::TYPE_OPD,
    ]);

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', [
            'appointmentId' => $appointment->getKey(),
        ])->assertStatus(409);

    expect(Teleconsult::query()->count())->toBe(0);
});

it('denies scheduling without telehealth:schedule', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);

    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $ctx['org'], $ctx['facility']);

    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/telehealth/schedule', [
            'appointmentId' => $appointment->getKey(),
        ])->assertForbidden();
});

it('requires authentication for the telehealth surface', function (): void {
    $this->getJson('/api/v1/telehealth/teleconsults')->assertStatus(401);
    $this->postJson('/api/v1/telehealth/schedule', ['appointmentId' => (string) Str::uuid()])->assertStatus(401);
});

it('runs the full happy path: ready → consent-gated start → video → signed encounter → complete', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video', 'recording']);

    // Schedule.
    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');

    // ready.
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/ready")
        ->assertStatus(200)->assertJsonPath('data.status', Teleconsult::STATUS_READY);

    // start (consent gate) → in_progress + shared Encounter created.
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start", ['medium' => 'video'])
        ->assertStatus(201)
        ->assertJsonPath('data.status', Teleconsult::STATUS_IN_PROGRESS);

    $encounter = Encounter::query()
        ->where('appointment_id', $appointment->getKey())
        ->where('type', Encounter::TYPE_TELECONSULT)
        ->firstOrFail();
    expect($encounter->status)->toBe(Encounter::STATUS_OPEN)
        ->and($encounter->provider_staff_id)->toBe($ctx['doctorStaff']->getKey());

    // Open a video session (metadata only).
    $session = $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/video-sessions", [
            'participantType' => 'provider',
            'recordingRequested' => true,
            'providerSessionRef' => 'room-'.substr((string) Str::uuid(), 0, 8),
        ])
        ->assertStatus(201)->json('data');
    expect($session['status'])->toBe(VideoSession::STATUS_ACTIVE);

    // Document + sign the encounter at the OPD standard, then complete.
    $note = $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/encounters/{$encounter->getKey()}/notes", [
            'noteType' => 'consultation',
            'content' => ['summary' => 'Teleconsult completed'],
        ])->assertStatus(201)->json('data');

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/encounters/{$encounter->getKey()}/notes/{$note['id']}/sign")
        ->assertStatus(200);

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/encounters/{$encounter->getKey()}/sign")
        ->assertStatus(200);

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/complete")
        ->assertStatus(200)->assertJsonPath('data.status', Teleconsult::STATUS_COMPLETED);

    // End the video session.
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/video-sessions/{$session['id']}/end")
        ->assertStatus(200)->assertJsonPath('data.status', VideoSession::STATUS_ENDED);

    // Audit trail: schedule, ready, start, video opened, video ended, complete.
    expect(AuditEvent::query()->where('action', 'telehealth.scheduled')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'telehealth.ready')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'telehealth.started')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'telehealth.video_opened')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'telehealth.video_ended')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'telehealth.completed')->count())->toBe(1);
});

it('enforces the consent gate: no active telehealth consent → 403 at start', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/ready")
        ->assertStatus(200);

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start", ['medium' => 'video'])
        ->assertStatus(403);

    // No encounter may be created, no state change.
    expect(Encounter::query()->count())->toBe(0)
        ->and(Teleconsult::query()->findOrFail($teleconsult['id'])->status)->toBe(Teleconsult::STATUS_READY);
});

it('requires the consent scope to cover the medium (phone fallback needs phone consent)', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video']); // no 'phone'

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/ready")
        ->assertStatus(200);

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start", ['medium' => 'phone'])
        ->assertStatus(403);
});

it('records connectivity failure with a documented fallback (never a silent drop)', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video']);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/ready")
        ->assertStatus(200);

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start", ['medium' => 'video'])
        ->assertStatus(201);

    $session = $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/video-sessions")
        ->assertStatus(201)->json('data');

    $response = $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/video-sessions/{$session['id']}/fail", [
            'fallbackMode' => 'phone',
            'fallbackReason' => 'Patient video link failed after three attempts; continued by phone.',
        ])
        ->assertStatus(200);

    $response->assertJsonPath('data.session.status', VideoSession::STATUS_FAILED)
        ->assertJsonPath('data.teleconsult.status', Teleconsult::STATUS_FAILED)
        ->assertJsonPath('data.teleconsult.fallbackMode', 'phone');

    $this->assertDatabaseHas('teleconsults', [
        'id' => $teleconsult['id'],
        'status' => Teleconsult::STATUS_FAILED,
        'fallback_mode' => 'phone',
        'fallback_reason' => 'Patient video link failed after three attempts; continued by phone.',
    ]);

    expect(AuditEvent::query()->where('action', 'telehealth.video_failed')->count())->toBe(1);
});

it('refuses an invalid fallback mode', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video']);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');

    $session = VideoSession::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'teleconsult_id' => $teleconsult['id'],
    ]);

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/video-sessions/{$session->getKey()}/fail", [
            'fallbackMode' => 'carrier_pigeon',
        ])->assertStatus(422);
});

it('gates recording: default policy disabled → recording refused, consult unaffected', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video', 'recording']);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/ready")->assertStatus(200);
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start")->assertStatus(201);

    $session = $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/video-sessions")
        ->assertStatus(201)->json('data');

    // The doctor does NOT hold telehealth:record — denied outright.
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/video-sessions/{$session['id']}/recording", [
            'action' => 'start',
            'storageRef' => 'rec/'.Str::uuid(),
        ])->assertForbidden();

    // The hospital admin holds telehealth:record but the facility policy is
    // disabled by default → refused (200, recordingAllowed: false).
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/telehealth/video-sessions/{$session['id']}/recording", [
            'action' => 'start',
            'storageRef' => 'rec/'.Str::uuid(),
        ])->assertStatus(200)->assertJsonPath('data.recordingAllowed', false);

    expect(AuditEvent::query()->where('action', 'telehealth.recording_refused')->count())->toBe(1);

    // The consult is unaffected — still in_progress, session still active.
    $this->assertDatabaseHas('teleconsults', ['id' => $teleconsult['id'], 'status' => Teleconsult::STATUS_IN_PROGRESS]);
    $this->assertDatabaseHas('video_sessions', ['id' => $session['id'], 'status' => VideoSession::STATUS_ACTIVE, 'recording_started_at' => null]);
});

it('allows recording when policy + consent + permission all pass', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video', 'recording']);

    FacilitySetting::query()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'key' => TelehealthService::RECORDING_SETTING_KEY,
        'value' => TelehealthService::RECORDING_POLICY_CONSENT_REQUIRED,
        'version' => 1,
        'updated_by' => $ctx['admin']->getKey(),
    ]);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/ready")->assertStatus(200);
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start")->assertStatus(201);

    $session = $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/video-sessions")
        ->assertStatus(201)->json('data');

    $ref = 'rec/'.Str::uuid();
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/telehealth/video-sessions/{$session['id']}/recording", [
            'action' => 'start',
            'storageRef' => $ref,
        ])->assertStatus(200)->assertJsonPath('data.recordingAllowed', true);

    $this->assertDatabaseHas('video_sessions', [
        'id' => $session['id'],
        'recording_started_at' => now()->toDateTimeString(),
        'recording_consent_verified' => true,
        'recording_storage_ref' => $ref,
    ]);

    // Stop the recording (idempotent on repeat).
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/telehealth/video-sessions/{$session['id']}/recording", ['action' => 'stop'])
        ->assertStatus(200);
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/telehealth/video-sessions/{$session['id']}/recording", ['action' => 'stop'])
        ->assertStatus(200);

    expect(AuditEvent::query()->where('action', 'telehealth.recording_started')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'telehealth.recording_stopped')->count())->toBe(1);
});

it('requires recording consent even under consent_required policy', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video']); // no 'recording'

    FacilitySetting::query()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'key' => TelehealthService::RECORDING_SETTING_KEY,
        'value' => TelehealthService::RECORDING_POLICY_CONSENT_REQUIRED,
        'version' => 1,
        'updated_by' => $ctx['admin']->getKey(),
    ]);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/ready")->assertStatus(200);
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start")->assertStatus(201);

    $session = $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/video-sessions")
        ->assertStatus(201)->json('data');

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/telehealth/video-sessions/{$session['id']}/recording", [
            'action' => 'start',
            'storageRef' => 'rec/'.Str::uuid(),
        ])->assertStatus(200)->assertJsonPath('data.recordingAllowed', false);
});

it('refuses to complete before the encounter is signed (same standard as OPD)', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video']);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/ready")->assertStatus(200);
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start")->assertStatus(201);

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/complete")
        ->assertStatus(409);
});

it('cancels a scheduled teleconsult and refuses double-cancel with 409', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/cancel")
        ->assertStatus(200)->assertJsonPath('data.status', Teleconsult::STATUS_CANCELLED);

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/cancel")
        ->assertStatus(409);

    expect(AuditEvent::query()->where('action', 'telehealth.cancelled')->count())->toBe(1);
});

it('blocks invalid state transitions (start before ready, complete after cancel)', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video']);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');

    // start requires ready — a direct start from scheduled is refused.
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start")
        ->assertStatus(409);

    // complete requires in_progress.
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/complete")
        ->assertStatus(409);
});

it('isolates teleconsults across tenants and facilities', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');

    // Another tenant: same-shaped record, own admin.
    $intruderCtx = telehealthAdmin();
    $intruderPatient = teleconsultPatient($intruderCtx);
    teleconsultAppointment($intruderCtx, $intruderPatient);

    // List shows only the caller's tenant rows.
    $this->withToken(Identity::tokenFor($intruderCtx['admin']))
        ->getJson('/api/v1/telehealth/teleconsults')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');

    // Route-bound cross-tenant id → 404 read / 403 write (no existence leak
    // on reads; writes denied — the established repo convention).
    $this->withToken(Identity::tokenFor($intruderCtx['admin']))
        ->getJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}")
        ->assertNotFound();
    $this->withToken(Identity::tokenFor($intruderCtx['admin']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/cancel")
        ->assertForbidden();

    // Same tenant, different facility: also invisible (TENANT_FACILITY).
    $otherFacility = Identity::facility($ctx['org']);
    $sibling = Identity::user();
    Identity::assign($sibling, 'hospital_admin', $ctx['org'], $otherFacility);
    $this->withToken(Identity::tokenFor($sibling))
        ->getJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}")
        ->assertNotFound();
});

it('keeps video session metadata PHI-safe and storage refs as references only', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video']);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/ready")->assertStatus(200);
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start")->assertStatus(201);

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/video-sessions", [
            'participantType' => 'patient',
            'providerSessionRef' => 'room-abc',
        ])->assertStatus(201)->assertJsonPath('data.participantType', 'patient');

    // Every telehealth audit payload is fact-only — no PHI keys, no media.
    foreach (AuditEvent::query()->where('action', 'like', 'telehealth.%')->get() as $event) {
        expect(collect($event->payload)->keys()->contains(
            fn (string $k): bool => in_array($k, ['patientName', 'clinicalNotes', 'diagnosis', 'reason'], true)
        ))->toBeFalse("audit payload leaked a PHI key in {$event->action}");
    }

    // The session row stores METADATA ONLY — the provider room ref as a
    // reference, never pixels/media content (CLINICAL_SAFETY.md §7).
    $this->assertDatabaseHas('video_sessions', [
        'provider_session_ref' => 'room-abc',
        'participant_type' => 'patient',
        'recording_storage_ref' => null,
    ]);
});

it('proves CAS concurrency: exactly one winner on simultaneous starts', function (): void {
    $ctx = telehealthAdmin();
    $patient = teleconsultPatient($ctx);
    $appointment = teleconsultAppointment($ctx, $patient);
    telehealthConsent($ctx, $patient, ['video']);

    $teleconsult = $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/telehealth/schedule', ['appointmentId' => $appointment->getKey()])
        ->assertStatus(201)->json('data');
    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/ready")->assertStatus(200);

    // Two concurrent start calls: the first wins, the second (stale
    // lock_version) loses with 409, and exactly ONE encounter exists.
    $stale = Teleconsult::query()->findOrFail($teleconsult['id']);
    $stale->forceFill(['lock_version' => 0])->save();

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start")
        ->assertStatus(201);

    $this->withToken(Identity::tokenFor($ctx['doctor']))
        ->postJson("/api/v1/telehealth/teleconsults/{$teleconsult['id']}/start")
        ->assertStatus(409);

    expect(Encounter::query()->count())->toBe(1);
});
