<?php

use App\Models\CriticalValueEvent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabResultVersion;
use App\Models\LabTest;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Specimen;
use App\Models\Staff;
use App\Models\User;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Gap closure tests for the Laboratory / LIS module. Covers:
 *
 * 1. Lab Orders Index — facility-wide worklist with status filtering
 * 2. Specimens Index — facility-wide specimen worklist
 * 3. Order Cancellation — cancel before processing
 * 4. Lab Test Update — update/deactivate catalog entries
 * 5. lab_supervisor lab:manage permission
 */
beforeEach(function (): void {
    seedIdentity();
});

function labGapDoctor(Organization $org, Facility $facility, User $user): Staff
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

function labGapStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function labGapEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
{
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'status' => Encounter::STATUS_OPEN,
    ]);
}

function labGapCatalog(Organization $org, Facility $facility, string $code, string $name): LabTest
{
    return LabTest::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => $code,
        'name' => $name,
        'status' => LabTest::STATUS_ACTIVE,
    ]);
}

it('lists lab orders across the facility with status filtering and pagination', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labGapDoctor($org, $facility, $doctorUser);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    // Create 3 orders in different statuses.
    $encounter1 = labGapEncounter($org, $facility, $doctor);
    $order1 = LabOrder::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $encounter1->patient_id,
        'encounter_id' => $encounter1->getKey(),
        'ordered_by_staff_id' => $doctor->getKey(),
        'status' => LabOrder::STATUS_ORDERED,
    ]);

    $encounter2 = labGapEncounter($org, $facility, $doctor);
    $order2 = LabOrder::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $encounter2->patient_id,
        'encounter_id' => $encounter2->getKey(),
        'ordered_by_staff_id' => $doctor->getKey(),
        'status' => LabOrder::STATUS_REPORTED,
    ]);

    $encounter3 = labGapEncounter($org, $facility, $doctor);
    $order3 = LabOrder::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $encounter3->patient_id,
        'encounter_id' => $encounter3->getKey(),
        'ordered_by_staff_id' => $doctor->getKey(),
        'status' => LabOrder::STATUS_PROCESSING,
    ]);

    $techUser = Identity::user();
    labGapStaff($org, $facility, $techUser, 'Lab Technician');
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    // List all orders — should see all 3.
    $this->withToken(Identity::tokenFor($techUser))
        ->getJson('/api/v1/lab-orders')
        ->assertOk()
        ->assertJsonPath('data.total', 3);

    // Filter by status — only ordered.
    $this->withToken(Identity::tokenFor($techUser))
        ->getJson('/api/v1/lab-orders?status=ordered')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $order1->getKey());

    // Filter by status — only reported.
    $this->withToken(Identity::tokenFor($techUser))
        ->getJson('/api/v1/lab-orders?status=reported')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $order2->getKey());

    // Cross-tenant user sees nothing.
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $techBUser = Identity::user();
    labGapStaff($orgB, $facilityB, $techBUser, 'Lab Technician');
    Identity::assign($techBUser, 'lab_technician', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($techBUser))
        ->getJson('/api/v1/lab-orders')
        ->assertOk()
        ->assertJsonPath('data.total', 0);
});

it('lists specimens across the facility with status filtering', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labGapDoctor($org, $facility, $doctorUser);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $encounter = labGapEncounter($org, $facility, $doctor);
    $order = LabOrder::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->getKey(),
        'ordered_by_staff_id' => $doctor->getKey(),
        'status' => LabOrder::STATUS_COLLECTED,
    ]);

    // Create 2 specimens in different statuses.
    $specimen1 = Specimen::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'lab_order_id' => $order->getKey(),
        'status' => Specimen::STATUS_COLLECTED,
        'collected_by_staff_id' => $doctor->getKey(),
        'collected_at' => now(),
    ]);
    $specimen2 = Specimen::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'lab_order_id' => $order->getKey(),
        'status' => Specimen::STATUS_COMPLETED,
        'collected_by_staff_id' => $doctor->getKey(),
        'collected_at' => now()->subMinute(),
    ]);

    $techUser = Identity::user();
    labGapStaff($org, $facility, $techUser, 'Lab Technician');
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    // List all specimens — should see 2.
    $this->withToken(Identity::tokenFor($techUser))
        ->getJson('/api/v1/specimens')
        ->assertOk()
        ->assertJsonPath('data.total', 2);

    // Filter by status — only collected.
    $this->withToken(Identity::tokenFor($techUser))
        ->getJson('/api/v1/specimens?status=collected')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $specimen1->getKey());

    // Filter by status — only completed.
    $this->withToken(Identity::tokenFor($techUser))
        ->getJson('/api/v1/specimens?status=completed')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.id', $specimen2->getKey());

    // Cross-tenant user sees nothing.
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $techBUser = Identity::user();
    labGapStaff($orgB, $facilityB, $techBUser, 'Lab Technician');
    Identity::assign($techBUser, 'lab_technician', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($techBUser))
        ->getJson('/api/v1/specimens')
        ->assertOk()
        ->assertJsonPath('data.total', 0);
});

