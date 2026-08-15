<?php

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

/**
 * Phase 3 slice 4 — discharge & follow-up (PRODUCT_REQUIREMENTS §6.7):
 * the clinical close of a signed visit (discharge) and planned return
 * visits linked to the encounter (plan → book → complete / cancel). The
 * discharge transition (signed → closed) is a compare-and-swap on (status,
 * lock_version); follow-up transitions are CAS too — concurrent actors can
 * never double-advance.
 */
beforeEach(function (): void {
    seedIdentity();
});

function slice4Doctor(Organization $org, Facility $facility, User $user): Staff
{
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $user->getKey(),
        'designation' => 'Consultant Physician',
        'status' => 'active',
    ]);
}

function slice4Encounter(Organization $org, Facility $facility, Staff $doctor, string $status = Encounter::STATUS_OPEN): Encounter
{
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $encounter = Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'status' => $status,
    ]);

    if ($status === Encounter::STATUS_SIGNED) {
        $encounter->update([
            'status' => Encounter::STATUS_SIGNED,
            'ended_at' => now(),
            'signed_by' => $doctor->user_id,
            'signed_at' => now(),
            'lock_version' => 1,
        ]);
    }

    return $encounter;
}

function slice4FollowUp(Organization $org, Facility $facility, Encounter $encounter, User $doctorUser, array $overrides = []): FollowUp
{
    return FollowUp::factory()->create(array_merge([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->getKey(),
        'provider_staff_id' => $encounter->provider_staff_id,
    ], $overrides));
}

it('discharges a signed encounter with disposition, summary, and audit', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    $encounter = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_SIGNED);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/discharge', [
            'disposition' => 'home',
            'summary' => 'Stable at discharge. Paracetamol PRN for fever, review in 7 days.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', Encounter::STATUS_CLOSED)
        ->assertJsonPath('data.disposition', 'home')
        ->assertJsonPath('data.dischargeSummary', 'Stable at discharge. Paracetamol PRN for fever, review in 7 days.');

    $fresh = Encounter::query()->findOrFail($encounter->getKey());
    expect($fresh->status)->toBe(Encounter::STATUS_CLOSED)
        ->and($fresh->discharged_at)->not->toBeNull()
        ->and($fresh->lock_version)->toBe(2)
        ->and(AuditEvent::query()->where('action', 'encounter.discharged')->count())->toBe(1);
});

it('refuses to discharge from any state except signed', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $open = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_OPEN);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$open->getKey().'/discharge', ['disposition' => 'home', 'summary' => 'x'])
        ->assertStatus(409);

    $closed = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_CLOSED);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$closed->getKey().'/discharge', ['disposition' => 'home', 'summary' => 'x'])
        ->assertStatus(409);
});

it('denies discharge to nurses and to non-provider doctors', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    $encounter = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_SIGNED);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    // A nurse holds no encounter:sign → 403 at the gate.
    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $org, $facility);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/discharge', ['disposition' => 'home', 'summary' => 'x'])
        ->assertStatus(403);

    // A different doctor holds encounter:sign but is NOT the encounter
    // provider → 403 at the provider guard.
    $other = Identity::user();
    slice4Doctor($org, $facility, $other);
    Identity::assign($other, 'doctor', $org, $facility);
    $this->withToken(Identity::tokenFor($other))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/discharge', ['disposition' => 'home', 'summary' => 'x'])
        ->assertStatus(403);

    expect(Encounter::query()->findOrFail($encounter->getKey())->status)->toBe(Encounter::STATUS_SIGNED);
});

it('plans a follow-up on an open encounter and audits it', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    $encounter = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_OPEN);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/follow-ups', [
            'followUpType' => 'return_visit',
            'plannedAt' => now()->addDays(7)->toIso8601String(),
            'reason' => 'Review blood pressure control',
        ])
        ->assertCreated()
        ->assertJsonPath('data.followUpType', 'return_visit')
        ->assertJsonPath('data.status', FollowUp::STATUS_PLANNED)
        ->assertJsonPath('data.reason', 'Review blood pressure control')
        ->assertJsonPath('data.providerStaffId', $doctor->getKey());

    expect(FollowUp::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'follow_up.planned')->count())->toBe(1);

    // Validation: a past planned date and an unknown type are rejected.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/follow-ups', [
            'followUpType' => 'return_visit',
            'plannedAt' => now()->subDay()->toIso8601String(),
        ])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/follow-ups', [
            'followUpType' => 'virtual',
            'plannedAt' => now()->addDays(3)->toIso8601String(),
        ])
        ->assertStatus(422);
});

