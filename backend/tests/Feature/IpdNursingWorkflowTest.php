<?php

use App\Models\Admission;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\ClinicalNote;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\MarEntry;
use App\Models\Medication;
use App\Models\NursingNote;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Room;
use App\Models\Staff;
use App\Models\TransferEvent;
use App\Models\User;
use App\Models\VitalObservation;
use App\Models\Ward;
use App\Support\BedStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Phase 3 slice 13 — the remaining documented IPD workflow (ROADMAP Phase 8,
 * PRODUCT_REQUIREMENTS §6.5): audited bed/ward transfers with a preserved
 * historical bed timeline, nursing notes (draft → signed), vital
 * observations, and MAR administration (scheduled dose → given/refused/
 * missed/held). Bed claims and every transition are CAS-guarded; the
 * discharge-summary release (slice 6) now also works after a transfer.
 */
beforeEach(function (): void {
    seedIdentity();
});

function ipdStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
{
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $user->getKey(),
        'designation' => $designation,
        'status' => 'active',
    ]);
}

function ipdEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
{
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'type' => Encounter::TYPE_IPD,
        'status' => Encounter::STATUS_OPEN,
    ]);
}

function ipdBed(Organization $org, Facility $facility, string $status = BedStatus::AVAILABLE): Bed
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
        'status' => $status,
        'lock_version' => 0,
    ]);
}

function ipdAdmit(TestCase $test, Organization $org, Facility $facility, User $doctorUser, Bed $bed): Admission
{
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    $encounter = ipdEncounter($org, $facility, ipdStaff($org, $facility, $doctorUser, 'Consultant Physician'));

    $test->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $bed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Acute observation',
        ])
        ->assertCreated();

    return Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();
}

/**
 * An ORDERED prescription line for the admission's own patient (the MAR
 * source). Creates its own encounter/prescription aligned to org+facility.
 */
function ipdMarLine(Organization $org, Facility $facility, Patient $patient, string $status = PrescriptionLine::STATUS_ORDERED): PrescriptionLine
{
    $doctor = ipdStaff($org, $facility, Identity::user(), 'Consultant Physician');
    $encounter = Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'type' => Encounter::TYPE_IPD,
        'status' => Encounter::STATUS_OPEN,
    ]);

    $prescription = Prescription::factory()->create([
        'tenant_id' => $org->getKey(),
        'encounter_id' => $encounter->getKey(),
        'patient_id' => $patient->getKey(),
        'prescriber_staff_id' => $doctor->getKey(),
        'status' => Prescription::STATUS_ACTIVE,
    ]);

    return PrescriptionLine::factory()->create([
        'tenant_id' => $org->getKey(),
        'prescription_id' => $prescription->getKey(),
        'status' => $status,
        'line_no' => 1,
    ]);
}

it('transfers an admitted patient between beds with a captured reason and an audited timeline', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = ipdStaff($org, $facility, $doctorUser, 'Consultant Physician');
    $encounter = ipdEncounter($org, $facility, $doctor);
    $fromBed = ipdBed($org, $facility);
    $toBed = ipdBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $fromBed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Observation',
        ])
        ->assertCreated();

    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => $toBed->getKey(),
            'reason' => 'Escalation to monitored bed',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'transferred');

    $fresh = Admission::query()->findOrFail($admission->getKey());
    expect($fresh->status)->toBe(Admission::STATUS_TRANSFERRED)
        ->and($fresh->lock_version)->toBe(1);

    // The vacated bed goes to cleaning (never immediately reassignable)…
    expect($fromBed->refresh()->status)->toBe(BedStatus::CLEANING)
        ->and($fromBed->current_admission_id)->toBeNull();

    // …and the target bed is occupied by this admission.
    expect($toBed->refresh()->status)->toBe(BedStatus::OCCUPIED)
        ->and($toBed->current_admission_id)->toBe($admission->getKey())
        ->and($toBed->lock_version)->toBe(1);

    // The immutable transfer event preserves the timeline: from → to, with
    // the reason and authorizing doctor.
    $event = TransferEvent::query()->where('admission_id', $admission->getKey())->firstOrFail();
    expect($event->from_bed_id)->toBe($fromBed->getKey())
        ->and($event->to_bed_id)->toBe($toBed->getKey())
        ->and($event->reason)->toBe('Escalation to monitored bed')
        ->and($event->transferred_by)->toBe($doctor->getKey())
        ->and($event->transferred_at)->not->toBeNull();

    // The GET timeline returns it.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/admissions/'.$admission->getKey().'/transfers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fromBedId', $fromBed->getKey())
        ->assertJsonPath('data.0.toBedId', $toBed->getKey());

    expect(AuditEvent::query()->where('action', 'admission.transferred')->count())->toBe(1);
});

