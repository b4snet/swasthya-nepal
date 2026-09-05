<?php

use App\Exceptions\ApiException;
use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\ErEvent;
use App\Models\ErRegistration;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Room;
use App\Models\Staff;
use App\Models\TriageAssignment;
use App\Models\TriageScale;
use App\Models\User;
use App\Models\Ward;
use App\Services\ErService;
use App\Support\BedStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Phase 3 slice 14 — Emergency (ROADMAP Phase 9, PRODUCT_REQUIREMENTS §6.6):
 * minimal-data ER registration, configurable triage (the acuity level IS the
 * queue priority), time-stamped append-only ER events, and audited
 * admit/transfer/discharge disposition. Registration accepts partial data
 * (unidentified patients get a documented placeholder); triage reassessment
 * supersedes via CAS; disposition 'admitted' claims the bed through the SAME
 * CAS path as IPD; every mutation is audited facts-only (never complaint,
 * note, or reason text).
 */
beforeEach(function (): void {
    seedIdentity();
});

function erStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
{
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $user->getKey(),
        'designation' => $designation,
        'status' => Staff::STATUS_ACTIVE,
    ]);
}

function erScale(Organization $org, Facility $facility, array $attributes = []): TriageScale
{
    $suffix = substr(str_replace('-', '', (string) Str::uuid()), 0, 8);

    return TriageScale::factory()->create(array_merge([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'L'.$suffix,
        'name' => 'Level '.$suffix,
        'level' => 3,
        'color' => 'yellow',
        'reassessment_minutes' => 30,
        'is_default' => true,
        'status' => TriageScale::STATUS_ACTIVE,
    ], $attributes));
}

function erBed(Organization $org, Facility $facility): Bed
{
    $suffix = substr(str_replace('-', '', (string) Str::uuid()), 0, 8);

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
        'status' => BedStatus::AVAILABLE,
        'lock_version' => 0,
    ]);
}

/**
 * A front-desk registration actor (the ONLY role that can register —
 * er:register is never granted to clinical roles, which only triage/
 * document/dispose).
 */
function erReceptionist(Organization $org, Facility $facility): User
{
    $receptionist = Identity::user();
    erStaff($org, $facility, $receptionist, 'Receptionist');
    Identity::assign($receptionist, 'receptionist', $org, $facility);

    return $receptionist;
}

/**
 * Register through the API (as a receptionist — the registration act);
 * returns [registration, patient, encounter] from the response envelope.
 * dateOfBirth and estimatedAge are mutually exclusive, so the sentinel DOB
 * is dropped when an estimated age is supplied.
 */
function erRegister(
    TestCase $test,
    Organization $org,
    Facility $facility,
    array $payload = [],
): array {
    $receptionist = erReceptionist($org, $facility);

    $base = [
        'patientName' => 'Ram Bahadur',
        'sex' => 'male',
        'dateOfBirth' => '1990-05-15',
        'presentingComplaint' => 'Chest pain',
    ];

    if (array_key_exists('estimatedAge', $payload)) {
        unset($base['dateOfBirth']);
    }

    // An explicit null patientName registers the unidentified path (the
    // name is omitted from the request entirely).
    if (array_key_exists('patientName', $payload) && $payload['patientName'] === null) {
        unset($base['patientName'], $payload['patientName']);
    }

    $response = $test->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/er/registrations', array_merge($base, $payload))
        ->assertCreated();

    $data = $response->json('data');

    return [$data, Patient::query()->findOrFail($data['patientId']), Encounter::query()->findOrFail($data['encounterId'])];
}

it('registers a patient in the ER with full facts: patient + encounter + registration + first event', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);

    expect($registration['id'])->not->toBeNull()
        ->and($registration['mrn'])->toStartWith('MRN-')
        ->and($registration['isUnidentified'])->toBeFalse()
        ->and($registration['patientName'])->toBe('Ram Bahadur')
        ->and($registration['presentingComplaint'])->toBe('Chest pain')
        ->and($patient->full_name)->toBe('Ram Bahadur')
        ->and($patient->mrn)->toBe($registration['mrn'])
        ->and($encounter->type)->toBe(Encounter::TYPE_ER)
        ->and($encounter->status)->toBe(Encounter::STATUS_OPEN);

    // The registration row and the medico-legal first event exist.
    expect(ErRegistration::query()->where('patient_id', $patient->getKey())->count())->toBe(1)
        ->and(ErEvent::query()->where('encounter_id', $encounter->getKey())->count())->toBe(1)
        ->and(ErEvent::query()->where('encounter_id', $encounter->getKey())->firstOrFail()->event_type)->toBe(ErEvent::TYPE_REGISTERED);

    // Audited — facts only (patientId/encounterId/timestamps).
    $audit = AuditEvent::query()->where('action', 'er.registered')->firstOrFail();
    expect($audit->payload)->toHaveKey('patientId', $patient->getKey())
        ->toHaveKey('encounterId', $encounter->getKey())
        ->toHaveKey('isUnidentified', false)
        ->not->toHaveKey('presentingComplaint');
});

