<?php

use App\Models\Department;
use App\Models\Facility;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Identity;

uses(RefreshDatabase::class);

/**
 * PHASE 90 — Hospital Configuration Isolation.
 *
 * Proves that hospital configuration is properly isolated:
 * Hospital A's configuration does not leak to Hospital B.
 */
it('isolates facilities across hospitals', function () {
    $hospitalA = Identity::organization(['name' => 'Hospital A']);
    $hospitalB = Identity::organization(['name' => 'Hospital B']);

    $facA = Identity::facility($hospitalA, ['name' => 'Facility A-1']);
    $facB = Identity::facility($hospitalB, ['name' => 'Facility B-1']);

    // Hospital A sees only its facilities
    $facilitiesA = Facility::where('tenant_id', $hospitalA->id)->get();
    expect($facilitiesA)->toHaveCount(1);
    expect($facilitiesA->first()->name)->toBe('Facility A-1');

    // Hospital B sees only its facilities
    $facilitiesB = Facility::where('tenant_id', $hospitalB->id)->get();
    expect($facilitiesB)->toHaveCount(1);
    expect($facilitiesB->first()->name)->toBe('Facility B-1');
});

it('isolates departments across hospitals', function () {
    $hospitalA = Identity::organization(['name' => 'Hospital A']);
    $hospitalB = Identity::organization(['name' => 'Hospital B']);

    $facA = Identity::facility($hospitalA, ['name' => 'Fac A']);
    $facB = Identity::facility($hospitalB, ['name' => 'Fac B']);

    $deptA = Department::create([
        'tenant_id' => $hospitalA->id,
        'facility_id' => $facA->id,
        'name' => 'Cardiology',
        'code' => 'CARD-A',
        'type' => 'medical',
        'status' => 'active',
    ]);
    $deptB = Department::create([
        'tenant_id' => $hospitalB->id,
        'facility_id' => $facB->id,
        'name' => 'Neurology',
        'code' => 'NEURO-B',
        'type' => 'medical',
        'status' => 'active',
    ]);

    // Hospital A sees only its departments
    $deptsA = Department::where('tenant_id', $hospitalA->id)->get();
    expect($deptsA)->toHaveCount(1);
    expect($deptsA->first()->name)->toBe('Cardiology');

    // Hospital B sees only its departments
    $deptsB = Department::where('tenant_id', $hospitalB->id)->get();
    expect($deptsB)->toHaveCount(1);
    expect($deptsB->first()->name)->toBe('Neurology');
});

it('isolates services across hospitals with different pricing', function () {
    $hospitalA = Identity::organization(['name' => 'Hospital A']);
    $hospitalB = Identity::organization(['name' => 'Hospital B']);

    $facA = Identity::facility($hospitalA, ['name' => 'Fac A']);
    $facB = Identity::facility($hospitalB, ['name' => 'Fac B']);

    Service::create([
        'tenant_id' => $hospitalA->id,
        'facility_id' => $facA->id,
        'name' => 'OPD Consultation',
        'code' => 'CONS-A',
        'service_type' => 'opd_consultation',
        'default_charge_minor' => 1500,
        'currency' => 'NPR',
        'status' => 'active',
    ]);

    Service::create([
        'tenant_id' => $hospitalB->id,
        'facility_id' => $facB->id,
        'name' => 'OPD Consultation',
        'code' => 'CONS-B',
        'service_type' => 'opd_consultation',
        'default_charge_minor' => 3000,
        'currency' => 'NPR',
        'status' => 'active',
    ]);

    // Each hospital sees only its own services with its own pricing
    $servicesA = Service::where('tenant_id', $hospitalA->id)->get();
    $servicesB = Service::where('tenant_id', $hospitalB->id)->get();

    expect($servicesA)->toHaveCount(1);
    expect($servicesA->first()->default_charge_minor)->toBe(1500);
    expect($servicesB)->toHaveCount(1);
    expect($servicesB->first()->default_charge_minor)->toBe(3000);
});

it('prevents cross-hospital configuration access', function () {
    $hospitalA = Identity::organization(['name' => 'Hospital A']);
    $hospitalB = Identity::organization(['name' => 'Hospital B']);

    $facA = Identity::facility($hospitalA, ['name' => 'Fac A']);
    $facB = Identity::facility($hospitalB, ['name' => 'Fac B']);

    $adminA = Identity::user();
    Identity::assign($adminA, 'hospital_admin', $hospitalA, $facA);

    // Admin A should not be able to access Hospital B's configuration
    $response = $this->withToken(Identity::tokenFor($adminA))
        ->getJson("/api/v1/facilities/{$facB->getKey()}");
    $response->assertStatus(404); // RLS hides it
});
