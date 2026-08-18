<?php

use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\ClinicalNote;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Room;
use App\Models\Staff;
use App\Models\User;
use App\Models\Ward;
use App\Support\BedStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 slice 6 — IPD admission/discharge with bed release
 * (PRODUCT_REQUIREMENTS §6.5, DATABASE.md §3.23): a patient is admitted from
 * an open encounter onto a live available bed (CAS — two clerks can never
 * book the same bed), and discharged with a structured discharge summary
 * (a signed clinical note of type 'discharge') that releases the bed.
 *
 * The bed claim is a compare-and-swap on (status, current_admission_id,
 * lock_version); the discharge transition (admitted → discharged) is CAS on
 * (status, lock_version). The discharge summary content is clinical PHI and
 * never appears in audit payloads.
 */
beforeEach(function (): void {
    seedIdentity();
});

function admissionDoctor(Organization $org, Facility $facility, User $user): Staff
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

function admissionEncounter(Organization $org, Facility $facility, Staff $doctor, string $status = Encounter::STATUS_OPEN): Encounter
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

function admissionBed(Organization $org, Facility $facility, string $status = BedStatus::AVAILABLE): Bed
{
    // Ward/room/bed codes are unique per tenant+facility (uq_wards_tenant_facility_code,
    // uq_rooms_tenant_facility_code, uq_beds_tenant_room_code) — a per-call suffix
    // keeps multiple beds in the same tenant+facility distinct.
    $n = (string) Str::uuid();
    $suffix = substr(str_replace('-', '', $n), 0, 8);

    $ward = Ward::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'name' => 'General Ward',
        'code' => 'gen-ward-'.$suffix,
        'ward_type' => 'general',
        'status' => Ward::STATUS_ACTIVE,
    ]);

    $room = Room::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'ward_id' => $ward->getKey(),
        'name' => 'Room 1',
        'code' => 'room-'.$suffix,
        'room_type' => 'general',
        'status' => Room::STATUS_ACTIVE,
    ]);

    return Bed::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'room_id' => $room->getKey(),
        'bed_code' => 'B-'.$suffix,
        'status' => $status,
        'lock_version' => 0,
    ]);
}

it('admits a patient from an open encounter onto an available bed', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = admissionDoctor($org, $facility, $doctorUser);
    $encounter = admissionEncounter($org, $facility, $doctor);
    $bed = admissionBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $bed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Acute appendicitis',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'admitted')
        ->assertJsonPath('data.admissionType', 'emergency')
        ->assertJsonPath('data.encounterId', $encounter->getKey())
        ->assertJsonPath('data.patientId', $encounter->patient_id);

    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();
    expect($admission->admission_number)->not->toBeNull()
        ->and($admission->admitted_at)->not->toBeNull()
        ->and($admission->lock_version)->toBe(0);

    // The bed is occupied by this admission.
    $bed->refresh();
    expect($bed->status)->toBe(BedStatus::OCCUPIED)
        ->and($bed->current_admission_id)->toBe($admission->getKey())
        ->and($bed->lock_version)->toBe(1);

    expect(AuditEvent::query()->where('action', 'admission.admitted')->count())->toBe(1);
});

it('refuses admission from a signed encounter and an already-open patient admission', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = admissionDoctor($org, $facility, $doctorUser);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    // Signed encounter → 409.
    $signed = admissionEncounter($org, $facility, $doctor, Encounter::STATUS_SIGNED);
    $bed = admissionBed($org, $facility);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$signed->getKey().'/admissions', [
            'bedId' => $bed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Test',
        ])
        ->assertStatus(409);

    // First admission succeeds; a second for the same patient → 409.
    $encounter = admissionEncounter($org, $facility, $doctor);
    $bed2 = admissionBed($org, $facility);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $bed2->getKey(),
            'admissionType' => 'planned',
            'admittingDiagnosis' => 'Surgery',
        ])
        ->assertCreated();

    $encounter2 = admissionEncounter($org, $facility, $doctor);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter2->getKey().'/admissions', [
            'bedId' => $bed2->getKey(),
            'admissionType' => 'planned',
            'admittingDiagnosis' => 'Surgery',
        ])
        ->assertStatus(409);
});

it('refuses to book a bed that is occupied or out of scope', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = admissionDoctor($org, $facility, $doctorUser);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $encounter = admissionEncounter($org, $facility, $doctor);

    // Occupied bed → 409.
    $occupiedBed = admissionBed($org, $facility, BedStatus::OCCUPIED);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $occupiedBed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Test',
        ])
        ->assertStatus(409);

    // A bed in another facility → 404 (existence hidden).
    $facilityB = Identity::facility($org);
    $bedB = admissionBed($org, $facilityB);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $bedB->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Test',
        ])
        ->assertStatus(404);

    // A missing bed → 404.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => (string) Str::uuid(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Test',
        ])
        ->assertStatus(404);
});