it('registers a fully unidentified patient with a documented placeholder and estimated age', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    // An unidentified patient is registered by OMITTING the name (an empty
    // string is converted to null by the request middleware and fails the
    // string rule) — the documented minimal-data path.
    [$registration, $patient] = erRegister($this, $org, $facility, [
        'patientName' => null,
        'sex' => 'unknown',
        'estimatedAge' => 45,
        'presentingComplaint' => 'Found unconscious',
    ]);

    expect($registration['isUnidentified'])->toBeTrue()
        ->and($registration['estimatedAge'])->toBe(45)
        ->and($patient->full_name)->toBe('Unidentified')
        ->and($patient->sex)->toBe(Patient::SEX_UNKNOWN);

    // DOB is the sentinel when neither a DOB nor an age is provided.
    $dateOfBirth = $patient->date_of_birth;
    expect(Carbon::parse($dateOfBirth)->year)->toBe((int) now()->subYears(45)->year);
});

it('rejects contradictory registration facts and unknown fields', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $receptionist = Identity::user();
    erStaff($org, $facility, $receptionist, 'Receptionist');
    Identity::assign($receptionist, 'receptionist', $org, $facility);

    // dateOfBirth and estimatedAge are mutually exclusive → 422.
    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/er/registrations', [
            'patientName' => 'Ram',
            'dateOfBirth' => '1990-05-15',
            'estimatedAge' => 45,
        ])
        ->assertStatus(422);

    // Unknown field → 422 (strict input).
    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/er/registrations', [
            'patientName' => 'Ram',
            'isUnidentified' => true,
        ])
        ->assertStatus(422);

    expect(Patient::query()->count())->toBe(0)
        ->and(ErRegistration::query()->count())->toBe(0);
});

it('manages the triage scale catalog with CAS updates and facility scoping', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $facility2 = Identity::facility($org);
    $admin = Identity::user();
    erStaff($org, $facility, $admin, 'ER Lead');
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    // Create a level.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/er/triage-scales', [
            'facilityId' => $facility->getKey(),
            'code' => 'L1',
            'name' => 'Resuscitation',
            'level' => 1,
            'color' => 'red',
            'reassessmentMinutes' => 5,
            'isDefault' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.level', 1)
        ->assertJsonPath('data.status', 'active');

    $scale = TriageScale::query()->where('code', 'L1')->firstOrFail();

    // CAS update.
    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/er/triage-scales/'.$scale->getKey(), [
            'name' => 'Resus (updated)',
            'lockVersion' => $scale->lock_version,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Resus (updated)')
        ->assertJsonPath('data.lockVersion', 1);

    // Stale lockVersion → 409 and nothing changes.
    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/er/triage-scales/'.$scale->getKey(), [
            'name' => 'Stale write',
            'lockVersion' => 0,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'LOCK_CONFLICT');

    expect($scale->refresh()->name)->toBe('Resus (updated)');

    // The catalog list scopes to the caller's facility.
    erScale($org, $facility2, ['code' => 'X1', 'level' => 5]);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/er/triage-scales')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'L1');

    // Audited.
    expect(AuditEvent::query()->where('action', 'triage_scale.created')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'triage_scale.updated')->count())->toBe(1);
});

it('assigns and reassesses triage with a preserved superseded history (CAS)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $nurse = Identity::user();
    erStaff($org, $facility, $nurse, 'Staff Nurse');
    Identity::assign($nurse, 'nurse', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);
    $scale = erScale($org, $facility, ['level' => 2, 'code' => 'L2']);

    // First assessment.
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/triage', [
            'scaleId' => $scale->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.level', 2)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.isOverride', false);

    $first = TriageAssignment::query()->where('encounter_id', $encounter->getKey())->firstOrFail();

    // Reassessment with a more urgent level — the old row is superseded.
    $urgent = erScale($org, $facility, ['level' => 1, 'code' => 'L1']);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/triage', [
            'scaleId' => $urgent->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.level', 1);

    expect($first->refresh()->status)->toBe(TriageAssignment::STATUS_SUPERSEDED)
        ->and($first->lock_version)->toBe(1)
        ->and(TriageAssignment::query()->where('encounter_id', $encounter->getKey())->where('status', TriageAssignment::STATUS_ACTIVE)->count())->toBe(1);

    // Both assessments and the reassessment event are audited; the events
    // log carries the medico-legal timeline.
    expect(AuditEvent::query()->where('action', 'triage.assigned')->count())->toBe(2)
        ->and(ErEvent::query()->where('encounter_id', $encounter->getKey())->where('event_type', ErEvent::TYPE_TRIAGED)->count())->toBe(1)
        ->and(ErEvent::query()->where('encounter_id', $encounter->getKey())->where('event_type', ErEvent::TYPE_REASSESSED)->count())->toBe(1);
});

