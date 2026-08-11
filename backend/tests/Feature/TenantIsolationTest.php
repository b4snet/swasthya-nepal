<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\User;
use Tests\Support\Identity;

/**
 * Tenant isolation (TENANCY.md §8, SECURITY.md §8, TESTING_STRATEGY.md §4.3):
 * cross-tenant reads and writes at the API layer must fail — 404 for reads
 * (existence is never leaked), 403 for writes. A forged tenant identifier
 * in a request is ignored.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('a tenant cannot read another tenant’s facility (404, existence hidden)', function () {
    [$orgA, $adminA] = tenant('a');
    [, , $facilityB] = tenant('b');

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/facilities/'.$facilityB->getKey())
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('a tenant cannot list another tenant’s facilities (404 for reads)', function () {
    [$orgA, $adminA] = tenant('a');
    [$orgB] = tenant('b');

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/organizations/'.$orgB->getKey().'/facilities')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('a tenant cannot create a facility inside another tenant (403 for writes)', function () {
    [$orgA, $adminA] = tenant('a');
    [$orgB] = tenant('b');

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgB->getKey().'/facilities', [
            'name' => 'Intrusion Wing',
            'code' => 'intrusion',
            'timezone' => 'Asia/Kathmandu',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('a tenant cannot grant roles inside another tenant', function () {
    [$orgA, $adminA] = tenant('a');
    [$orgB, , , $userB] = tenant('b');

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgB->getKey().'/users/'.$userB->getKey().'/assignments', [
            'roleCode' => 'org_admin',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('user listing is tenant-scoped — a tenant never sees another tenant’s users', function () {
    [$orgA, $adminA] = tenant('a');
    [$orgB, , , $userB] = tenant('b');

    $response = $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/users')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->not->toContain($userB->getKey());
});

it('audit reads are tenant-scoped — no cross-tenant trail leakage', function () {
    [$orgA, $adminA] = tenant('a');
    [$orgB, $adminB] = tenant('b');

    // Generate a facility-created event in EACH tenant.
    $createdB = $this->withToken(Identity::tokenFor($adminB))
        ->postJson('/api/v1/organizations/'.$orgB->getKey().'/facilities', [
            'name' => 'B Wing',
            'code' => 'b-wing',
            'timezone' => 'Asia/Kathmandu',
        ])->assertCreated();

    $createdA = $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/facilities', [
            'name' => 'A Wing',
            'code' => 'a-wing',
            'timezone' => 'Asia/Kathmandu',
        ])->assertCreated();

    $response = $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/audit-events')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($createdA->headers->get('X-Audit-Event-Id'))
        ->and($ids)->not->toContain($createdB->headers->get('X-Audit-Event-Id'));
});

it('a forged tenant_id in the request body is ignored (rejected as unknown field)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $otherOrg = Identity::organization();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/facilities', [
            'name' => 'Wing',
            'code' => 'wing-9',
            'timezone' => 'Asia/Kathmandu',
            'tenant_id' => $otherOrg->getKey(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // The facility was created under the CALLER's tenant, never the forged one.
    expect(Facility::query()
        ->where('tenant_id', $otherOrg->getKey())
        ->where('code', 'wing-9')
        ->exists())->toBeFalse();
});

it('a facility header pointing at another tenant is refused with FACILITY_DENIED', function () {
    [$orgA, $adminA, $facilityA] = tenant('a');
    [$orgB, , $facilityB] = tenant('b');

    $this->withToken(Identity::tokenFor($adminA))
        ->withHeader('X-Swasthya-Facility', $facilityB->getKey())
        ->getJson('/api/v1/organizations')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FACILITY_DENIED');
});

it('a suspended tenant is denied with TENANT_SUSPENDED, and its isolation holds', function () {
    [$orgA, $adminA, $facilityA] = tenant('a');
    $orgA->update(['status' => 'suspended']);

    // Login still works (the identity is global), but every context-bound
    // request is refused at the tenant gate.
    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/auth/me')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'TENANT_SUSPENDED');

    // And it certainly cannot reach another tenant while suspended.
    [$orgB, , $facilityB] = tenant('b');
    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/facilities/'.$facilityB->getKey())
        ->assertStatus(403);
});

/**
 * @return array{0: Organization, 1: User, 2: Facility, 3: User}
 */
function tenant(string $suffix): array
{
    $org = Organization::factory()->create(['code' => 'org-'.$suffix]);
    $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
    $admin = Identity::user(['email' => 'admin-'.$suffix.'@isolation.test']);
    Identity::assign($admin, 'org_admin', $org);
    $member = Identity::user(['email' => 'member-'.$suffix.'@isolation.test']);
    Identity::assign($member, 'doctor', $org, $facility);

    return [$org, $admin, $facility, $member];
}