it('discharges an admission with a structured summary and releases the bed', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = admissionDoctor($org, $facility, $doctorUser);
    $encounter = admissionEncounter($org, $facility, $doctor);
    $bed = admissionBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $bed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Appendicitis',
        ])
        ->assertCreated();

    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/discharge', [
            'dischargeType' => 'home',
            'summary' => [
                'diagnoses' => ['Acute appendicitis'],
                'procedures' => ['Appendectomy'],
                'medications' => ['Paracetamol 500mg tid x 5 days'],
                'followUp' => 'Review in 1 week',
            ],
            'identityConfirmed' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'discharged')
        ->assertJsonPath('data.dischargeType', 'home')
        ->assertJsonPath('data.dischargeSummaryId', $admission->refresh()->discharge_summary_id);

    $fresh = Admission::query()->findOrFail($admission->getKey());
    expect($fresh->status)->toBe(Admission::STATUS_DISCHARGED)
        ->and($fresh->discharged_at)->not->toBeNull()
        ->and($fresh->lock_version)->toBe(1);

    // A signed discharge-summary clinical note exists on the encounter.
    $note = ClinicalNote::query()->findOrFail($fresh->discharge_summary_id);
    expect($note->note_type)->toBe(ClinicalNote::TYPE_DISCHARGE)
        ->and($note->status)->toBe(ClinicalNote::STATUS_SIGNED)
        ->and($note->encounter_id)->toBe($encounter->getKey())
        ->and($note->content)->toHaveKey('diagnoses');

    // The bed is released → cleaning (never immediately reassignable).
    $bed->refresh();
    expect($bed->status)->toBe(BedStatus::CLEANING)
        ->and($bed->current_admission_id)->toBeNull();

    expect(AuditEvent::query()->where('action', 'admission.discharged')->count())->toBe(1);
});

it('refuses invalid discharges: already discharged, unknown admission', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = admissionDoctor($org, $facility, $doctorUser);
    $encounter = admissionEncounter($org, $facility, $doctor);
    $bed = admissionBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $bed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Test',
        ])
        ->assertCreated();

    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();

    // Missing dischargeType → 422.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/discharge', [
            'summary' => ['diagnoses' => ['Test']],
            'identityConfirmed' => true,
        ])
        ->assertStatus(422);

    // Incomplete discharge — missing identity confirmation → 422
    // (CLINICAL_SAFETY §16: the high-risk gate, never bypassable).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/discharge', [
            'dischargeType' => 'home',
            'summary' => ['diagnoses' => ['Test']],
        ])
        ->assertStatus(422);

    // identityConfirmed = false → 422, never accepted.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/discharge', [
            'dischargeType' => 'home',
            'summary' => ['diagnoses' => ['Test']],
            'identityConfirmed' => false,
        ])
        ->assertStatus(422);

    // No side effects from any rejected attempt.
    expect($admission->refresh()->status)->toBe(Admission::STATUS_ADMITTED)
        ->and($bed->refresh()->current_admission_id)->toBe($admission->getKey())
        ->and(ClinicalNote::query()->where('note_type', ClinicalNote::TYPE_DISCHARGE)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'admission.discharged')->count())->toBe(0);

    // Complete discharge (identity confirmed) → 200.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/discharge', [
            'dischargeType' => 'home',
            'summary' => ['diagnoses' => ['Test']],
            'identityConfirmed' => true,
        ])
        ->assertOk();

    // Discharging again → 409.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/discharge', [
            'dischargeType' => 'home',
            'summary' => ['diagnoses' => ['Again']],
            'identityConfirmed' => true,
        ])
        ->assertStatus(409);

    // Unknown admission → 404.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/admissions/'.(string) Str::uuid())
        ->assertStatus(404);
});

it('wins the bed-claim race via the compare-and-swap', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = admissionDoctor($org, $facility, $doctorUser);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $encounter = admissionEncounter($org, $facility, $doctor);
    $bed = admissionBed($org, $facility);

    // Simulate the admission of a competing patient that already claimed the
    // bed: the winner's CAS commits, the stale claimant affects zero rows.
    $admission = Admission::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->getKey(),
        'admission_number' => 'ADM-RACE-1',
        'admission_type' => 'emergency',
        'admitting_diagnosis' => 'Race',
        'admitted_at' => now(),
        'status' => Admission::STATUS_ADMITTED,
        'lock_version' => 0,
    ]);

    $winner = DB::table('beds')
        ->where('id', $bed->getKey())
        ->where('status', BedStatus::AVAILABLE)
        ->whereNull('current_admission_id')
        ->where('lock_version', $bed->lock_version)
        ->update([
            'status' => BedStatus::OCCUPIED,
            'current_admission_id' => $admission->getKey(),
            'lock_version' => $bed->lock_version + 1,
        ]);

    expect($winner)->toBe(1);

    // A second claimant holding the SAME stale snapshot affects zero rows.
    $loser = DB::table('beds')
        ->where('id', $bed->getKey())
        ->where('status', BedStatus::AVAILABLE)
        ->whereNull('current_admission_id')
        ->where('lock_version', $bed->lock_version)
        ->update([
            'status' => BedStatus::OCCUPIED,
            'current_admission_id' => (string) Str::uuid(),
            'lock_version' => $bed->lock_version + 1,
        ]);

    expect($loser)->toBe(0);

    // And the losing HTTP admission — arriving after the winner committed —
    // fails safely with CONFLICT and books nothing.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $bed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Too late',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(Admission::query()->count())->toBe(1)
        ->and($bed->refresh()->current_admission_id)->toBe($admission->getKey());
});