it('requires clinical authority for triage overrides', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $nurse = Identity::user();
    erStaff($org, $facility, $nurse, 'Staff Nurse');
    Identity::assign($nurse, 'nurse', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);
    $scale = erScale($org, $facility, ['level' => 3, 'code' => 'L3']);

    // A nurse (no er:disposition) cannot override.
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/triage', [
            'scaleId' => $scale->getKey(),
            'overrideReason' => 'Nurse thinks urgent',
        ])
        ->assertStatus(403);

    expect(TriageAssignment::query()->count())->toBe(0);

    // A doctor (er:disposition) can — audited separately.
    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/triage', [
            'scaleId' => $scale->getKey(),
            'overrideReason' => 'Clinical judgment overrides protocol',
        ])
        ->assertCreated()
        ->assertJsonPath('data.isOverride', true);

    expect(AuditEvent::query()->where('action', 'triage.overridden')->count())->toBe(1);
});

it('backstops concurrent triage with the partial unique: exactly one ACTIVE row per encounter', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $nurse = Identity::user();
    erStaff($org, $facility, $nurse, 'Staff Nurse');
    Identity::assign($nurse, 'nurse', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);
    $scale = erScale($org, $facility, ['level' => 3, 'code' => 'L3']);

    // The winner's active row commits first (a concurrent assignment that
    // beat the loser to the insert).
    TriageAssignment::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'encounter_id' => $encounter->getKey(),
        'patient_id' => $patient->getKey(),
        'triage_scale_id' => $scale->getKey(),
        'level' => 3,
        'color' => 'yellow',
        'assessed_by_staff_id' => erStaff($org, $facility, Identity::user(), 'Staff Nurse')->getKey(),
        'assessed_at' => now(),
        'is_override' => false,
        'status' => TriageAssignment::STATUS_ACTIVE,
        'lock_version' => 0,
    ]);

    // The DB backstop: a second ACTIVE row for the same encounter is
    // rejected by the partial unique — two actives can never coexist. The
    // insert happens inside a savepoint so the expected constraint failure
    // does not poison the test transaction.
    DB::statement('SAVEPOINT triage_race');
    $rejected = false;
    try {
        TriageAssignment::query()->create([
            'tenant_id' => $org->getKey(),
            'facility_id' => $facility->getKey(),
            'encounter_id' => $encounter->getKey(),
            'patient_id' => $patient->getKey(),
            'triage_scale_id' => $scale->getKey(),
            'level' => 3,
            'color' => 'yellow',
            'assessed_by_staff_id' => erStaff($org, $facility, Identity::user(), 'Staff Nurse')->getKey(),
            'assessed_at' => now(),
            'is_override' => false,
            'status' => TriageAssignment::STATUS_ACTIVE,
            'lock_version' => 0,
        ]);
    } catch (QueryException $e) {
        $rejected = true;
    }
    DB::statement('ROLLBACK TO SAVEPOINT triage_race');
    DB::statement('RELEASE SAVEPOINT triage_race');

    expect($rejected)->toBeTrue();

    // The re-triage HTTP path is the SUPERSEDE path (the documented
    // reassessment): the old active is CAS-superseded and the new row
    // becomes the single active.
    $urgent = erScale($org, $facility, ['level' => 1, 'code' => 'L1']);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/triage', [
            'scaleId' => $urgent->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.level', 1);

    expect(TriageAssignment::query()->where('encounter_id', $encounter->getKey())->where('status', TriageAssignment::STATUS_ACTIVE)->count())->toBe(1)
        ->and(TriageAssignment::query()->where('encounter_id', $encounter->getKey())->where('status', TriageAssignment::STATUS_SUPERSEDED)->count())->toBe(1);
});