it('refuses to plan a follow-up on a signed encounter or as a nurse', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    $encounter = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_SIGNED);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    // The record is final — no new plans after signing.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/follow-ups', [
            'followUpType' => 'return_visit',
            'plannedAt' => now()->addDays(7)->toIso8601String(),
        ])
        ->assertStatus(409);

    // Nurses hold followup:view only — planning is the provider's step.
    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $org, $facility);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/follow-ups', [
            'followUpType' => 'return_visit',
            'plannedAt' => now()->addDays(7)->toIso8601String(),
        ])
        ->assertStatus(403);
});

it('lists follow-ups per encounter and per patient (upcoming only)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    $encounter = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_OPEN);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $planned = slice4FollowUp($org, $facility, $encounter, $doctorUser, ['planned_at' => now()->addDays(3)]);
    $booked = slice4FollowUp($org, $facility, $encounter, $doctorUser, ['planned_at' => now()->addDays(5), 'status' => FollowUp::STATUS_BOOKED]);
    slice4FollowUp($org, $facility, $encounter, $doctorUser, ['planned_at' => now()->addDays(1), 'status' => FollowUp::STATUS_COMPLETED]);
    slice4FollowUp($org, $facility, $encounter, $doctorUser, ['planned_at' => now()->addDays(9), 'status' => FollowUp::STATUS_CANCELLED, 'cancel_reason' => 'No longer needed']);

    // Care team view: everything, ordered by planned_at.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/encounters/'.$encounter->getKey().'/follow-ups')
        ->assertOk()
        ->assertJsonCount(4, 'data');

    // Patient view: only the upcoming (planned + booked) visits.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/patients/'.$encounter->patient_id.'/follow-ups')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $planned->getKey());
});

it('books a planned follow-up against a matching appointment and rejects foreign ones', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    $encounter = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_OPEN);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $followUp = slice4FollowUp($org, $facility, $encounter, $doctorUser);

    // A matching appointment for the same patient in the same facility.
    $appointment = Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $encounter->patient_id,
        'provider_staff_id' => $doctor->getKey(),
        'appointment_type' => 'follow_up',
    ]);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/book', [
            'appointmentId' => $appointment->getKey(),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', FollowUp::STATUS_BOOKED)
        ->assertJsonPath('data.bookedAppointmentId', $appointment->getKey());

    expect(AuditEvent::query()->where('action', 'follow_up.booked')->count())->toBe(1);

    // A different patient's appointment is rejected (422), and a cancelled
    // follow-up cannot be booked (409).
    $otherPatient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $foreign = Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $otherPatient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
    ]);

    $another = slice4FollowUp($org, $facility, $encounter, $doctorUser);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$another->getKey().'/book', [
            'appointmentId' => $foreign->getKey(),
        ])
        ->assertStatus(422);

    $cancelled = slice4FollowUp($org, $facility, $encounter, $doctorUser, ['status' => FollowUp::STATUS_CANCELLED, 'cancel_reason' => 'x']);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$cancelled->getKey().'/book', [
            'appointmentId' => $appointment->getKey(),
        ])
        ->assertStatus(409);
});

it('cancels and completes follow-ups with strict transitions', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    $encounter = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_OPEN);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    // Cancel a planned follow-up with a reason.
    $planned = slice4FollowUp($org, $facility, $encounter, $doctorUser);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$planned->getKey().'/cancel', ['reason' => 'Patient improved'])
        ->assertOk()
        ->assertJsonPath('data.status', FollowUp::STATUS_CANCELLED)
        ->assertJsonPath('data.cancelReason', 'Patient improved');

    // Cancel without a reason → 422; cancel twice → 409.
    $planned2 = slice4FollowUp($org, $facility, $encounter, $doctorUser);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$planned2->getKey().'/cancel', [])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$planned->getKey().'/cancel', ['reason' => 'again'])
        ->assertStatus(409);

    // Complete: booked → completed; a planned one cannot be completed.
    $booked = slice4FollowUp($org, $facility, $encounter, $doctorUser, ['status' => FollowUp::STATUS_BOOKED]);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$booked->getKey().'/complete')
        ->assertOk()
        ->assertJsonPath('data.status', FollowUp::STATUS_COMPLETED);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$planned2->getKey().'/complete')
        ->assertStatus(409);
});

