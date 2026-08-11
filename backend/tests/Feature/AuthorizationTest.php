<?php

use App\Models\Organization;
use App\Models\RoleAssignment;
use Tests\Support\Identity;

/**
 * Authorization (MASTER_RULES.md §8–9, SECURITY.md §6, TESTING_STRATEGY.md
 * §4.2): every endpoint is gated; the default is deny; role changes take
 * effect immediately.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('denies unauthenticated access to every protected endpoint', function (string $method, string $uri) {
    $response = match ($method) {
        'GET' => $this->getJson($uri),
        'POST' => $this->postJson($uri),
        default => $this->deleteJson($uri),
    };

    $response->assertStatus(401)->assertJsonPath('error.code', 'INVALID_TOKEN');
})->with([
    ['GET', '/api/v1/auth/me'],
    ['GET', '/api/v1/organizations'],
    ['POST', '/api/v1/organizations'],
    ['GET', '/api/v1/users'],
    ['GET', '/api/v1/roles'],
    ['GET', '/api/v1/permissions'],
    ['GET', '/api/v1/audit-events'],
    ['GET', '/api/v1/organizations/00000000-0000-0000-0000-000000000000/facilities'],
]);

it('denies an authenticated user without the permission (403 SCOPE_DENIED)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/facilities', [
            'name' => 'New Wing',
            'code' => 'wing-1',
            'timezone' => 'Asia/Kathmandu',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('grants an org admin the facility:create permission in their tenant', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/facilities', [
            'name' => 'New Wing',
            'code' => 'wing-1',
            'timezone' => 'Asia/Kathmandu',
        ])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id');
});

it('lets a platform superadmin provision an organization', function () {
    $super = Identity::user();
    Identity::assign($super, 'superadmin');

    $this->withToken(Identity::tokenFor($super))
        ->postJson('/api/v1/organizations', [
            'name' => 'New Group',
            'code' => 'new-group',
            'currency' => 'NPR',
            'timezone' => 'Asia/Kathmandu',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'new-group')
        ->assertJsonPath('meta.context.tenantId', null);

    expect(Organization::query()->where('code', 'new-group')->exists())->toBeTrue();
});

it('denies a tenant user the platform provisioning action', function () {
    $org = Identity::organization();
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations', [
            'name' => 'Sneaky Org',
            'code' => 'sneaky-org',
            'currency' => 'NPR',
            'timezone' => 'Asia/Kathmandu',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('a user with no active assignments has no access at all', function () {
    $user = Identity::user();

    $this->withToken(Identity::tokenFor($user))
        ->getJson('/api/v1/organizations')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

it('revoking a role takes effect immediately', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $adminA = Identity::user(['email' => 'admin-a@two.test']);
    Identity::assign($adminA, 'org_admin', $org);
    $adminB = Identity::user(['email' => 'admin-b@two.test']);
    Identity::assign($adminB, 'org_admin', $org);

    $tokenA = Identity::tokenFor($adminA);
    $tokenB = Identity::tokenFor($adminB);

    // B can create a facility now…
    $this->withToken($tokenB)
        ->postJson('/api/v1/organizations/'.$org->getKey().'/facilities', [
            'name' => 'B Wing',
            'code' => 'b-wing',
            'timezone' => 'Asia/Kathmandu',
        ])
        ->assertCreated();

    // …A revokes B's assignment…
    $assignment = RoleAssignment::query()
        ->where('user_id', $adminB->getKey())
        ->where('tenant_id', $org->getKey())
        ->firstOrFail();

    $this->withToken($tokenA)
        ->deleteJson('/api/v1/organizations/'.$org->getKey().'/users/'.$adminB->getKey().'/assignments/'.$assignment->getKey())
        ->assertStatus(204);

    // …and B's very next request is denied — no session/token snapshot.
    $this->withToken($tokenB)
        ->postJson('/api/v1/organizations/'.$org->getKey().'/facilities', [
            'name' => 'C Wing',
            'code' => 'c-wing',
            'timezone' => 'Asia/Kathmandu',
        ])
        ->assertStatus(403);
});

it('echoes the effective context on every authenticated response', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations')
        ->assertOk()
        ->assertJsonPath('meta.context.tenantId', $org->getKey())
        ->assertJsonPath('meta.context.facilityId', $facility->getKey())
        ->assertJsonPath('meta.context.timezone', 'Asia/Kathmandu');
});