it('appends time-stamped ER events and lists them chronologically (immutable)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $nurse = Identity::user();
    $nurseStaff = erStaff($org, $facility, $nurse, 'Staff Nurse');
    Identity::assign($nurse, 'nurse', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);

    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/events', [
            'eventType' => 'seen_by_doctor',
            'notes' => 'Distinctive-Event-Notes-XYZ',
        ])
        ->assertCreated()
        ->assertJsonPath('data.eventType', 'seen_by_doctor')
        ->assertJsonPath('data.actorStaffId', $nurseStaff->getKey());

    // Invalid event type → 422.
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/events', [
            'eventType' => 'nonsense',
        ])
        ->assertStatus(422);

    // Chronological list — registered event first, then the seen event.
    $this->withToken(Identity::tokenFor($nurse))
        ->getJson('/api/v1/er/encounters/'.$encounter->getKey().'/events')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.eventType', 'registered')
        ->assertJsonPath('data.1.eventType', 'seen_by_doctor');

    // Event notes are PHI — never in the audit payload.
    $audit = AuditEvent::query()->where('action', 'er.event')->firstOrFail();
    expect($audit->payload)->toHaveKey('eventType', 'seen_by_doctor')
        ->not->toHaveKey('notes');
});

it('returns the ER queue with triage-driven priority (level asc, untriaged last)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $nurse = Identity::user();
    erStaff($org, $facility, $nurse, 'Staff Nurse');
    Identity::assign($nurse, 'nurse', $org, $facility);

    [$registrationA, $patientA, $encounterA] = erRegister($this, $org, $facility, [
        'patientName' => 'Patient A',
        'presentingComplaint' => 'Chest pain',
    ]);

    // Patient B arrives later and is triaged URGENT (level 1).
    [$registrationB, $patientB, $encounterB] = erRegister($this, $org, $facility, [
        'patientName' => 'Patient B',
        'presentingComplaint' => 'Trauma',
    ]);

    $urgent = erScale($org, $facility, ['level' => 1, 'code' => 'L1']);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounterB->getKey().'/triage', [
            'scaleId' => $urgent->getKey(),
        ])
        ->assertCreated();

    // Patient A (untriaged) is at the end; patient B (urgent) first.
    $this->withToken(Identity::tokenFor($nurse))
        ->getJson('/api/v1/er/queue')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.patientId', $patientB->getKey())
        ->assertJsonPath('data.0.triageLevel', 1)
        ->assertJsonPath('data.1.patientId', $patientA->getKey())
        ->assertJsonPath('data.1.triageLevel', null);
});

it('admits an ER patient to IPD through the CAS bed claim (disposition admitted)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = Identity::user();
    $doctorStaff = erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);
    $bed = erBed($org, $facility);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'admitted',
            'bedId' => $bed->getKey(),
            'admittingDiagnosis' => 'Acute appendicitis',
        ])
        ->assertOk()
        ->assertJsonPath('data.encounter.disposition', 'admitted')
        ->assertJsonPath('data.encounter.status', 'open')
        ->assertJsonPath('data.admissionId', fn ($value) => $value !== null);

    // The admission exists with emergency type; the bed is claimed.
    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();
    expect($admission->admission_type)->toBe(Admission::TYPE_EMERGENCY)
        ->and($bed->refresh()->status)->toBe(BedStatus::OCCUPIED)
        ->and($bed->current_admission_id)->toBe($admission->getKey())
        ->and($bed->lock_version)->toBe(1);

    // The disposition event and the audit trail exist.
    expect(ErEvent::query()->where('encounter_id', $encounter->getKey())->where('event_type', ErEvent::TYPE_DISPOSITION)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'er.disposition')->count())->toBe(1);

    $audit = AuditEvent::query()->where('action', 'er.disposition')->firstOrFail();
    expect($audit->payload)->toHaveKey('disposition', 'admitted')
        ->toHaveKey('admissionId', $admission->getKey())
        ->not->toHaveKey('admittingDiagnosis');
});

it('discharges an ER patient home (disposition home closes the encounter)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'home',
            'notes' => 'Discharged with analgesia',
        ])
        ->assertOk()
        ->assertJsonPath('data.encounter.disposition', 'home')
        ->assertJsonPath('data.encounter.status', 'closed')
        ->assertJsonPath('data.admissionId', null);

    $fresh = $encounter->refresh();
    expect($fresh->status)->toBe(Encounter::STATUS_CLOSED)
        ->and($fresh->ended_at)->not->toBeNull()
        ->and($fresh->discharged_at)->not->toBeNull();

    expect(ErEvent::query()->where('encounter_id', $encounter->getKey())->where('event_type', ErEvent::TYPE_DISCHARGED)->count())->toBe(1);
});

it('validates dispositions: bed required for admit, unknown disposition rejected', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);

    // admitted without bedId → 422.
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'admitted',
        ])
        ->assertStatus(422);

    // Unknown disposition → 422.
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'beamed_up',
        ])
        ->assertStatus(422);

    // The encounter is untouched.
    expect($encounter->refresh()->disposition)->toBeNull()
        ->and(Admission::query()->count())->toBe(0);
});

