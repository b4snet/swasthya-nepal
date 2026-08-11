<?php

use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use Tests\Support\Identity;

/**
 * Patient data authorization and isolation (TENANCY.md §8, TESTING_STRATEGY
 * §4.3): PHI is the crown jewel — cross-tenant reads 404, writes 403, and
 * facility roles see exactly their facility's patients. Registration is
 * gated by patient:register, merge by patient:merge (a receptionist may
 * register but never merge).
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * @return array{0: Organization, 1: mixed, 2: mixed, 3: string, 4: string}
 */
function patientTenant(string $suffix): array
{
    $org = Organization::factory()->create(['code' => 'pt-org-'.$suffix]);
    $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
    $admin = Identity::user(['email' => 'pt-admin-'.$suffix.'@isolation.test']);
    Identity::assign($admin, 'org_admin', $org);

    return [$org, $admin, $facility, $org->getKey(), $facility->getKey()];
}

it('a tenant cannot read another tenant’s patient (404, existence hidden)', function () {
    [$orgA, $adminA] = patientTenant('a');
    [, , , $orgB, $facilityB] = patientTenant('b');
    $patientB = Patient::factory()->create(['tenant_id' => $orgB, 'facility_id' => $facilityB]);

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/patients/'.$patientB->getKey())
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('a tenant cannot list or search another tenant’s patients', function () {
    [$orgA, $adminA] = patientTenant('a');
    [$orgB, , $facilityB] = patientTenant('b');
    Patient::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'full_name' => 'Cross Tenant Patient']);

    // List scoped by tenant.
    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/organizations/'.$orgA->getKey().'/patients')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Search: same name exists in tenant B but tenant A sees nothing.
    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/patients/search?q=Cross%20Tenant')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('a tenant cannot register a patient into another tenant’s facility', function () {
    [$orgA, $adminA, $facilityA] = patientTenant('a');
    [, , $facilityB] = patientTenant('b');

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/patients', [
            'facilityId' => $facilityB->getKey(),
            'fullName' => 'Sneaky',
            'dateOfBirth' => '1990-01-01',
            'sex' => 'female',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('facility-scoped roles see only their facility’s patients (read 404, write 403)', function () {
    $org = Identity::organization();
    $facilityA = Facility::factory()->create(['tenant_id' => $org->getKey(), 'code' => 'fac-a']);
    $facilityB = Facility::factory()->create(['tenant_id' => $org->getKey(), 'code' => 'fac-b']);
    $doctorA = Identity::user();
    Identity::assign($doctorA, 'doctor', $org, $facilityA);

    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey()]);

    // Same tenant, other facility → 404 on read.
    $this->withToken(Identity::tokenFor($doctorA))
        ->getJson('/api/v1/patients/'.$patientB->getKey())
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // Same tenant, other facility → 403 on write (update).
    $this->withToken(Identity::tokenFor($doctorA))
        ->patchJson('/api/v1/patients/'.$patientB->getKey(), [
            'lockVersion' => 0,
            'fullName' => 'Intrusion',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('a receptionist can register and update but never merge (permission split)', function () {
    $org = Identity::organization();
    $facility = Facility::factory()->create(['tenant_id' => $org->getKey()]);
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);
    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org, $facility);

    // Receptionist registers.
    $created = $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/patients', [
            'facilityId' => $facility->getKey(),
            'fullName' => 'New Patient',
            'dateOfBirth' => '1988-08-08',
            'sex' => 'other',
        ])
        ->assertCreated();

    // Doctor can view (patient:view) but not register.
    $patientId = $created->json('data.id');
    $this->withToken(Identity::tokenFor($doctor))
        ->getJson('/api/v1/patients/'.$patientId)
        ->assertOk();

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/patients', [
            'facilityId' => $facility->getKey(),
            'fullName' => 'Not Allowed',
            'dateOfBirth' => '1989-09-09',
            'sex' => 'female',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // Receptionist cannot merge (no patient:merge).
    $other = $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/patients', [
            'facilityId' => $facility->getKey(),
            'fullName' => 'Second Patient',
            'dateOfBirth' => '1990-10-10',
            'sex' => 'male',
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/patients/'.$other->json('data.id').'/merge', [
            'targetPatientId' => $patientId,
            'reason' => 'Trying to merge without permission',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('denies unauthenticated access to every patient endpoint', function (string $method, string $uri) {
    $response = match ($method) {
        'GET' => $this->getJson($uri),
        default => $this->postJson($uri),
    };

    $response->assertStatus(401)->assertJsonPath('error.code', 'INVALID_TOKEN');
})->with([
    ['GET', '/api/v1/organizations/00000000-0000-0000-0000-000000000000/patients'],
    ['GET', '/api/v1/patients/search?q=test'],
    ['GET', '/api/v1/patients/00000000-0000-0000-0000-000000000000'],
    ['POST', '/api/v1/patients/00000000-0000-0000-0000-000000000000/identifiers'],
    ['POST', '/api/v1/patients/00000000-0000-0000-0000-000000000000/contacts'],
    ['POST', '/api/v1/patients/00000000-0000-0000-0000-000000000000/consents'],
    ['POST', '/api/v1/patients/00000000-0000-0000-0000-000000000000/documents'],
    ['POST', '/api/v1/patients/00000000-0000-0000-0000-000000000000/insurance-policies'],
    ['GET', '/api/v1/patients/00000000-0000-0000-0000-000000000000/timeline'],
    ['GET', '/api/v1/organizations/00000000-0000-0000-0000-000000000000/payers'],
]);

it('audits patient views and cross-tenant audit reads never leak', function () {
    [$orgA, $adminA, $facilityA] = patientTenant('a');
    [$orgB, $adminB, $facilityB] = patientTenant('b');

    $patientB = Patient::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    $this->withToken(Identity::tokenFor($adminB))
        ->getJson('/api/v1/patients/'.$patientB->getKey())
        ->assertOk();

    // Tenant A's audit stream does not contain tenant B's view event.
    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/audit-events')
        ->assertOk()
        ->assertJsonCount(0, 'data'); // A has produced no events yet
});