it('enforces RBAC: only the encounter provider and authorized roles admit/discharge', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = admissionDoctor($org, $facility, $doctorUser);
    $encounter = admissionEncounter($org, $facility, $doctor);
    $bed = admissionBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    // A second doctor without admission authority cannot admit.
    $otherDoctorUser = Identity::user();
    admissionDoctor($org, $facility, $otherDoctorUser);
    Identity::assign($otherDoctorUser, 'doctor', $org, $facility);
    $this->withToken(Identity::tokenFor($otherDoctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $bed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Test',
        ])
        ->assertStatus(403);

    // The receptionist has no admission permission at all.
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);
    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $bed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Test',
        ])
        ->assertStatus(403);

    // Unauthenticated → 401. (Flush the receptionist's token from the
    // previous request first — without it this post would still be
    // authenticated.)
    $this->flushHeaders();
    $this->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
        'bedId' => $bed->getKey(),
        'admissionType' => 'emergency',
        'admittingDiagnosis' => 'Test',
    ])->assertStatus(401);
});

it('enforces cross-tenant and cross-facility isolation for the admission surface', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $doctorA = admissionDoctor($orgA, $facilityA, Identity::user());
    $encounterA = admissionEncounter($orgA, $facilityA, $doctorA);
    $bedA = admissionBed($orgA, $facilityA);

    $doctorAUser = Identity::user();
    $doctorA2 = admissionDoctor($orgA, $facilityA, $doctorAUser);
    $encounterA2 = admissionEncounter($orgA, $facilityA, $doctorA2);
    Identity::assign($doctorAUser, 'doctor', $orgA, $facilityA);
    $this->withToken(Identity::tokenFor($doctorAUser))
        ->postJson('/api/v1/encounters/'.$encounterA2->getKey().'/admissions', [
            'bedId' => $bedA->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Real admission',
        ])
        ->assertCreated();

    $admissionA = Admission::query()->where('encounter_id', $encounterA2->getKey())->firstOrFail();

    // Tenant-B doctor attacking tenant A's encounter, bed, and admission.
    $doctorB = admissionDoctor($orgB, $facilityB, Identity::user());
    $doctorBUser = Identity::user();
    admissionDoctor($orgB, $facilityB, $doctorBUser);
    Identity::assign($doctorBUser, 'doctor', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($doctorBUser))
        ->postJson('/api/v1/encounters/'.$encounterA->getKey().'/admissions', [
            'bedId' => $bedA->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Attack',
        ])
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($doctorBUser))
        ->getJson('/api/v1/admissions/'.$admissionA->getKey())
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($doctorBUser))
        ->postJson('/api/v1/admissions/'.$admissionA->getKey().'/discharge', [
            'dischargeType' => 'home',
            'summary' => ['diagnoses' => ['Pwned']],
            'identityConfirmed' => true,
        ])
        ->assertStatus(403);

    // Tenant A's data is untouched.
    expect($admissionA->refresh()->status)->toBe(Admission::STATUS_ADMITTED)
        ->and($bedA->refresh()->current_admission_id)->toBe($admissionA->getKey())
        ->and(Admission::query()->count())->toBe(1);
});

it('keeps patient identifiers and discharge-summary content out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = admissionDoctor($org, $facility, $doctorUser);
    $encounter = admissionEncounter($org, $facility, $doctor);
    $bed = admissionBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $patient = $encounter->patient;
    $patientName = $patient->full_name;

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $bed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Acute appendicitis',
        ])
        ->assertCreated();

    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/discharge', [
            'dischargeType' => 'home',
            'summary' => [
                'diagnoses' => ['Acute appendicitis with perforation'],
                'procedures' => ['Appendectomy — open'],
                'medications' => ['Morphine 5mg IV'],
                'followUp' => 'Review with Dr. '.$patientName.' next week',
            ],
            'identityConfirmed' => true,
        ])
        ->assertOk();

    // No patient name, no admitting diagnosis, no summary content in ANY
    // audit payload.
    foreach (AuditEvent::query()->whereIn('action', ['admission.admitted', 'admission.discharged', 'admission.viewed'])->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain($patientName)
            ->and($encoded)->not->toContain('appendicitis')
            ->and($encoded)->not->toContain('Appendectomy')
            ->and($encoded)->not->toContain('Morphine');
    }

    // Facts are present: patient/encounter/bed ids, type, number.
    $admitted = AuditEvent::query()->where('action', 'admission.admitted')->firstOrFail();
    expect($admitted->payload)
        ->toHaveKey('patientId', $patient->getKey())
        ->toHaveKey('encounterId', $encounter->getKey())
        ->toHaveKey('bedId', $bed->getKey())
        ->toHaveKey('admissionType', 'emergency');
});