it('refuses double disposition and stale (concurrent) disposition with 409', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'home',
        ])
        ->assertOk();

    // Double disposition → 409.
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'home',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // The CAS race: a concurrent disposer holding a STALE snapshot (already
    // superseded by a committed disposition) affects zero rows. The HTTP
    // layer always rebinds the fresh row, so the race is proven at the
    // service level with a stale model.
    $encounter2 = Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()])->getKey(),
        'provider_staff_id' => erStaff($org, $facility, Identity::user(), 'ER Physician')->getKey(),
        'type' => Encounter::TYPE_ER,
        'status' => Encounter::STATUS_OPEN,
        'lock_version' => 0,
    ]);

    $service = app(ErService::class);
    $actor = Staff::query()->where('tenant_id', $org->getKey())->where('user_id', $doctor->getKey())->firstOrFail();

    // Winner commits the disposition with lock_version 0 → 1.
    DB::table('encounters')
        ->where('id', $encounter2->getKey())
        ->update([
            'disposition' => Encounter::DISPOSITION_HOME,
            'status' => Encounter::STATUS_CLOSED,
            'lock_version' => 1,
        ]);

    // The stale disposer still holds lock_version 0 — the CAS affects zero
    // rows and the loser gets a LOCK_CONFLICT.
    expect(fn () => $service->dispose(
        $encounter2,
        Encounter::DISPOSITION_HOME,
        null,
        null,
        null,
        $actor,
    ))->toThrow(ApiException::class, 'disposed concurrently');

    expect($encounter2->refresh()->disposition)->toBe(Encounter::DISPOSITION_HOME)
        ->and($encounter2->lock_version)->toBe(1);
});

it('refuses ER operations on non-ER encounters and enforces RBAC across roles', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'Consultant Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    // An OPD encounter is not an ER encounter → 409 on the ER surface.
    $opd = Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'provider_staff_id' => erStaff($org, $facility, Identity::user(), 'Consultant Physician')->getKey(),
        'type' => Encounter::TYPE_OPD,
        'status' => Encounter::STATUS_OPEN,
    ]);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$opd->getKey().'/events', [
            'eventType' => 'other',
        ])
        ->assertStatus(409);

    // A pharmacist cannot register or triage (no er permission).
    $pharmacist = Identity::user();
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/er/registrations', [
            'patientName' => 'Ram',
        ])
        ->assertStatus(403);

    // A receptionist CAN register but CANNOT triage (triage:assign is
    // clinical — nurse/doctor).
    $receptionist = Identity::user();
    erStaff($org, $facility, $receptionist, 'Receptionist');
    Identity::assign($receptionist, 'receptionist', $org, $facility);
    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);

    $scale = erScale($org, $facility, ['level' => 3, 'code' => 'L3']);
    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/triage', [
            'scaleId' => $scale->getKey(),
        ])
        ->assertStatus(403);

    // A nurse CAN triage but CANNOT dispose (er:disposition is clinical
    // authority — doctor/admin).
    $nurse = Identity::user();
    erStaff($org, $facility, $nurse, 'Staff Nurse');
    Identity::assign($nurse, 'nurse', $org, $facility);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/triage', [
            'scaleId' => $scale->getKey(),
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'home',
        ])
        ->assertStatus(403);

    // Unauthenticated → 401.
    $this->flushHeaders();
    $this->postJson('/api/v1/er/registrations', [
        'patientName' => 'Ram',
    ])->assertStatus(401);
});

it('enforces cross-tenant isolation for the ER surface at the API layer', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    [$registration, $patientA, $encounterA] = erRegister($this, $orgA, $facilityA, [
        'patientName' => 'Distinctive-Patient-A-XYZ',
    ]);

    // Tenant-B staff attack tenant A's ER surface.
    $nurseB = Identity::user();
    erStaff($orgB, $facilityB, $nurseB, 'Staff Nurse');
    Identity::assign($nurseB, 'nurse', $orgB, $facilityB);

    $scaleB = erScale($orgB, $facilityB, ['level' => 1, 'code' => 'LB']);

    // Triage A's encounter with B's scale → 403 (scope) — and even if the
    // scale were visible, the encounter itself is out of scope.
    $this->withToken(Identity::tokenFor($nurseB))
        ->postJson('/api/v1/er/encounters/'.$encounterA->getKey().'/triage', [
            'scaleId' => $scaleB->getKey(),
        ])
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($nurseB))
        ->getJson('/api/v1/er/encounters/'.$encounterA->getKey().'/events')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($nurseB))
        ->postJson('/api/v1/er/encounters/'.$encounterA->getKey().'/events', [
            'eventType' => 'other',
            'notes' => 'Pwned',
        ])
        ->assertStatus(403);

    // Tenant B's queue is empty; tenant A's data is untouched.
    $this->withToken(Identity::tokenFor($nurseB))
        ->getJson('/api/v1/er/queue')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect(ErEvent::query()->where('encounter_id', $encounterA->getKey())->count())->toBe(1)
        ->and(TriageAssignment::query()->count())->toBe(0);
});

