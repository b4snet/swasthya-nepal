<?php

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Department;
use Tests\Support\Identity;

/**
 * Branches (TENANCY.md V2 §4): the optional facility sub-division — CRUD,
 * tenant/facility isolation, catalog branch assignment, and the branch
 * context header (a proposal validated against the resolved facility, never
 * a client-authoritative identity).
 */
beforeEach(function (): void {
    seedIdentity();
});

it('creates a branch inside a facility with audit', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/facilities/'.$facility->getKey().'/branches', [
            'name' => 'East Wing',
            'code' => 'east-wing',
        ])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id');

    expect($response->json('data.facilityId'))->toBe($facility->getKey())
        ->and($response->json('data.code'))->toBe('east-wing')
        ->and(Branch::query()->where('code', 'east-wing')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'branch.created')->count())->toBe(1);
});

it('rejects duplicate branch codes per facility but allows reuse across facilities', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $token = Identity::tokenFor($admin);

    $this->withToken($token)
        ->postJson('/api/v1/facilities/'.$facilityA->getKey().'/branches', ['name' => 'Wing', 'code' => 'wing'])
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/v1/facilities/'.$facilityA->getKey().'/branches', ['name' => 'Wing Again', 'code' => 'wing'])
        ->assertStatus(422);

    $this->withToken($token)
        ->postJson('/api/v1/facilities/'.$facilityB->getKey().'/branches', ['name' => 'Wing', 'code' => 'wing'])
        ->assertCreated();
});

it('scopes branch reads to the caller facility and hides other facilities', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    Branch::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(), 'name' => 'A Wing', 'code' => 'a-wing']);

    $adminA = Identity::user();
    Identity::assign($adminA, 'hospital_admin', $org, $facilityA);

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/facilities/'.$facilityA->getKey().'/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // A facility-scoped principal cannot list another facility's branches.
    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/facilities/'.$facilityB->getKey().'/branches')
        ->assertStatus(404);

    // ...and cannot create branches in another facility.
    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/facilities/'.$facilityB->getKey().'/branches', ['name' => 'B Wing', 'code' => 'b-wing'])
        ->assertStatus(403);
});

it('assigns a branch to a department and rejects branches from another facility', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $branch = Branch::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $token = Identity::tokenFor($admin);

    // Valid branch assignment persists.
    $response = $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$org->getKey().'/departments', [
            'name' => 'Cardiology',
            'code' => 'cardiology',
            'facilityId' => $facilityA->getKey(),
            'branchId' => $branch->getKey(),
        ])
        ->assertCreated();

    expect($response->json('data.branchId'))->toBe($branch->getKey())
        ->and(Department::query()->where('code', 'cardiology')->value('branch_id'))->toBe($branch->getKey());

    // A branch from another facility is a request-shape error.
    $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$org->getKey().'/departments', [
            'name' => 'Neurology',
            'code' => 'neurology',
            'facilityId' => $facilityA->getKey(),
            'branchId' => Branch::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey()])->getKey(),
        ])
        ->assertStatus(422);
});

it('applies branch context from the validated header', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $branch = Branch::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);
    $token = Identity::tokenFor($admin);

    $this->withToken($token)
        ->withHeader('X-Swasthya-Branch', $branch->getKey())
        ->getJson('/api/v1/organizations/'.$org->getKey().'/departments')
        ->assertOk()
        ->assertJsonPath('meta.context.branchId', $branch->getKey());
});

it('rejects branch proposals outside scope', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $branchB = Branch::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facilityA);
    $token = Identity::tokenFor($admin);

    // A branch of another facility — the header is a proposal, not identity.
    $this->withToken($token)
        ->withHeader('X-Swasthya-Branch', $branchB->getKey())
        ->getJson('/api/v1/organizations/'.$org->getKey().'/departments')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'BRANCH_DENIED');

    // A bogus branch id.
    $this->withToken($token)
        ->withHeader('X-Swasthya-Branch', '00000000-0000-0000-0000-000000000000')
        ->getJson('/api/v1/organizations/'.$org->getKey().'/departments')
        ->assertStatus(403);
});

it('updates and deactivates a branch with audit', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $branch = Branch::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $token = Identity::tokenFor($admin);

    $this->withToken($token)
        ->patchJson('/api/v1/branches/'.$branch->getKey(), ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    expect(AuditEvent::query()->where('action', 'branch.updated')->count())->toBe(1);

    $this->withToken($token)
        ->deleteJson('/api/v1/branches/'.$branch->getKey())
        ->assertStatus(204);

    expect(AuditEvent::query()->where('action', 'branch.deleted')->count())->toBe(1)
        ->and(Branch::query()->withTrashed()->where('id', $branch->getKey())->exists())->toBeTrue();
});
