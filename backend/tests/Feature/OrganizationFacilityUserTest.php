<?php

use App\Models\AuditEvent;
use App\Models\Facility;
use App\Models\RoleAssignment;
use App\Models\User;
use Tests\Support\Identity;

/**
 * Organizations, facilities, and users (API_CONTRACTS.md §21.2–21.4):
 * creation rules, uniqueness, membership checks, and assignment lifecycle.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('a platform admin can create an organization and duplicate codes are rejected', function () {
    $super = Identity::user();
    Identity::assign($super, 'superadmin');
    $token = Identity::tokenFor($super);

    $this->withToken($token)->postJson('/api/v1/organizations', [
        'name' => 'First Group',
        'code' => 'first-group',
        'currency' => 'NPR',
        'timezone' => 'Asia/Kathmandu',
    ])->assertCreated();

    $this->withToken($token)->postJson('/api/v1/organizations', [
        'name' => 'First Group Again',
        'code' => 'first-group',
        'currency' => 'NPR',
        'timezone' => 'Asia/Kathmandu',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('organization show is membership-gated — 404 for non-members', function () {
    $orgA = Identity::organization();
    $orgB = Identity::organization();
    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/organizations/'.$orgA->getKey())
        ->assertOk();

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/organizations/'.$orgB->getKey())
        ->assertStatus(404);
});

it('a facility code is unique per tenant but may repeat across tenants', function () {
    $orgA = Identity::organization();
    $orgB = Identity::organization();
    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);
    $adminB = Identity::user();
    Identity::assign($adminB, 'org_admin', $orgB);

    $base = ['name' => 'Central', 'code' => 'central', 'timezone' => 'Asia/Kathmandu'];

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/facilities', $base)
        ->assertCreated();

    // Same code in the same tenant → 422.
    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/facilities', $base)
        ->assertStatus(422);

    // Same code in a different tenant → fine (tenant-scoped uniqueness).
    $this->withToken(Identity::tokenFor($adminB))
        ->postJson('/api/v1/organizations/'.$orgB->getKey().'/facilities', $base)
        ->assertCreated();
});

it('creates a user with a strong initial password and an active assignment', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/users', [
            'email' => 'nurse.poudel@two.test',
            'password' => 'strong-initial-password-42',
            'roleCode' => 'nurse',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id');

    expect($response->json('data.assignments.0.roles'))->toBe(['nurse'])
        ->and(User::query()->where('email', 'nurse.poudel@two.test')->exists())->toBeTrue()
        ->and(RoleAssignment::query()->where('tenant_id', $org->getKey())->where('status', 'active')->count())->toBe(2);
});

it('rejects a weak initial password and a duplicate email', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    Identity::user(['email' => 'exists@two.test']);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/users', [
            'email' => 'weak@two.test',
            'password' => 'short',
            'roleCode' => 'nurse',
            'facilityId' => $facility->getKey(),
        ])->assertStatus(422);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/users', [
            'email' => 'EXISTS@two.test', // case-insensitive duplicate
            'password' => 'strong-initial-password-42',
            'roleCode' => 'nurse',
            'facilityId' => $facility->getKey(),
        ])->assertStatus(422);
});

it('grants and revokes a role assignment with audit and conflict rules', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $target = Identity::user();

    $token = Identity::tokenFor($admin);

    // Grant.
    $grant = $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$org->getKey().'/users/'.$target->getKey().'/assignments', [
            'roleCode' => 'hospital_admin',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id');

    // Duplicate grant → 409.
    $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$org->getKey().'/users/'.$target->getKey().'/assignments', [
            'roleCode' => 'hospital_admin',
            'facilityId' => $facility->getKey(),
        ])->assertStatus(409)
        ->assertJsonPath('error.code', 'RESOURCE_EXISTS');

    // Revoke.
    $assignmentId = $grant->json('data.id');
    $this->withToken($token)
        ->deleteJson('/api/v1/organizations/'.$org->getKey().'/users/'.$target->getKey().'/assignments/'.$assignmentId)
        ->assertStatus(204);

    // Second revoke → conflict.
    $this->withToken($token)
        ->deleteJson('/api/v1/organizations/'.$org->getKey().'/users/'.$target->getKey().'/assignments/'.$assignmentId)
        ->assertStatus(409);

    expect(AuditEvent::query()->where('action', 'role_assignment.revoked')->count())->toBe(1);
});

it('rejects role/scope mismatches (org role with a facility, facility role without one)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $target = Identity::user();
    $token = Identity::tokenFor($admin);

    // Org-scoped role cannot carry a facility.
    $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$org->getKey().'/users/'.$target->getKey().'/assignments', [
            'roleCode' => 'org_admin',
            'facilityId' => $facility->getKey(),
        ])->assertStatus(422);

    // Facility-scoped role requires a facility.
    $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$org->getKey().'/users/'.$target->getKey().'/assignments', [
            'roleCode' => 'hospital_admin',
        ])->assertStatus(422);
});

it('lists the role and permission catalogs', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $token = Identity::tokenFor($admin);

    $roles = $this->withToken($token)->getJson('/api/v1/roles')->assertOk();
    expect(collect($roles->json('data'))->pluck('code'))->toContain('org_admin', 'doctor', 'nurse');

    $filtered = $this->withToken($token)->getJson('/api/v1/roles?filter[scopeType]=facility')->assertOk();
    expect(collect($filtered->json('data'))->pluck('scopeType')->unique()->all())->toBe(['facility']);

    $permissions = $this->withToken($token)->getJson('/api/v1/permissions')->assertOk();
    expect(collect($permissions->json('data'))->pluck('code'))->toContain('facility:create', 'audit:view');
});

it('does not allow platform roles to be granted through an organization', function () {
    $org = Identity::organization();
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $target = Identity::user();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/users/'.$target->getKey().'/assignments', [
            'roleCode' => 'superadmin',
        ])
        ->assertStatus(422);
});