it('keeps patient identifiers and all clinical content out of ER audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility, [
        'patientName' => 'Distinctive-Patient-XYZ',
        'presentingComplaint' => 'Distinctive-Complaint-XYZ',
        'sex' => 'unknown',
        'estimatedAge' => 33,
    ]);

    $nurse = Identity::user();
    erStaff($org, $facility, $nurse, 'Staff Nurse');
    Identity::assign($nurse, 'nurse', $org, $facility);
    $scale = erScale($org, $facility, ['level' => 2, 'code' => 'L2']);

    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/triage', [
            'scaleId' => $scale->getKey(),
            'overrideReason' => 'Distinctive-Override-XYZ',
        ])
        ->assertStatus(403); // nurse cannot override — plain assign instead

    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/triage', [
            'scaleId' => $scale->getKey(),
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/events', [
            'eventType' => 'seen_by_doctor',
            'notes' => 'Distinctive-Event-Notes-XYZ',
        ])
        ->assertCreated();

    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'home',
            'notes' => 'Distinctive-Discharge-Notes-XYZ',
        ])
        ->assertOk();

    // No patient name, complaint, event notes, override reason, or
    // discharge notes in ANY audit payload.
    foreach (AuditEvent::query()->whereIn('action', [
        'er.registered', 'triage.assigned', 'er.event', 'er.disposition',
    ])->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain('Distinctive-Patient-XYZ')
            ->and($encoded)->not->toContain('Distinctive-Complaint-XYZ')
            ->and($encoded)->not->toContain('Distinctive-Override-XYZ')
            ->and($encoded)->not->toContain('Distinctive-Event-Notes-XYZ')
            ->and($encoded)->not->toContain('Distinctive-Discharge-Notes-XYZ');
    }

    // Facts are present: ids and timestamps.
    $registered = AuditEvent::query()->where('action', 'er.registered')->firstOrFail();
    expect($registered->payload)->toHaveKey('patientId', $patient->getKey())
        ->toHaveKey('encounterId', $encounter->getKey())
        ->toHaveKey('isUnidentified', false);
});

it('moves a patient to immediate treatment bypassing the queue', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);

    // Encounter starts open.
    expect($encounter->status)->toBe(Encounter::STATUS_OPEN);

    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/immediate-treatment', [
            'reason' => 'Cardiac arrest on arrival',
        ])
        ->assertOk();

    // Encounter is now in_progress.
    $encounter->refresh();
    expect($encounter->status)->toBe(Encounter::STATUS_IN_PROGRESS);

    // ER event recorded.
    $event = ErEvent::query()
        ->where('encounter_id', $encounter->getKey())
        ->where('event_type', ErEvent::TYPE_IMMEDIATE_TREATMENT)
        ->firstOrFail();
    expect($event->notes)->toBe('Cardiac arrest on arrival')
        ->and($event->actor_staff_id)->not->toBeNull();

    // Audited — facts only.
    $audit = AuditEvent::query()->where('action', 'er.immediate_treatment')->firstOrFail();
    expect($audit->payload)->toHaveKey('encounterId', $encounter->getKey())
        ->toHaveKey('patientId', $patient->getKey())
        ->not->toHaveKey('reason');
});

it('rejects immediate treatment without clinical authority', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);

    $nurse = Identity::user();
    erStaff($org, $facility, $nurse, 'Staff Nurse');
    Identity::assign($nurse, 'nurse', $org, $facility);

    // Nurse cannot grant immediate treatment — only er:disposition holders can.
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/immediate-treatment', [
            'reason' => 'Urgent',
        ])
        ->assertStatus(403);
});

it('rejects immediate treatment on disposed encounters', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);

    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    // Discharge first.
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'home',
        ])
        ->assertOk();

    // Now try immediate treatment — should fail (encounter is closed).
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/immediate-treatment', [
            'reason' => 'Too late',
        ])
        ->assertStatus(409);
});