it('refuses invalid transfers: occupied/same bed, discharged admission, unknown bed', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = ipdStaff($org, $facility, $doctorUser, 'Consultant Physician');
    $encounter = ipdEncounter($org, $facility, $doctor);
    $fromBed = ipdBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $fromBed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Observation',
        ])
        ->assertCreated();

    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();

    // Same bed → 409.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => $fromBed->getKey(),
            'reason' => 'Nowhere to go',
        ])
        ->assertStatus(409);

    // Occupied target → 409.
    $occupied = ipdBed($org, $facility, BedStatus::OCCUPIED);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => $occupied->getKey(),
            'reason' => 'To occupied',
        ])
        ->assertStatus(409);

    // A bed in another facility → 404 (existence hidden).
    $facilityB = Identity::facility($org);
    $bedB = ipdBed($org, $facilityB);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => $bedB->getKey(),
            'reason' => 'Cross facility',
        ])
        ->assertStatus(404);

    // Unknown bed → 404; missing reason → 422.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => (string) Str::uuid(),
            'reason' => 'Ghost',
        ])
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => $occupied->getKey(),
        ])
        ->assertStatus(422);

    // A discharged admission cannot be transferred → 409.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/discharge', [
            'dischargeType' => 'home',
            'summary' => ['diagnoses' => ['Test']],
        ])
        ->assertOk();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => (string) Str::uuid(),
            'reason' => 'Too late',
        ])
        ->assertStatus(409);
});

it('wins the transfer bed-claim race via the compare-and-swap', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = ipdStaff($org, $facility, $doctorUser, 'Consultant Physician');
    $encounter = ipdEncounter($org, $facility, $doctor);
    $fromBed = ipdBed($org, $facility);
    $toBed = ipdBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $fromBed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Race',
        ])
        ->assertCreated();

    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();

    // A competing admission claims the SAME target bed first: the winner's
    // CAS commits, the stale transferer affects zero rows.
    $competitor = Admission::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()])->getKey(),
        'encounter_id' => ipdEncounter($org, $facility, ipdStaff($org, $facility, Identity::user(), 'Consultant Physician'))->getKey(),
        'admission_number' => 'ADM-RACE-2',
        'admission_type' => 'emergency',
        'admitting_diagnosis' => 'Competitor',
        'admitted_at' => now(),
        'status' => Admission::STATUS_ADMITTED,
        'lock_version' => 0,
    ]);

    $winner = DB::table('beds')
        ->where('id', $toBed->getKey())
        ->where('status', BedStatus::AVAILABLE)
        ->whereNull('current_admission_id')
        ->where('lock_version', $toBed->lock_version)
        ->update([
            'status' => BedStatus::OCCUPIED,
            'current_admission_id' => $competitor->getKey(),
            'lock_version' => $toBed->lock_version + 1,
        ]);

    expect($winner)->toBe(1);

    // The losing HTTP transfer arrives after the winner committed: it must
    // fail with CONFLICT, book nothing, and move nothing.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => $toBed->getKey(),
            'reason' => 'Too late',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(TransferEvent::query()->count())->toBe(0)
        ->and($admission->refresh()->status)->toBe(Admission::STATUS_ADMITTED)
        ->and($fromBed->refresh()->current_admission_id)->toBe($admission->getKey());
});

