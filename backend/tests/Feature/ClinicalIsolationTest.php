<?php

use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Staff;
use Tests\Support\Identity;

/**
 * Tenant and facility isolation + role gates across the clinical workflow.
 * A record from another tenant is invisible (404 reads / 403 writes); a
 * facility-scoped principal cannot touch another facility.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('hides another tenant\'s appointments, encounters, and invoices', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $departmentB = Department::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    $patientB = Patient::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    $staffB = Staff::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'department_id' => $departmentB->getKey(),
    ]);
    $encounterB = Encounter::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
        'provider_staff_id' => $staffB->getKey(),
        'status' => 'signed',
    ]);
    $chargeB = Charge::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
        'source_type' => 'manual',
    ]);
    $invoiceB = Invoice::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
        'status' => 'issued',
    ]);

    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/encounters/'.$encounterB->getKey())
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/invoices/'.$invoiceB->getKey())
        ->assertStatus(404);

    // Writes to another tenant's records are 403.
    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/appointments/'.Appointment::factory()->create([
            'tenant_id' => $orgB->getKey(),
            'facility_id' => $facilityB->getKey(),
            'patient_id' => $patientB->getKey(),
            'provider_staff_id' => $staffB->getKey(),
            'status' => 'booked',
        ])->getKey().'/check-in')
        ->assertStatus(403);
});

it('scopes the queue and appointment list to the caller\'s facility', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org);
    $facilityB = Identity::facility($org);

    $departmentA = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey()]);
    $departmentB = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey()]);
    $doctorA = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facilityA->getKey(),
        'department_id' => $departmentA->getKey(),
    ]);
    $doctorB = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facilityB->getKey(),
        'department_id' => $departmentB->getKey(),
    ]);

    $patientA = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey()]);
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey()]);

    $date = today()->toDateString();
    Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facilityA->getKey(),
        'patient_id' => $patientA->getKey(),
        'provider_staff_id' => $doctorA->getKey(),
        'starts_at' => now()->addMinutes(30),
        'ends_at' => now()->addMinutes(45),
        'status' => 'checked_in',
    ]);
    Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
        'provider_staff_id' => $doctorB->getKey(),
        'starts_at' => now()->addMinutes(60),
        'ends_at' => now()->addMinutes(75),
        'status' => 'checked_in',
    ]);

    $receptionistA = Identity::user();
    Identity::assign($receptionistA, 'receptionist', $org, $facilityA);

    $this->withToken(Identity::tokenFor($receptionistA))
        ->getJson('/api/v1/appointments/queue?date='.$date)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.patient.id', $patientA->getKey());
});

it('enforces role gates across the workflow (receptionist cannot sign, clerk cannot check in)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $doctorUser = Identity::user();
    $doctor = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $doctorUser->getKey(),
        'designation' => 'Consultant Physician',
    ]);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $appointment = Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'status' => 'checked_in',
    ]);

    // A receptionist cannot start an encounter (encounter:create).
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);

    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/start-encounter')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // A billing clerk cannot check a patient in (appointment:checkin).
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/check-in')
        ->assertStatus(403);

    // The doctor can (their role grants it).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/start-encounter')
        ->assertCreated();
});

it('rejects a cross-tenant provider at booking time', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $departmentB = Department::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    $staffB = Staff::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'department_id' => $departmentB->getKey(),
    ]);
    $patientA = Patient::factory()->create(['tenant_id' => $orgA->getKey(), 'facility_id' => $facilityA->getKey()]);

    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientA->getKey(),
            'providerStaffId' => $staffB->getKey(),
            'startsAt' => now()->addHour()->toISOString(),
            'endsAt' => now()->addHour()->addMinutes(30)->toISOString(),
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});