it('enforces cross-tenant and cross-facility isolation on the whole surface', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $doctorA = Identity::user();
    $doctorAStaff = slice4Doctor($orgA, $facilityA, $doctorA);
    $encounterA = slice4Encounter($orgA, $facilityA, $doctorAStaff, Encounter::STATUS_SIGNED);
    $followUpA = slice4FollowUp($orgA, $facilityA, $encounterA, $doctorA);
    Identity::assign($doctorA, 'doctor', $orgA, $facilityA);

    // Tenant-B doctor attacks tenant A's discharge + follow-up surfaces.
    $doctorB = Identity::user();
    slice4Doctor($orgB, $facilityB, $doctorB);
    Identity::assign($doctorB, 'doctor', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($doctorB))
        ->postJson('/api/v1/encounters/'.$encounterA->getKey().'/discharge', ['disposition' => 'home', 'summary' => 'x'])
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($doctorB))
        ->getJson('/api/v1/encounters/'.$encounterA->getKey().'/follow-ups')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($doctorB))
        ->postJson('/api/v1/encounters/'.$encounterA->getKey().'/follow-ups', [
            'followUpType' => 'return_visit',
            'plannedAt' => now()->addDays(3)->toIso8601String(),
        ])
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($doctorB))
        ->postJson('/api/v1/follow-ups/'.$followUpA->getKey().'/cancel', ['reason' => 'attack'])
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($doctorB))
        ->getJson('/api/v1/patients/'.$encounterA->patient_id.'/follow-ups')
        ->assertStatus(404);

    // And tenant A's data is untouched.
    expect(FollowUp::query()->findOrFail($followUpA->getKey())->status)->toBe(FollowUp::STATUS_PLANNED)
        ->and(Encounter::query()->findOrFail($encounterA->getKey())->status)->toBe(Encounter::STATUS_SIGNED);

    // Cross-facility within the same tenant: facility-B doctor cannot reach
    // facility A's encounter or plans.
    $facilityB2 = Identity::facility($orgA);
    $doctorB2 = Identity::user();
    slice4Doctor($orgA, $facilityB2, $doctorB2);
    Identity::assign($doctorB2, 'doctor', $orgA, $facilityB2);

    $this->withToken(Identity::tokenFor($doctorB2))
        ->getJson('/api/v1/encounters/'.$encounterA->getKey().'/follow-ups')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($doctorB2))
        ->postJson('/api/v1/encounters/'.$encounterA->getKey().'/discharge', ['disposition' => 'home', 'summary' => 'x'])
        ->assertStatus(403);
});

it('wins the concurrent discharge race via the compare-and-swap', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    $encounter = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_SIGNED);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    // The winning discharge commits atomically — the exact compare-and-swap
    // the controller runs.
    $winner = DB::table('encounters')
        ->where('id', $encounter->getKey())
        ->where('status', Encounter::STATUS_SIGNED)
        ->where('lock_version', $encounter->lock_version)
        ->update(['status' => Encounter::STATUS_CLOSED, 'lock_version' => $encounter->lock_version + 1]);

    expect($winner)->toBe(1);

    // A second discharge holding the stale snapshot affects zero rows.
    $loser = DB::table('encounters')
        ->where('id', $encounter->getKey())
        ->where('status', Encounter::STATUS_SIGNED)
        ->where('lock_version', $encounter->lock_version)
        ->update(['status' => Encounter::STATUS_CLOSED, 'lock_version' => $encounter->lock_version + 1]);

    expect($loser)->toBe(0);

    // The losing HTTP request fails safely with CONFLICT.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/discharge', ['disposition' => 'home', 'summary' => 'x'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(Encounter::query()->findOrFail($encounter->getKey())->status)->toBe(Encounter::STATUS_CLOSED);
});

it('keeps discharge summaries and patient identifiers out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    $encounter = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_OPEN);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $patientName = Patient::query()->findOrFail($encounter->patient_id)->full_name;

    // The follow-up is planned while the encounter is open, then the visit
    // is signed and discharged.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/follow-ups', [
            'followUpType' => 'return_visit',
            'plannedAt' => now()->addDays(7)->toIso8601String(),
            'reason' => 'Sensitive follow-up reason text',
        ])
        ->assertCreated();

    $encounter->update([
        'status' => Encounter::STATUS_SIGNED,
        'ended_at' => now(),
        'signed_by' => $doctorUser->getKey(),
        'signed_at' => now(),
        'lock_version' => 1,
    ]);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/discharge', [
            'disposition' => 'home',
            'summary' => 'Highly specific discharge note about the patient prognosis',
        ])
        ->assertOk();

    foreach (AuditEvent::query()->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain('Highly specific discharge note')
            ->and($encoded)->not->toContain('Sensitive follow-up reason')
            ->and($encoded)->not->toContain($patientName);
    }
});

it('requires authentication for discharge and follow-up surfaces', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = slice4Doctor($org, $facility, $doctorUser);
    $encounter = slice4Encounter($org, $facility, $doctor, Encounter::STATUS_SIGNED);
    $followUp = slice4FollowUp($org, $facility, $encounter, $doctorUser);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    // The doctor's token persists from the fixture calls — flush it.
    $this->flushHeaders();

    $this->postJson('/api/v1/encounters/'.$encounter->getKey().'/discharge', ['disposition' => 'home', 'summary' => 'x'])
        ->assertStatus(401);

    $this->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/cancel', ['reason' => 'x'])
        ->assertStatus(401);
});
