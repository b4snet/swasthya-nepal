<?php

use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\OrderSet;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\Problem;
use App\Models\Staff;
use Tests\Support\Identity;

/**
 * Clinical encounters enterprise hardening — vitals, allergies,
 * problem list, order sets, note amendment, walk-in, CAS sign.
 */
beforeEach(function (): void {
    seedIdentity();
});

function makeEncounterDoctor(Organization $org, Facility $facility): array
{
    $user = Identity::user();
    Identity::assign($user, 'doctor', $org, $facility);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $staff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $user->getKey(),
        'designation' => 'Consultant Physician',
        'status' => 'active',
    ]);

    return ['staff' => $staff, 'user' => $user];
}

function makeEncounter(Organization $org, Facility $facility, Staff $doctor, Patient $patient): Encounter
{
    return Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'type' => Encounter::TYPE_OPD,
        'status' => Encounter::STATUS_OPEN,
        'started_at' => now(),
        'lock_version' => 0,
    ]);
}

// ── Vitals ──────────────────────────────────────────────────────────

it('records and retrieves encounter vitals', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['staff' => $doctor] = makeEncounterDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = makeEncounter($org, $facility, $doctor, $patient);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/vitals', [
            'temperatureCelsius' => 37.2,
            'heartRateBpm' => 78,
            'systolicBp' => 120,
            'diastolicBp' => 80,
            'spo2Percent' => 98.5,
        ])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'value' => ['temperature_celsius']]]);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/encounters/'.$encounter->getKey().'/vitals')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── Patient Allergies ───────────────────────────────────────────────

it('creates, lists, and resolves patient allergies', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    // Create
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/allergies', [
            'allergen' => 'Penicillin',
            'severity' => 'severe',
            'reaction' => 'Anaphylaxis',
        ])
        ->assertCreated()
        ->assertJsonPath('data.allergen', 'Penicillin');

    // List
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/patients/'.$patient->getKey().'/allergies')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Resolve
    $allergy = PatientAllergy::where('patient_id', $patient->getKey())->first();
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/allergies/'.$allergy->getKey().'/resolve')
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved');
});

// ── Problem List ────────────────────────────────────────────────────

it('creates, lists, resolves, and rules out problems', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    // Create
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/problems', [
            'description' => 'Type 2 Diabetes Mellitus',
            'onsetDate' => '2024-01-15',
        ])
        ->assertCreated()
        ->assertJsonPath('data.description', 'Type 2 Diabetes Mellitus');

    // List active
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/patients/'.$patient->getKey().'/problems')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Resolve
    $problem = Problem::where('patient_id', $patient->getKey())->first();
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/problems/'.$problem->getKey().'/resolve', [
            'resolvedReason' => 'Diet-controlled',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved');

    // Create another and rule out
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/problems', [
            'description' => 'Viral URI',
        ])
        ->assertCreated();

    $problem2 = Problem::where('patient_id', $patient->getKey())->where('status', 'active')->first();
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/problems/'.$problem2->getKey().'/rule-out')
        ->assertOk()
        ->assertJsonPath('data.status', 'ruled_out');
});

// ── Order Sets ──────────────────────────────────────────────────────

it('creates, publishes, applies, and retires order sets', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['staff' => $doctor] = makeEncounterDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = makeEncounter($org, $facility, $doctor, $patient);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    // Create order set
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/order-sets', [
            'name' => 'Diabetic Workup',
            'description' => 'Standard diabetic investigation panel',
            'specialty' => 'endocrinology',
            'items' => [
                ['orderType' => 'lab', 'serviceName' => 'HbA1c', 'serviceCode' => 'HBA1C'],
                ['orderType' => 'lab', 'serviceName' => 'Fasting Glucose', 'serviceCode' => 'GLUC-F'],
                ['orderType' => 'lab', 'serviceName' => 'Lipid Panel', 'serviceCode' => 'LIPID'],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Diabetic Workup');

    $orderSet = OrderSet::where('tenant_id', $org->getKey())->first();

    // Publish
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/order-sets/'.$orderSet->getKey().'/publish')
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    // List published
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/order-sets')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Apply to encounter
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/order-sets/'.$orderSet->getKey().'/apply')
        ->assertCreated()
        ->assertJsonPath('data.item_count', 3);

    // Check provenance
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/encounters/'.$encounter->getKey().'/order-set-applications')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Retire
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/order-sets/'.$orderSet->getKey().'/retire')
        ->assertOk()
        ->assertJsonPath('data.status', 'retired');
});

// ── Walk-in Encounter ───────────────────────────────────────────────

it('creates a walk-in encounter without appointment', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['staff' => $doctor] = makeEncounterDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org, $facility);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/encounters/walk-in', [
            'patientId' => $patient->getKey(),
            'providerStaffId' => $doctor->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'opd')
        ->assertJsonPath('data.status', 'open');
});

// ── Note Amendment ──────────────────────────────────────────────────

it('amends a signed note creating a child version', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['staff' => $doctor, 'user' => $doctorUser] = makeEncounterDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = makeEncounter($org, $facility, $doctor, $patient);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    // Create and sign a note (only the encounter provider can document)
    $noteResp = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/notes', [
            'content' => ['complaint' => 'Headache', 'assessment' => 'Tension headache'],
        ])
        ->assertCreated();
    $noteId = $noteResp->json('data.id');

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/notes/'.$noteId.'/sign')
        ->assertOk();

    // Amend
    $amendResp = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/notes/'.$noteId.'/amend', [
            'content' => ['complaint' => 'Headache', 'assessment' => 'Migraine with aura'],
            'correctionReason' => 'Additional history clarifies diagnosis',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    // Original is now amended
    $this->assertDatabaseHas('clinical_notes', [
        'id' => $noteId,
        'status' => 'amended',
    ]);

    // Child note exists
    $this->assertDatabaseHas('clinical_notes', [
        'parent_note_id' => $noteId,
        'status' => 'draft',
    ]);
});