it('cancels a lab order in ordered or collected state but not after processing', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labGapDoctor($org, $facility, $doctorUser);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $encounter = labGapEncounter($org, $facility, $doctor);
    $cbc = labGapCatalog($org, $facility, 'CBC', 'Complete Blood Count');

    $response = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => [$cbc->getKey()],
        ])
        ->assertCreated();

    $orderId = $response->json('data.id');

    // Cancel an ordered order — should succeed.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/cancel')
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect(LabOrder::query()->findOrFail($orderId)->status)->toBe('cancelled');

    // Create another order and advance it to processing.
    $encounter2 = labGapEncounter($org, $facility, $doctor);
    $cbc2 = labGapCatalog($org, $facility, 'CBC2', 'CBC 2');
    $response2 = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter2->getKey().'/lab-orders', [
            'testIds' => [$cbc2->getKey()],
        ])
        ->assertCreated();

    $orderId2 = $response2->json('data.id');

    $techUser = Identity::user();
    labGapStaff($org, $facility, $techUser, 'Lab Technician');
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId2.'/collect')
        ->assertOk();

    // Cancel a collected order — should succeed.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/lab-orders/'.$orderId2.'/cancel')
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // Create a third order and advance it to processing.
    $encounter3 = labGapEncounter($org, $facility, $doctor);
    $cbc3 = labGapCatalog($org, $facility, 'CBC3', 'CBC 3');
    $response3 = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter3->getKey().'/lab-orders', [
            'testIds' => [$cbc3->getKey()],
        ])
        ->assertCreated();

    $orderId3 = $response3->json('data.id');

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId3.'/collect')
        ->assertOk();
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId3.'/process')
        ->assertOk();

    // Cancel a processing order — should fail (409).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/lab-orders/'.$orderId3.'/cancel')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('cancels an ordered order that has been collected via specimen custody', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labGapDoctor($org, $facility, $doctorUser);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $encounter = labGapEncounter($org, $facility, $doctor);
    $cbc = labGapCatalog($org, $facility, 'CBC', 'Complete Blood Count');

    $response = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => [$cbc->getKey()],
        ])
        ->assertCreated();

    $orderId = $response->json('data.id');

    // Collect specimens via the specimen custody path.
    $techUser = Identity::user();
    labGapStaff($org, $facility, $techUser, 'Lab Technician');
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/specimens', [
            'specimens' => [['specimenType' => 'blood']],
        ])
        ->assertOk();

    // The order is now 'collected' — cancellation should still succeed.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/cancel')
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('updates and deactivates lab test catalog entries', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $adminUser = Identity::user();
    Identity::assign($adminUser, 'org_admin', $org);

    $cbc = labGapCatalog($org, $facility, 'CBC', 'Complete Blood Count');

    // Update the test name and reference range.
    $this->withToken(Identity::tokenFor($adminUser))
        ->patchJson('/api/v1/organizations/'.$org->getKey().'/lab-tests/'.$cbc->getKey(), [
            'name' => 'Complete Blood Count (CBC)',
            'referenceRange' => '4.0–11.0 x10^9/L',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Complete Blood Count (CBC)')
        ->assertJsonPath('data.referenceRange', '4.0–11.0 x10^9/L');

    expect($cbc->fresh()->name)->toBe('Complete Blood Count (CBC)');

    // Deactivate the test.
    $this->withToken(Identity::tokenFor($adminUser))
        ->patchJson('/api/v1/organizations/'.$org->getKey().'/lab-tests/'.$cbc->getKey(), [
            'status' => 'inactive',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    expect($cbc->fresh()->status)->toBe(LabTest::STATUS_INACTIVE);

    // A nurse cannot update catalog entries (no lab:manage).
    $nurseUser = Identity::user();
    labGapStaff($org, $facility, $nurseUser, 'Staff Nurse');
    Identity::assign($nurseUser, 'nurse', $org, $facility);

    $this->withToken(Identity::tokenFor($nurseUser))
        ->patchJson('/api/v1/organizations/'.$org->getKey().'/lab-tests/'.$cbc->getKey(), [
            'name' => 'Hacked',
        ])
        ->assertStatus(403);
});

it('allows lab_supervisor to manage catalog entries via lab:manage permission', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $supervisorUser = Identity::user();
    labGapStaff($org, $facility, $supervisorUser, 'Lab Supervisor');
    Identity::assign($supervisorUser, 'lab_supervisor', $org, $facility);

    $cbc = labGapCatalog($org, $facility, 'CBC', 'Complete Blood Count');

    // Lab supervisor can update the test catalog (lab:manage).
    $this->withToken(Identity::tokenFor($supervisorUser))
        ->patchJson('/api/v1/organizations/'.$org->getKey().'/lab-tests/'.$cbc->getKey(), [
            'name' => 'Complete Blood Count (Updated by Supervisor)',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Complete Blood Count (Updated by Supervisor)');

    // Lab supervisor can create new tests.
    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/lab-tests', [
            'facilityId' => $facility->getKey(),
            'code' => 'GLU',
            'name' => 'Blood Glucose',
            'category' => 'laboratory',
            'sampleType' => 'serum',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'GLU');
});