it('discharges a transferred admission and releases the NEW bed', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = ipdStaff($org, $facility, $doctorUser, 'Consultant Physician');
    $encounter = ipdEncounter($org, $facility, $doctor);
    $fromBed = ipdBed($org, $facility);
    $toBed = ipdBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $fromBed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Observation',
        ])
        ->assertCreated();

    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => $toBed->getKey(),
            'reason' => 'Moved to monitored bed',
        ])
        ->assertOk();

    // Discharge after transfer: the CURRENT (new) bed is released.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/discharge', [
            'dischargeType' => 'home',
            'summary' => ['diagnoses' => ['Observation complete']],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'discharged');

    $fresh = Admission::query()->findOrFail($admission->getKey());
    $note = ClinicalNote::query()->findOrFail($fresh->discharge_summary_id);
    expect($note->note_type)->toBe(ClinicalNote::TYPE_DISCHARGE);

    expect($toBed->refresh()->status)->toBe(BedStatus::CLEANING)
        ->and($toBed->current_admission_id)->toBeNull();
});

it('enforces RBAC for transfers and nursing acts', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = ipdStaff($org, $facility, $doctorUser, 'Consultant Physician');
    $encounter = ipdEncounter($org, $facility, $doctor);
    $fromBed = ipdBed($org, $facility);
    $toBed = ipdBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $fromBed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'RBAC',
        ])
        ->assertCreated();

    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();

    // A nurse CANNOT transfer (admission:transfer is doctor/admin only).
    $nurseUser = Identity::user();
    ipdStaff($org, $facility, $nurseUser, 'Staff Nurse');
    Identity::assign($nurseUser, 'nurse', $org, $facility);
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => $toBed->getKey(),
            'reason' => 'Nurse attempt',
        ])
        ->assertStatus(403);

    // A nurse CAN document and administer MAR; a pharmacist CANNOT.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/nursing-notes', [
            'content' => ['observation' => 'Stable'],
        ])
        ->assertCreated();

    $pharmacistUser = Identity::user();
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/nursing-notes', [
            'content' => ['observation' => 'Nope'],
        ])
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/vitals', [
            'type' => 'bp',
            'value' => ['systolic' => 120, 'diastolic' => 80],
        ])
        ->assertStatus(403);

    // A doctor CANNOT administer MAR (mar:administer is the nurse's act).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/mar', [
            'prescriptionLineId' => (string) Str::uuid(),
            'scheduledAt' => now()->addHour()->toIso8601String(),
        ])
        ->assertStatus(403);

    // Unauthenticated → 401.
    $this->flushHeaders();
    $this->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
        'toBedId' => $toBed->getKey(),
        'reason' => 'Anonymous',
    ])->assertStatus(401);
});

it('creates and signs nursing notes (author-only, once) and lists them', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $nurseUser = Identity::user();
    $nurse = ipdStaff($org, $facility, $nurseUser, 'Staff Nurse');
    Identity::assign($nurseUser, 'nurse', $org, $facility);

    $admission = ipdAdmit($this, $org, $facility, Identity::user(), ipdBed($org, $facility));
    $otherNurseUser = Identity::user();
    ipdStaff($org, $facility, $otherNurseUser, 'Staff Nurse');
    Identity::assign($otherNurseUser, 'nurse', $org, $facility);

    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/nursing-notes', [
            'content' => ['observation' => 'Patient resting', 'intervention' => 'IV fluids running'],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.authorStaffId', $nurse->getKey());

    $note = NursingNote::query()->where('admission_id', $admission->getKey())->firstOrFail();

    // A different nurse cannot sign someone else's note → 403.
    $this->withToken(Identity::tokenFor($otherNurseUser))
        ->postJson('/api/v1/nursing-notes/'.$note->getKey().'/sign')
        ->assertStatus(403);

    // The author signs it — immutably.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/nursing-notes/'.$note->getKey().'/sign')
        ->assertOk()
        ->assertJsonPath('data.status', 'signed')
        ->assertJsonPath('data.signedAt', $note->refresh()->signed_at?->toIso8601String());

    // Re-signing → 409.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/nursing-notes/'.$note->getKey().'/sign')
        ->assertStatus(409);

    // The admission's note list returns it.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->getJson('/api/v1/admissions/'.$admission->getKey().'/nursing-notes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'signed');

    expect(AuditEvent::query()->where('action', 'nursing_note.created')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'nursing_note.signed')->count())->toBe(1);
});

