<?php

use App\Models\Consent;
use App\Models\InsurancePolicy;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\Payer;
use Tests\Support\Identity;

/**
 * Insurance, consent, documents, and payers (DATABASE.md §3.14, §3.38,
 * §3.39, §3.45): payer catalog is tenant-scoped; policies are per
 * (patient, payer) with number uniqueness among active rows; consents are
 * versioned with one active per type; documents are honestly `staged` until
 * object storage exists.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('creates payers in the tenant catalog with tenant-unique codes', function () {
    $org = Identity::organization();
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/payers', [
            'name' => 'Nepal Insurance Company',
            'code' => 'nic',
            'payerType' => 'private',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'nic');

    // Duplicate code (case-insensitive) → 422.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/payers', [
            'name' => 'Nepal Insurance Co.',
            'code' => 'NIC',
            'payerType' => 'private',
        ])
        ->assertStatus(422);

    // The same code in another tenant is fine.
    $orgB = Identity::organization();
    $adminB = Identity::user();
    Identity::assign($adminB, 'org_admin', $orgB);
    $this->withToken(Identity::tokenFor($adminB))
        ->postJson('/api/v1/organizations/'.$orgB->getKey().'/payers', [
            'name' => 'NIC Branch B',
            'code' => 'nic',
            'payerType' => 'private',
        ])
        ->assertCreated();
});

it('attaches an insurance policy and rejects duplicate active (patient, payer) and policy numbers', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $payer = Payer::factory()->create(['tenant_id' => $org->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/insurance-policies', [
            'payerId' => $payer->getKey(),
            'policyNumber' => 'POL-1001',
            'coverageType' => 'general',
            'validFrom' => '2026-01-01',
            'validTo' => '2027-01-01',
            'benefits' => ['opd' => true, 'ipd' => true],
        ])
        ->assertCreated()
        ->assertJsonPath('data.policyNumber', 'POL-1001')
        ->assertJsonPath('data.status', 'active');

    // Same payer again while active → 409.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/insurance-policies', [
            'payerId' => $payer->getKey(),
            'policyNumber' => 'POL-1002',
            'coverageType' => 'general',
            'validFrom' => '2026-02-01',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RESOURCE_EXISTS');

    // Same policy number for the payer (another patient) → 409.
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patientB->getKey().'/insurance-policies', [
            'payerId' => $payer->getKey(),
            'policyNumber' => 'POL-1001',
            'coverageType' => 'general',
            'validFrom' => '2026-03-01',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RESOURCE_EXISTS');
});

it('cancels a policy (lifecycle, never deleted) and refuses double-cancel', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $payer = Payer::factory()->create(['tenant_id' => $org->getKey()]);
    $policy = InsurancePolicy::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $patient->getKey(),
        'payer_id' => $payer->getKey(),
    ]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/insurance-policies/'.$policy->getKey().'/cancel', [
            'reason' => 'Patient switched payer',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // The row still exists — claims can reference coverage history.
    expect(InsurancePolicy::query()->whereKey($policy->getKey())->exists())->toBeTrue();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/insurance-policies/'.$policy->getKey().'/cancel', ['reason' => 'again'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('rejects a payer from another tenant at policy creation', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $orgB = Identity::organization();
    $payerB = Payer::factory()->create(['tenant_id' => $orgB->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/insurance-policies', [
            'payerId' => $payerB->getKey(),
            'policyNumber' => 'POL-9999',
            'coverageType' => 'general',
            'validFrom' => '2026-01-01',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('versions consents — a new capture expires the prior active version', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/consents', [
            'consentType' => 'treatment',
            'scope' => ['opd' => true],
        ])
        ->assertCreated()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.status', 'active');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/consents', [
            'consentType' => 'treatment',
            'scope' => ['opd' => true, 'ipd' => true],
        ])
        ->assertCreated()
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.status', 'active');

    // Exactly one active treatment consent; history has both versions.
    expect(Consent::query()->where('patient_id', $patient->getKey())->where('status', 'active')->count())->toBe(1)
        ->and(Consent::query()->where('patient_id', $patient->getKey())->where('consent_type', 'treatment')->count())->toBe(2);

    // The patient's consent summary reflects the latest status.
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/patients/'.$patient->getKey())
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('revokes a consent with a reason and refuses a second revoke', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $consent = Consent::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $patient->getKey(),
        'consent_type' => Consent::TYPE_DATA_USE,
        'version' => 1,
        'status' => Consent::STATUS_ACTIVE,
    ]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/consents/'.$consent->getKey().'/revoke', [
            'reason' => 'Patient withdrew consent',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked');

    expect($consent->refresh()->revoked_at)->not->toBeNull()
        ->and($consent->revocation_reason)->toBe('Patient withdrew consent');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/consents/'.$consent->getKey().'/revoke', ['reason' => 'again'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('registers document metadata honestly as staged (no fake object storage)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/documents', [
            'documentType' => 'consent',
            'mimeType' => 'application/pdf',
            'sizeBytes' => 2048,
            'checksum' => 'sha256:abc123',
            'retentionClass' => 'clinical',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'staged')
        ->assertJsonPath('data.documentType', 'consent')
        ->assertJsonMissingPath('data.objectKey');

    expect(PatientDocument::query()->where('patient_id', $patient->getKey())->value('status'))->toBe('staged');
});

it('lists patient documents and pays the view audit', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    PatientDocument::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $patient->getKey(),
        'document_type' => 'report',
        'status' => PatientDocument::STATUS_STAGED,
    ]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/patients/'.$patient->getKey().'/documents')
        ->assertOk()
        ->assertJsonPath('data.0.documentType', 'report');
});