it('reconciles an unidentified ER patient to an existing canonical patient', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    // Register an unidentified patient.
    [$registration, $erPatient, $encounter] = erRegister($this, $org, $facility, [
        'patientName' => null,
        'sex' => 'unknown',
        'estimatedAge' => 40,
        'presentingComplaint' => 'Unresponsive',
    ]);

    expect($registration['isUnidentified'])->toBeTrue();

    // Create the canonical patient record that this person is eventually identified as.
    $canonicalPatient = Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'full_name' => 'Sita Devi',
        'status' => Patient::STATUS_ACTIVE,
    ]);

    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/registrations/'.$registration['id'].'/reconcile-identity', [
            'targetPatientId' => $canonicalPatient->getKey(),
            'reason' => 'Wristband matched to existing MRN',
        ])
        ->assertOk();

    // ER patient record is now merged.
    $erPatient->refresh();
    expect($erPatient->status)->toBe(Patient::STATUS_MERGED)
        ->and($erPatient->merge_into_patient_id)->toBe($canonicalPatient->getKey());

    // Registration is stamped as completed.
    $reg = ErRegistration::query()->findOrFail($registration['id']);
    expect($reg->completed_at)->not->toBeNull()
        ->and($reg->completed_by)->not->toBeNull();

    // ER event recorded.
    $event = ErEvent::query()
        ->where('encounter_id', $encounter->getKey())
        ->where('event_type', ErEvent::TYPE_IDENTITY_RECONCILED)
        ->firstOrFail();
    expect($event->notes)->toBe('Wristband matched to existing MRN');

    // Canonical patient is still active.
    $canonicalPatient->refresh();
    expect($canonicalPatient->status)->toBe(Patient::STATUS_ACTIVE);
});

it('rejects identity reconciliation for already-completed registrations', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    [$registration, $erPatient, $encounter] = erRegister($this, $org, $facility, [
        'patientName' => null,
        'sex' => 'unknown',
        'estimatedAge' => 40,
    ]);

    $canonicalPatient = Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'status' => Patient::STATUS_ACTIVE,
    ]);

    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    // First reconciliation succeeds.
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/registrations/'.$registration['id'].'/reconcile-identity', [
            'targetPatientId' => $canonicalPatient->getKey(),
        ])
        ->assertOk();

    // Second attempt fails — already completed.
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/registrations/'.$registration['id'].'/reconcile-identity', [
            'targetPatientId' => $canonicalPatient->getKey(),
        ])
        ->assertStatus(409);
});

it('rejects identity reconciliation for non-unidentified registrations', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    // Register a KNOWN patient (not unidentified).
    [$registration, $patient, $encounter] = erRegister($this, $org, $facility, [
        'patientName' => 'Known Patient',
    ]);

    expect($registration['isUnidentified'])->toBeFalse();

    $targetPatient = Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'status' => Patient::STATUS_ACTIVE,
    ]);

    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/registrations/'.$registration['id'].'/reconcile-identity', [
            'targetPatientId' => $targetPatient->getKey(),
        ])
        ->assertStatus(409);
});

it('returns the ER dashboard with operational counts and enforces er:view', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    // Register two ER patients; triage one; discharge one.
    [$registrationA, $patientA, $encounterA] = erRegister($this, $org, $facility, ['patientName' => 'Dash Patient A']);
    [$registrationB, $patientB, $encounterB] = erRegister($this, $org, $facility, ['patientName' => 'Dash Patient B']);

    $scale = erScale($org, $facility, ['level' => 1, 'code' => 'DASH1']);
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounterB->getKey().'/triage', ['scaleId' => $scale->getKey()])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounterA->getKey().'/disposition', ['disposition' => 'home'])
        ->assertOk();

    // Server-truth aggregate: 1 open, 0 in_progress, 1 closed, total 2;
    // triage distribution counts the active level for open encounters;
    // average waiting time is a number for open encounters.
    $this->withToken(Identity::tokenFor($doctor))
        ->getJson('/api/v1/er/dashboard')
        ->assertOk()
        ->assertJsonPath('data.statusCounts.open', 1)
        ->assertJsonPath('data.statusCounts.inProgress', 0)
        ->assertJsonPath('data.statusCounts.closed', 1)
        ->assertJsonPath('data.statusCounts.total', 2)
        ->assertJsonPath('data.triageDistribution.level_1', 1)
        ->assertJsonPath('data.avgWaitingMinutes', fn ($value) => is_int($value) || is_float($value) || $value === null);

    // No PHI: patient names and complaints never appear on the dashboard.
    $payload = json_encode($this->withToken(Identity::tokenFor($doctor))
        ->getJson('/api/v1/er/dashboard')->json());
    expect($payload)->not->toContain('Dash Patient A')
        ->and($payload)->not->toContain('Dash Patient B');

    // A pharmacist (no er:view) is denied.
    $pharmacist = Identity::user();
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->getJson('/api/v1/er/dashboard')
        ->assertStatus(403);
});

