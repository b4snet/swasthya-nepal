<?php

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\ClinicalNote;
use App\Models\Department;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Staff;
use App\Models\User;
use Tests\Support\Identity;

/**
 * Phase 7 — OPD clinical spine (DATABASE.md §3.17–3.21): start from a
 * checked-in appointment, document, diagnose, prescribe, sign (immutable),
 * and audit every step.
 */
beforeEach(function (): void {
    seedIdentity();
});

function doctorWithUser(Organization $org, Facility $facility, User $user): Staff
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

function checkedInAppointment(Organization $org, Facility $facility, Staff $doctor): Appointment
{
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $appointment = Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'starts_at' => now()->addMinutes(5),
        'ends_at' => now()->addMinutes(20),
        'status' => 'checked_in',
    ]);

    return $appointment;
}

it('starts an encounter from a checked-in appointment and moves it to in-consultation', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = doctorWithUser($org, $facility, $doctorUser);
    $appointment = checkedInAppointment($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/start-encounter')
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.patientId', $appointment->patient_id)
        ->assertJsonPath('data.appointmentId', $appointment->getKey())
        ->assertJsonPath('data.providerStaffId', $doctor->getKey());

    expect($appointment->refresh()->status)->toBe('in_consultation')
        ->and(Encounter::query()->count())->toBe(1);

    // A second start on the same appointment is refused (one per appointment).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/start-encounter')
        ->assertStatus(409);
});

it('documents a note, diagnoses, and a prescription on an open encounter', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = doctorWithUser($org, $facility, $doctorUser);
    $appointment = checkedInAppointment($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $encounterId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/start-encounter')
        ->json('data.id');

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/notes', [
            'content' => [
                'complaint' => 'Fever and cough for 3 days',
                'history' => 'Non-smoker, no known allergies',
                'examination' => 'Temp 101F, throat congested',
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.author.id', $doctor->getKey());

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/diagnoses', [
            'code' => 'J06.9',
            'codingSystem' => 'icd10',
            'description' => 'Acute upper respiratory infection',
            'diagnosisType' => 'final',
            'isPrimary' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'J06.9');

    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'generic_name' => 'Paracetamol',
        'strength' => '500 mg',
        'price_minor' => 2000,
    ]);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/prescriptions', [
            'notes' => 'Take after food',
            'lines' => [
                [
                    'medicationId' => $medication->getKey(),
                    'dose' => '1 tablet',
                    'route' => 'oral',
                    'frequency' => 'three times daily',
                    'duration' => '5 days',
                    'quantityMinor' => 15,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.lineCount', 1)
        ->assertJsonPath('data.lines.0.medication.genericName', 'Paracetamol');

    expect(Diagnosis::query()->count())->toBe(1)
        ->and(ClinicalNote::query()->count())->toBe(1)
        ->and(Prescription::query()->count())->toBe(1);
});

it('signs the note and the encounter, then refuses further edits', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = doctorWithUser($org, $facility, $doctorUser);
    $appointment = checkedInAppointment($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $encounterId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/start-encounter')
        ->json('data.id');

    $noteId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/notes', [
            'content' => ['complaint' => 'Headache since morning'],
        ])
        ->json('data.id');

    // Signing without a signed note is refused.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/sign')
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/notes/'.$noteId.'/sign')
        ->assertOk()
        ->assertJsonPath('data.status', 'signed');

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/sign')
        ->assertOk()
        ->assertJsonPath('data.status', 'signed');

    // The appointment completes with the encounter.
    expect($appointment->refresh()->status)->toBe('completed');

    // Signed encounters are immutable: no more notes.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/notes', [
            'content' => ['complaint' => 'Late edit attempt'],
        ])
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/diagnoses', [
            'description' => 'Late diagnosis',
        ])
        ->assertStatus(409);
});

it('only the encounter provider can document the visit', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = doctorWithUser($org, $facility, $doctorUser);
    $appointment = checkedInAppointment($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $encounterId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/start-encounter')
        ->json('data.id');

    // A different doctor (with a user) cannot document this encounter.
    $otherUser = Identity::user();
    $otherDoctor = doctorWithUser($org, $facility, $otherUser);
    Identity::assign($otherUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($otherUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/notes', [
            'content' => ['complaint' => 'Impersonation attempt'],
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('audits the full clinical chain with facts, never clinical content', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = doctorWithUser($org, $facility, $doctorUser);
    $appointment = checkedInAppointment($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $encounterId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/start-encounter')
        ->json('data.id');

    $noteId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/notes', [
            'content' => ['complaint' => 'Confidential complaint text'],
        ])
        ->json('data.id');

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/notes/'.$noteId.'/sign')
        ->assertOk();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/sign')
        ->assertOk();

    $actions = AuditEvent::query()->pluck('action')->all();

    expect($actions)->toContain('encounter.started')
        ->and($actions)->toContain('note.drafted')
        ->and($actions)->toContain('note.signed')
        ->and($actions)->toContain('encounter.signed');

    // No clinical content in any audit payload.
    foreach (AuditEvent::query()->get() as $event) {
        expect(json_encode($event->payload))->not->toContain('Confidential complaint text');
    }

    // The encounter carries its facility so facility-scoped auditors see it.
    $signed = AuditEvent::query()->where('action', 'encounter.signed')->firstOrFail();
    expect($signed->facility_id)->toBe($facility->getKey())
        ->and($signed->resource_id)->toBe($encounterId);
});