it('validates vital value shapes per type and lists the admission observations', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $nurseUser = Identity::user();
    ipdStaff($org, $facility, $nurseUser, 'Staff Nurse');
    Identity::assign($nurseUser, 'nurse', $org, $facility);

    $admission = ipdAdmit($this, $org, $facility, Identity::user(), ipdBed($org, $facility));

    // Valid BP.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/vitals', [
            'type' => 'bp',
            'value' => ['systolic' => 120, 'diastolic' => 80],
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'bp')
        ->assertJsonPath('data.value.systolic', 120)
        ->assertJsonPath('data.patientId', $admission->patient_id);

    // Valid temp with unit.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/vitals', [
            'type' => 'temp',
            'value' => ['value' => 37.2, 'unit' => 'c'],
        ])
        ->assertCreated();

    // Malformed BP (missing diastolic) → 422; unknown type → 422; isAbnormal
    // is never client-supplied (unknown-field rejection).
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/vitals', [
            'type' => 'bp',
            'value' => ['systolic' => 120],
        ])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/vitals', [
            'type' => 'glucose',
            'value' => ['value' => 90],
        ])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/vitals', [
            'type' => 'bp',
            'value' => ['systolic' => 120, 'diastolic' => 80],
            'isAbnormal' => true,
        ])
        ->assertStatus(422);

    // The list is chronological.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->getJson('/api/v1/admissions/'.$admission->getKey().'/vitals')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'bp');

    expect(AuditEvent::query()->where('action', 'vital_observation.recorded')->count())->toBe(2);
});

it('schedules and administers MAR doses with identity confirmation and reasons', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $nurseUser = Identity::user();
    $nurse = ipdStaff($org, $facility, $nurseUser, 'Staff Nurse');
    Identity::assign($nurseUser, 'nurse', $org, $facility);

    $admission = ipdAdmit($this, $org, $facility, Identity::user(), ipdBed($org, $facility));
    $line = ipdMarLine($org, $facility, $admission->patient);
    $dueAt = now()->addHour()->startOfHour()->toIso8601String();

    // Schedule a dose.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/mar', [
            'prescriptionLineId' => $line->getKey(),
            'scheduledAt' => $dueAt,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.prescriptionLineId', $line->getKey());

    $entry = MarEntry::query()->where('admission_id', $admission->getKey())->firstOrFail();

    // Administer 'given' — identity re-confirmation REQUIRED.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/mar-entries/'.$entry->getKey().'/administer', [
            'status' => 'given',
            'identityConfirmed' => false,
        ])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/mar-entries/'.$entry->getKey().'/administer', [
            'status' => 'given',
            'identityConfirmed' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'given')
        ->assertJsonPath('data.administeredBy', $nurse->getKey())
        ->assertJsonPath('data.administeredAt', $entry->refresh()->administered_at?->toIso8601String());

    // Double administration → 409.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/mar-entries/'.$entry->getKey().'/administer', [
            'status' => 'given',
            'identityConfirmed' => true,
        ])
        ->assertStatus(409);

    // A second scheduled dose at the same time → 409 (unique per line+time).
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/mar', [
            'prescriptionLineId' => $line->getKey(),
            'scheduledAt' => $dueAt,
        ])
        ->assertStatus(409);

    // Refused requires a reason.
    $line2 = ipdMarLine($org, $facility, $admission->patient);
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/mar', [
            'prescriptionLineId' => $line2->getKey(),
            'scheduledAt' => now()->addHours(2)->startOfHour()->toIso8601String(),
        ])
        ->assertCreated();

    $entry2 = MarEntry::query()->where('prescription_line_id', $line2->getKey())->firstOrFail();

    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/mar-entries/'.$entry2->getKey().'/administer', [
            'status' => 'refused',
            'identityConfirmed' => false,
        ])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/mar-entries/'.$entry2->getKey().'/administer', [
            'status' => 'refused',
            'reason' => 'Patient declined',
            'identityConfirmed' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'refused');

    // The MAR list shows both entries in scheduled order.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->getJson('/api/v1/admissions/'.$admission->getKey().'/mar')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(AuditEvent::query()->where('action', 'mar_entry.scheduled')->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'mar_entry.administered')->count())->toBe(2);
});

