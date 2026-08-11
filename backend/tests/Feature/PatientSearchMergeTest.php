<?php

use App\Models\Consent;
use App\Models\InsurancePolicy;
use App\Models\Patient;
use App\Models\PatientContact;
use App\Models\PatientDocument;
use App\Models\PatientIdentifier;
use App\Models\Payer;
use Tests\Support\Identity;

/**
 * Patient search, duplicate detection, and merge (API_CONTRACTS.md §21.7,
 * PRODUCT_REQUIREMENTS §6.1): search is tenant-scoped (facility-scoped for
 * facility roles); duplicates are surfaced never auto-merged; merge is the
 * only identity-resolution path — transactional, reason-required, fully
 * audited, children reassigned with collision handling.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('searches the patient index by name and MRN within the tenant', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'full_name' => 'Gita Poudel',
        'mrn' => 'MRN-000001',
    ]);
    Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'full_name' => 'Ramesh Poudel',
        'mrn' => 'MRN-000002',
    ]);

    // By name fragment.
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/patients/search?q=Poudel')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // By exact MRN prefix.
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/patients/search?q=MRN-000002')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.mrn', 'MRN-000002');
});

it('scopes search to the caller’s facility for facility roles', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org, $facilityA);

    Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facilityA->getKey(),
        'full_name' => 'Same Name Person',
    ]);
    Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facilityB->getKey(),
        'full_name' => 'Same Name Person',
    ]);

    $this->withToken(Identity::tokenFor($doctor))
        ->getJson('/api/v1/patients/search?q=Same%20Name')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.facilityId', $facilityA->getKey());
});

it('merges a source patient into a target, reassigning children and retiring the source', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $payer = Payer::factory()->create(['tenant_id' => $org->getKey()]);

    $source = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $target = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    // Source children: a document, an identifier, a contact, a policy, a consent.
    PatientDocument::factory()->create(['tenant_id' => $org->getKey(), 'patient_id' => $source->getKey()]);
    PatientIdentifier::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $source->getKey(),
        'type' => 'passport',
        'value' => 'NP-PASS-77',
    ]);
    PatientContact::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $source->getKey(),
        'type' => 'phone',
        'value' => '+977-9800-999999',
        'is_primary' => true,
    ]);
    InsurancePolicy::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $source->getKey(),
        'payer_id' => $payer->getKey(),
    ]);
    Consent::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $source->getKey(),
        'consent_type' => 'treatment',
        'version' => 1,
        'status' => 'active',
    ]);

    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$source->getKey().'/merge', [
            'targetPatientId' => $target->getKey(),
            'reason' => 'Duplicate registration — same passport on file',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $target->getKey())
        ->assertJsonPath('data.status', 'active');

    // Source retired; target owns all children.
    $source->refresh();
    expect($source->status)->toBe('merged')
        ->and($source->merge_into_patient_id)->toBe($target->getKey());

    expect($target->documents()->count())->toBe(1)
        ->and($target->identifiers()->count())->toBe(1)
        ->and($target->contacts()->count())->toBe(1)
        ->and($target->insurancePolicies()->count())->toBe(1)
        ->and($target->consents()->count())->toBe(1);

    // Both timeline entries exist: source merged, target received.
    expect($target->timeline()->where('event_type', 'patient.merge_received')->exists())->toBeTrue()
        ->and($source->timeline()->where('event_type', 'patient.merged')->exists())->toBeTrue();
});

it('refuses a merge into itself and a merge of an inactive source', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $archived = Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'status' => Patient::STATUS_ARCHIVED,
    ]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/merge', [
            'targetPatientId' => $patient->getKey(),
            'reason' => 'self',
        ])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$archived->getKey().'/merge', [
            'targetPatientId' => $patient->getKey(),
            'reason' => 'already archived',
        ])
        ->assertStatus(409);
});

it('requires the merge reason (no silent identity resolution)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $source = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $target = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$source->getKey().'/merge', [
            'targetPatientId' => $target->getKey(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});