it('exposes registration facts (id, unidentified flag, complaint) on the queue for the workspace', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    $nurse = Identity::user();
    erStaff($org, $facility, $nurse, 'Staff Nurse');
    Identity::assign($nurse, 'nurse', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility, [
        'patientName' => 'Queue-Facts-Patient',
        'presentingComplaint' => 'Abdominal pain',
    ]);

    $this->withToken(Identity::tokenFor($nurse))
        ->getJson('/api/v1/er/queue')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.registrationId', $registration['id'])
        ->assertJsonPath('data.0.encounterId', $encounter->getKey())
        ->assertJsonPath('data.0.patientId', $patient->getKey())
        ->assertJsonPath('data.0.presentingComplaint', 'Abdominal pain')
        ->assertJsonPath('data.0.isUnidentified', false);

    // The unidentified path is also exposed so the workspace can offer
    // identity reconciliation for exactly those registrations.
    [$anon] = erRegister($this, $org, $facility, [
        'patientName' => null,
        'estimatedAge' => 40,
        'presentingComplaint' => 'Unresponsive',
    ]);

    // Both are untriaged → arrival order (registered_at); the unidentified
    // registration is the second arrival.
    $this->withToken(Identity::tokenFor($nurse))
        ->getJson('/api/v1/er/queue')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.isUnidentified', true)
        ->assertJsonPath('data.1.registrationId', $anon['id']);
});

it('disposes a deceased patient: encounter closes with the deceased disposition', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'deceased',
            'notes' => 'Resuscitation ceased',
        ])
        ->assertOk()
        ->assertJsonPath('data.encounter.disposition', 'deceased')
        ->assertJsonPath('data.encounter.status', 'closed')
        ->assertJsonPath('data.admissionId', null);

    $fresh = $encounter->refresh();
    expect($fresh->status)->toBe(Encounter::STATUS_CLOSED)
        ->and($fresh->ended_at)->not->toBeNull();

    // No admission, no bed claimed, disposition event recorded.
    expect(Admission::query()->count())->toBe(0)
        ->and(ErEvent::query()->where('encounter_id', $encounter->getKey())->where('event_type', ErEvent::TYPE_DISPOSITION)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'er.disposition')->count())->toBe(1);
});

it('cannot create a second admission when an ER-to-IPD disposition is retried', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = Identity::user();
    erStaff($org, $facility, $doctor, 'ER Physician');
    Identity::assign($doctor, 'doctor', $org, $facility);

    [$registration, $patient, $encounter] = erRegister($this, $org, $facility);
    $bed = erBed($org, $facility);

    // First disposition: admitted → admission + bed claim.
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'admitted',
            'bedId' => $bed->getKey(),
            'admittingDiagnosis' => 'Acute pancreatitis',
        ])
        ->assertOk();

    expect(Admission::query()->count())->toBe(1);

    // Retry (client resend / worker retry): the encounter is already
    // disposed → 409, and NO second admission or double bed claim happens.
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/er/encounters/'.$encounter->getKey().'/disposition', [
            'disposition' => 'admitted',
            'bedId' => $bed->getKey(),
            'admittingDiagnosis' => 'Acute pancreatitis',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(Admission::query()->count())->toBe(1)
        ->and($bed->refresh()->current_admission_id)->not->toBeNull();
});

it('blocks cross-facility ER access within the same tenant (facility isolation)', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org);
    $facilityB = Identity::facility($org);

    [$registration, $patientA, $encounterA] = erRegister($this, $org, $facilityA, [
        'patientName' => 'Facility-A-Patient',
    ]);

    // A doctor scoped to facility B attempts to reach facility A's ER record.
    $doctorB = Identity::user();
    erStaff($org, $facilityB, $doctorB, 'ER Physician');
    Identity::assign($doctorB, 'doctor', $org, $facilityB);

    // Queue is facility-scoped: facility B sees no facility A patients.
    $this->withToken(Identity::tokenFor($doctorB))
        ->getJson('/api/v1/er/queue')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Direct object access to A's encounter/events is denied.
    $this->withToken(Identity::tokenFor($doctorB))
        ->getJson('/api/v1/er/encounters/'.$encounterA->getKey().'/events')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($doctorB))
        ->postJson('/api/v1/er/encounters/'.$encounterA->getKey().'/events', [
            'eventType' => 'seen_by_doctor',
        ])
        ->assertStatus(403);

    // Disposition of A's encounter by a B-scoped doctor is denied.
    $this->withToken(Identity::tokenFor($doctorB))
        ->postJson('/api/v1/er/encounters/'.$encounterA->getKey().'/disposition', [
            'disposition' => 'home',
        ])
        ->assertStatus(403);

    // A's data is untouched.
    expect(ErEvent::query()->where('encounter_id', $encounterA->getKey())->count())->toBe(1)
        ->and($encounterA->refresh()->disposition)->toBeNull();
});