it('refuses MAR doses from a cancelled line, another patient, or another tenant', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $nurseUser = Identity::user();
    ipdStaff($org, $facility, $nurseUser, 'Staff Nurse');
    Identity::assign($nurseUser, 'nurse', $org, $facility);

    $admission = ipdAdmit($this, $org, $facility, Identity::user(), ipdBed($org, $facility));
    $dueAt = now()->addHour()->startOfHour()->toIso8601String();

    // Cancelled line → 409.
    $cancelledLine = ipdMarLine($org, $facility, $admission->patient, PrescriptionLine::STATUS_CANCELLED);
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/mar', [
            'prescriptionLineId' => $cancelledLine->getKey(),
            'scheduledAt' => $dueAt,
        ])
        ->assertStatus(409);

    // A line prescribed for a DIFFERENT patient → 409.
    $otherPatient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $foreignLine = ipdMarLine($org, $facility, $otherPatient);
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/mar', [
            'prescriptionLineId' => $foreignLine->getKey(),
            'scheduledAt' => $dueAt,
        ])
        ->assertStatus(409);

    // A line in another tenant → 404 (existence hidden).
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $patientB = Patient::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    $lineB = ipdMarLine($orgB, $facilityB, $patientB);
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/mar', [
            'prescriptionLineId' => $lineB->getKey(),
            'scheduledAt' => $dueAt,
        ])
        ->assertStatus(404);

    // Nothing was scheduled.
    expect(MarEntry::query()->count())->toBe(0);
});

it('enforces cross-tenant and cross-facility isolation for the nursing surface', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $admissionA = ipdAdmit($this, $orgA, $facilityA, Identity::user(), ipdBed($orgA, $facilityA));

    // Tenant-B nurse attacks tenant A's admission.
    $nurseBUser = Identity::user();
    ipdStaff($orgB, $facilityB, $nurseBUser, 'Staff Nurse');
    Identity::assign($nurseBUser, 'nurse', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($nurseBUser))
        ->postJson('/api/v1/admissions/'.$admissionA->getKey().'/nursing-notes', [
            'content' => ['observation' => 'Pwned'],
        ])
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($nurseBUser))
        ->getJson('/api/v1/admissions/'.$admissionA->getKey().'/nursing-notes')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($nurseBUser))
        ->postJson('/api/v1/admissions/'.$admissionA->getKey().'/vitals', [
            'type' => 'bp',
            'value' => ['systolic' => 120, 'diastolic' => 80],
        ])
        ->assertStatus(403);

    // Tenant-B doctor attacks tenant A's transfer surface.
    $doctorBUser = Identity::user();
    ipdStaff($orgB, $facilityB, $doctorBUser, 'Consultant Physician');
    Identity::assign($doctorBUser, 'doctor', $orgB, $facilityB);
    $bedB = ipdBed($orgB, $facilityB);

    $this->withToken(Identity::tokenFor($doctorBUser))
        ->postJson('/api/v1/admissions/'.$admissionA->getKey().'/transfer', [
            'toBedId' => $bedB->getKey(),
            'reason' => 'Attack',
        ])
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($doctorBUser))
        ->getJson('/api/v1/admissions/'.$admissionA->getKey().'/transfers')
        ->assertStatus(404);

    // Tenant A's data is untouched.
    expect($admissionA->refresh()->status)->toBe(Admission::STATUS_ADMITTED)
        ->and(NursingNote::query()->count())->toBe(0)
        ->and(VitalObservation::query()->count())->toBe(0)
        ->and(TransferEvent::query()->count())->toBe(0);
});

