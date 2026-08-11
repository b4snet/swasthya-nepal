<?php

use Tests\Support\Identity;

/**
 * Facility isolation (SECURITY.md §9, TENANCY.md §7): a facility-scoped
 * principal cannot reach another facility of the SAME tenant — the policy
 * layer enforces the facility dimension on top of the tenant hard boundary.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('rejects a facility context outside the principal’s scope (FACILITY_DENIED)', function () {
    $org = Identity::organization();
    $facilityOne = Identity::facility($org, ['code' => 'one']);
    $facilityTwo = Identity::facility($org, ['code' => 'two']);
    $manager = Identity::user();
    Identity::assign($manager, 'hospital_admin', $org, $facilityOne);

    $this->withToken(Identity::tokenFor($manager))
        ->withHeader('X-Swasthya-Facility', $facilityTwo->getKey())
        ->getJson('/api/v1/auth/me')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FACILITY_DENIED');
});

it('a facility-scoped principal cannot read another facility of the same tenant (404)', function () {
    $org = Identity::organization();
    $facilityOne = Identity::facility($org, ['code' => 'one']);
    $facilityTwo = Identity::facility($org, ['code' => 'two']);
    $manager = Identity::user();
    Identity::assign($manager, 'hospital_admin', $org, $facilityOne);

    // Default context is facilityOne (most recent assignment).
    $this->withToken(Identity::tokenFor($manager))
        ->getJson('/api/v1/facilities/'.$facilityTwo->getKey())
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // …while its own facility is visible.
    $this->withToken(Identity::tokenFor($manager))
        ->getJson('/api/v1/facilities/'.$facilityOne->getKey())
        ->assertOk()
        ->assertJsonPath('data.id', $facilityOne->getKey());
});

it('facility listing is scoped to the principal’s facility', function () {
    $org = Identity::organization();
    $facilityOne = Identity::facility($org, ['code' => 'one']);
    Identity::facility($org, ['code' => 'two']);
    $manager = Identity::user();
    Identity::assign($manager, 'hospital_admin', $org, $facilityOne);

    $response = $this->withToken(Identity::tokenFor($manager))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/facilities')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$facilityOne->getKey()]);
});

it('an org-scoped admin sees and operates across all facilities of the tenant', function () {
    $org = Identity::organization();
    $facilityOne = Identity::facility($org, ['code' => 'one']);
    $facilityTwo = Identity::facility($org, ['code' => 'two']);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/facilities')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toContain($facilityOne->getKey())
        ->toContain($facilityTwo->getKey());
});

it('a facility-scoped principal cannot create facilities (scope denied)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $manager = Identity::user();
    Identity::assign($manager, 'hospital_admin', $org, $facility);

    $this->withToken(Identity::tokenFor($manager))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/facilities', [
            'name' => 'Rogue Wing',
            'code' => 'rogue',
            'timezone' => 'Asia/Kathmandu',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});
