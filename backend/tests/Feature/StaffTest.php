<?php

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

/**
 * Staff profiles (DATABASE.md §3.10): encryption at rest for the license
 * number, uniqueness rules, no-delete lifecycle, and audit discipline.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('creates a staff profile with the license number encrypted at rest', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/staff', [
            'facilityId' => $facility->getKey(),
            'departmentId' => $department->getKey(),
            'employeeCode' => 'EMP-001',
            'fullName' => 'Dr. Anisha Karki',
            'designation' => 'Consultant Cardiologist',
            'licenseNumber' => 'NMC-12345',
        ])
        ->assertCreated()
        ->assertJsonMissingPath('data.licenseNumber');

    $raw = DB::table('staff')->where('employee_code', 'EMP-001')->value('license_number_encrypted');

    expect($raw)->not->toBe('NMC-12345')           // ciphertext at rest
        ->and(is_string($raw))->toBeTrue();

    $member = Staff::query()->where('employee_code', 'EMP-001')->firstOrFail();
    expect($member->license_number_encrypted)->toBe('NMC-12345'); // plaintext in memory via cast

    // The audit event records the fact, never the value. jsonb reorders
    // keys — assert fields individually.
    $event = AuditEvent::query()->where('action', 'staff.created')->firstOrFail();
    expect($event->payload['employeeCode'])->toBe('EMP-001')
        ->and($event->payload['departmentId'])->toBe($department->getKey())
        ->and($event->payload['facilityId'])->toBe($facility->getKey())
        ->and($event->payload['designation'])->toBe('Consultant Cardiologist')
        ->and($event->payload['status'])->toBe('active')
        ->and($event->payload['hasLicense'])->toBe(true)
        ->and($event->payload)->not->toHaveKey('licenseNumber');
});

it('never leaks the license number in list responses or audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'license_number_encrypted' => 'SECRET-NMC-9876',
    ]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/staff')
        ->assertOk()
        ->assertJsonMissingPath('data.0.licenseNumber');

    foreach (AuditEvent::query()->get() as $event) {
        expect(json_encode($event->payload))->not->toContain('SECRET-NMC-9876');
    }
});

it('returns the license number on show only to staff:view holders', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $member = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'license_number_encrypted' => 'NMC-55555',
    ]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/staff/'.$member->getKey())
        ->assertOk()
        ->assertJsonPath('data.licenseNumber', 'NMC-55555');
});

it('enforces employee code uniqueness per tenant', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $departmentA = Department::factory()->create(['tenant_id' => $orgA->getKey(), 'facility_id' => $facilityA->getKey()]);
    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/staff', [
            'facilityId' => $facilityA->getKey(),
            'departmentId' => $departmentA->getKey(),
            'employeeCode' => 'EMP-001',
            'fullName' => 'First Hire',
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/staff', [
            'facilityId' => $facilityA->getKey(),
            'departmentId' => $departmentA->getKey(),
            'employeeCode' => 'emp-001',
            'fullName' => 'Duplicate',
        ])
        ->assertStatus(422);

    // The same code in another tenant is fine (tenant-scoped uniqueness).
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $departmentB = Department::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    $adminB = Identity::user();
    Identity::assign($adminB, 'org_admin', $orgB);

    $this->withToken(Identity::tokenFor($adminB))
        ->postJson('/api/v1/organizations/'.$orgB->getKey().'/staff', [
            'facilityId' => $facilityB->getKey(),
            'departmentId' => $departmentB->getKey(),
            'employeeCode' => 'EMP-001',
            'fullName' => 'Other Tenant',
        ])
        ->assertCreated();
});

it('allows at most one active staff profile per user per tenant, re-hire after departure', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $user = Identity::user(['email' => 'doctor@swasthya.test']);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/staff', [
            'facilityId' => $facility->getKey(),
            'departmentId' => $department->getKey(),
            'employeeCode' => 'EMP-100',
            'fullName' => 'Dr. Ram Thapa',
            'userId' => $user->getKey(),
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/staff', [
            'facilityId' => $facility->getKey(),
            'departmentId' => $department->getKey(),
            'employeeCode' => 'EMP-101',
            'fullName' => 'Dr. Ram Thapa II',
            'userId' => $user->getKey(),
        ])
        ->assertStatus(422);

    // Depart the first profile, then a re-hire is allowed.
    $first = Staff::query()->where('employee_code', 'EMP-100')->firstOrFail();
    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/staff/'.$first->getKey(), ['status' => 'departed'])
        ->assertOk();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/staff', [
            'facilityId' => $facility->getKey(),
            'departmentId' => $department->getKey(),
            'employeeCode' => 'EMP-101',
            'fullName' => 'Dr. Ram Thapa',
            'userId' => $user->getKey(),
        ])
        ->assertCreated();
});

it('rejects a department from another facility', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $departmentB = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/staff', [
            'facilityId' => $facilityA->getKey(),
            'departmentId' => $departmentB->getKey(),
            'employeeCode' => 'EMP-200',
            'fullName' => 'Cross Facility',
        ])
        ->assertStatus(422);
});