it('keeps all patient identifiers and clinical content out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = ipdStaff($org, $facility, $doctorUser, 'Consultant Physician');
    $encounter = ipdEncounter($org, $facility, $doctor);
    $fromBed = ipdBed($org, $facility);
    $toBed = ipdBed($org, $facility);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/admissions', [
            'bedId' => $fromBed->getKey(),
            'admissionType' => 'emergency',
            'admittingDiagnosis' => 'Distinctive-Diagnosis-XYZ',
        ])
        ->assertCreated();

    $admission = Admission::query()->where('encounter_id', $encounter->getKey())->firstOrFail();
    $patient = $admission->patient;
    $patientName = $patient->full_name;

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/transfer', [
            'toBedId' => $toBed->getKey(),
            'reason' => 'Distinctive-Transfer-Reason-XYZ',
        ])
        ->assertOk();

    $nurseUser = Identity::user();
    ipdStaff($org, $facility, $nurseUser, 'Staff Nurse');
    Identity::assign($nurseUser, 'nurse', $org, $facility);

    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/nursing-notes', [
            'content' => ['observation' => 'Distinctive-Note-Content-XYZ'],
        ])
        ->assertCreated();

    $note = NursingNote::query()->where('admission_id', $admission->getKey())->firstOrFail();
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/nursing-notes/'.$note->getKey().'/sign')
        ->assertOk();

    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/vitals', [
            'type' => 'bp',
            'value' => ['systolic' => 177, 'diastolic' => 111],
        ])
        ->assertCreated();

    $line = ipdMarLine($org, $facility, $admission->patient);
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/admissions/'.$admission->getKey().'/mar', [
            'prescriptionLineId' => $line->getKey(),
            'scheduledAt' => now()->addHour()->startOfHour()->toIso8601String(),
        ])
        ->assertCreated();

    $entry = MarEntry::query()->where('admission_id', $admission->getKey())->firstOrFail();
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/mar-entries/'.$entry->getKey().'/administer', [
            'status' => 'held',
            'reason' => 'Distinctive-Hold-Reason-XYZ',
            'identityConfirmed' => false,
        ])
        ->assertOk();

    // No patient name, no note content, no vital value, no reason text, no
    // admitting diagnosis, and no medication name in ANY audit payload.
    $actions = [
        'admission.admitted', 'admission.transferred',
        'nursing_note.created', 'nursing_note.signed',
        'vital_observation.recorded',
        'mar_entry.scheduled', 'mar_entry.administered',
    ];

    foreach (AuditEvent::query()->whereIn('action', $actions)->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain($patientName)
            ->and($encoded)->not->toContain('Distinctive-Note-Content-XYZ')
            ->and($encoded)->not->toContain('177')
            ->and($encoded)->not->toContain('Distinctive-Hold-Reason-XYZ')
            ->and($encoded)->not->toContain('Distinctive-Transfer-Reason-XYZ')
            ->and($encoded)->not->toContain('Distinctive-Diagnosis-XYZ');
    }

    // Facts are present: ids and timestamps, never clinical values.
    $administered = AuditEvent::query()->where('action', 'mar_entry.administered')->firstOrFail();
    expect($administered->payload)
        ->toHaveKey('prescriptionLineId', $line->getKey())
        ->toHaveKey('status', 'held');
});
