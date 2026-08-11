<?php

use App\Models\Department;
use App\Models\Facility;
use App\Models\Ward;
use Tests\Support\Identity;

/**
 * Phase 4 authorization gates (MASTER_RULES.md §8–9): the catalogs are
 * permission-gated and facility-scoped; the default is deny.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('denies a receptionist every Phase 4 management action', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);

    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/departments', [
            'name' => 'Sneaky', 'code' => 'sneaky', 'facilityId' => $facility->getKey(),
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    $this->withToken(Identity::tokenFor($receptionist))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/departments')
        ->assertStatus(403);
});

it('lets a hospital admin manage their own facility without naming it', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    // No facilityId in the body — the context facility IS the scope.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/wards', [
            'name' => 'ICU', 'code' => 'icu', 'wardType' => 'icu',
        ])
        ->assertCreated()
        ->assertJsonPath('data.facilityId', $facility->getKey());
});

it('rejects a facility-scoped principal proposing another facility', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facilityA);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/wards', [
            'name' => 'Ward B', 'code' => 'ward-b', 'wardType' => 'general',
            'facilityId' => $facilityB->getKey(),
        ])
        ->assertStatus(422);
});

it('scopes hospital-admin catalogs to exactly their facility', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(), 'code' => 'dept-a']);
    Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey(), 'code' => 'dept-b']);

    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facilityA);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/staff')
        ->assertOk();

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/departments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'dept-a');
});

it('lets an org admin administer catalogs across every facility of the tenant', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    foreach ([$facilityA, $facilityB] as $facility) {
        $this->withToken(Identity::tokenFor($admin))
            ->postJson('/api/v1/organizations/'.$org->getKey().'/wards', [
                'name' => 'Ward', 'code' => 'ward-'.$facility->code, 'wardType' => 'general',
                'facilityId' => $facility->getKey(),
            ])
            ->assertCreated();
    }

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/wards')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('denies cross-tenant writes to the catalogs', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $wardA = Ward::factory()->create(['tenant_id' => $orgA->getKey(), 'facility_id' => $facilityA->getKey()]);

    $orgB = Identity::organization();
    $adminB = Identity::user();
    Identity::assign($adminB, 'org_admin', $orgB);

    $this->withToken(Identity::tokenFor($adminB))
        ->postJson('/api/v1/wards/'.$wardA->getKey().'/rooms', [
            'name' => 'Hacked Room', 'code' => 'hacked', 'roomType' => 'general',
        ])
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($adminB))
        ->patchJson('/api/v1/wards/'.$wardA->getKey(), ['name' => 'Hacked'])
        ->assertStatus(403);
});
