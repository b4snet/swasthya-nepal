<?php

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Staff;
use Tests\Support\Identity;

/**
 * Departments (DATABASE.md §3.8): CRUD, hierarchy, code uniqueness,
 * facility scoping, and delete restrictions.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('lets an org admin create a department inside a facility', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/departments', [
            'name' => 'Cardiology',
            'code' => 'cardio',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id');

    $department = Department::query()->findOrFail($response->json('data.id'));

    expect($department->tenant_id)->toBe($org->getKey())
        ->and($department->facility_id)->toBe($facility->getKey())
        ->and($department->code)->toBe('cardio')
        ->and($department->status)->toBe('active');

    $event = AuditEvent::query()->findOrFail($response->headers->get('X-Audit-Event-Id'));
    expect($event->action)->toBe('department.created')
        ->and($event->tenant_id)->toBe($org->getKey())
        ->and($event->facility_id)->toBe($facility->getKey());
});

it('enforces a unique code per facility, not per tenant', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/departments', [
            'name' => 'OPD', 'code' => 'opd', 'facilityId' => $facilityA->getKey(),
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/departments', [
            'name' => 'OPD Two', 'code' => 'opd', 'facilityId' => $facilityA->getKey(),
        ])
        ->assertStatus(422);

    // Same code in a DIFFERENT facility of the same tenant is fine.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/departments', [
            'name' => 'OPD', 'code' => 'opd', 'facilityId' => $facilityB->getKey(),
        ])
        ->assertCreated();
});

it('enforces the department hierarchy inside the same facility', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $parent = Department::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(), 'code' => 'parent',
    ]);

    // Child with a parent in another facility → 422 (clean, not a DB error).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/departments', [
            'name' => 'Child', 'code' => 'child', 'facilityId' => $facilityB->getKey(),
            'parentDepartmentId' => $parent->getKey(),
        ])
        ->assertStatus(422);

    // Child in the same facility → 201.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/departments', [
            'name' => 'Child', 'code' => 'child', 'facilityId' => $facilityA->getKey(),
            'parentDepartmentId' => $parent->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.parentDepartmentId', $parent->getKey());
});

it('scopes index and show to the caller facility', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $deptA = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(), 'code' => 'dept-a']);
    Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey(), 'code' => 'dept-b']);

    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facilityA);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/departments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'dept-a');

    // A department inside the facility is readable.
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/departments/'.$deptA->getKey())
        ->assertOk();

    $deptB = Department::query()->where('code', 'dept-b')->firstOrFail();
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/departments/'.$deptB->getKey())
        ->assertStatus(404);
});

it('denies cross-tenant department access', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $deptA = Department::factory()->create(['tenant_id' => $orgA->getKey(), 'facility_id' => $facilityA->getKey()]);

    $orgB = Identity::organization();
    $adminB = Identity::user();
    Identity::assign($adminB, 'org_admin', $orgB);

    $this->withToken(Identity::tokenFor($adminB))
        ->getJson('/api/v1/organizations/'.$orgA->getKey().'/departments')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($adminB))
        ->getJson('/api/v1/departments/'.$deptA->getKey())
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($adminB))
        ->patchJson('/api/v1/departments/'.$deptA->getKey(), ['name' => 'Hacked'])
        ->assertStatus(403);
});

it('cannot delete a department that still has staff (409)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
    ]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->deleteJson('/api/v1/departments/'.$department->getKey())
        ->assertStatus(409);

    expect(Department::query()->find($department->getKey()))->not->toBeNull();
});

it('soft-deletes a department and hides it from reads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->deleteJson('/api/v1/departments/'.$department->getKey())
        ->assertStatus(204);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/departments/'.$department->getKey())
        ->assertStatus(404);

    expect(AuditEvent::query()->where('action', 'department.deleted')->exists())->toBeTrue();
});

it('rejects unknown fields on department creation', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/departments', [
            'name' => 'Cardiology', 'code' => 'cardio',
            'facilityId' => $facility->getKey(),
            'sneaky' => true,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});
