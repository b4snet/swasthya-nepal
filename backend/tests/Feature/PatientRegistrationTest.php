<?php

use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\PatientTimelineEntry;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

/**
 * Patient registration (DATABASE.md §3.11, PRODUCT_REQUIREMENTS §6.1): MRN
 * issuance is atomic per tenant and never reused; registration captures
 * demographics, primary contacts, identifiers, and emergency contact in one
 * transaction; duplicate detection runs server-side and returns candidates
 * — never an auto-merge.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('registers a patient with an MRN and writes a timeline entry', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/patients', [
            'facilityId' => $facility->getKey(),
            'fullName' => 'Sita Sharma',
            'dateOfBirth' => '1990-05-12',
            'sex' => 'female',
            'bloodGroup' => 'O+',
            'phone' => '+977-9841-000001',
            'email' => 'sita@example.test',
            'identifiers' => [
                ['type' => 'national_id', 'value' => 'NID-77889900', 'issuingCountry' => 'NP'],
            ],
            'emergencyContact' => [
                'name' => 'Ram Sharma',
                'relation' => 'spouse',
                'phone' => '+977-9841-000002',
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.mrn', 'MRN-000001')
        ->assertJsonPath('data.fullName', 'Sita Sharma')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('meta.duplicates', [])
        ->assertJsonPath('meta.context.tenantId', $org->getKey());

    $patient = Patient::query()->where('mrn', 'MRN-000001')->firstOrFail();

    // Contacts, identifiers, and the emergency contact landed in one go
    // (phone + email + emergency — no address was sent).
    expect($patient->contacts()->count())->toBe(3)
        ->and($patient->identifiers()->count())->toBe(1)
        ->and($patient->identifiers()->first()->type)->toBe('national_id');

    // Timeline foundation: registration is the first entry.
    expect($patient->timeline()->where('event_type', 'patient.registered')->exists())->toBeTrue()
        ->and(PatientTimelineEntry::query()->where('patient_id', $patient->getKey())->count())->toBe(1);

    // Audit recorded the fact (mrn/facilityId), never the phone/email.
    $event = AuditEvent::query()->where('action', 'patient.created')->firstOrFail();
    expect($event->payload['mrn'])->toBe('MRN-000001')
        ->and($event->payload['facilityId'])->toBe($facility->getKey())
        ->and(json_encode($event->payload))->not->toContain('sita@example.test')
        ->and(json_encode($event->payload))->not->toContain('9841-000001');
});

it('mints sequential MRNs per tenant and restarts for a second tenant', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/patients', [
            'facilityId' => $facilityA->getKey(),
            'fullName' => 'First A',
            'dateOfBirth' => '1985-01-01',
            'sex' => 'female',
        ])->assertCreated()->assertJsonPath('data.mrn', 'MRN-000001');

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/patients', [
            'facilityId' => $facilityA->getKey(),
            'fullName' => 'Second A',
            'dateOfBirth' => '1986-01-01',
            'sex' => 'male',
        ])->assertCreated()->assertJsonPath('data.mrn', 'MRN-000002');

    // A brand-new tenant starts at MRN-000001 (per-tenant counter).
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $adminB = Identity::user();
    Identity::assign($adminB, 'org_admin', $orgB);

    $this->withToken(Identity::tokenFor($adminB))
        ->postJson('/api/v1/organizations/'.$orgB->getKey().'/patients', [
            'facilityId' => $facilityB->getKey(),
            'fullName' => 'First B',
            'dateOfBirth' => '1987-01-01',
            'sex' => 'female',
        ])->assertCreated()->assertJsonPath('data.mrn', 'MRN-000001');
});

it('surfaces duplicate candidates on registration but never auto-merges', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    // First registration.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/patients', [
            'facilityId' => $facility->getKey(),
            'fullName' => 'Hari Bahadur Gurung',
            'dateOfBirth' => '1978-03-15',
            'sex' => 'male',
            'identifiers' => [['type' => 'national_id', 'value' => 'NID-11223344']],
        ])->assertCreated();

    // Second registration, same identifier → candidate with score 1.0.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/patients', [
            'facilityId' => $facility->getKey(),
            'fullName' => 'Hari Gurung',
            'dateOfBirth' => '1978-03-15',
            'sex' => 'male',
            'identifiers' => [['type' => 'national_id', 'value' => 'NID-11223344']],
        ])
        ->assertCreated()
        ->assertJsonPath('meta.duplicates.0.score', 1)
        ->assertJsonPath('meta.duplicates.0.fullName', 'Hari Bahadur Gurung');

    // Both records exist — registration never auto-merges.
    expect(Patient::query()->where('status', 'active')->count())->toBe(2);
});

it('rejects an underage-only date of birth and invalid sex at the validator', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/patients', [
            'facilityId' => $facility->getKey(),
            'fullName' => 'Baby',
            'dateOfBirth' => now()->addDay()->toDateString(),
            'sex' => 'undecided',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('reads a patient record and audits the view with facts only', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/patients/'.$patient->getKey())
        ->assertOk()
        ->assertJsonPath('data.mrn', $patient->mrn)
        ->assertJsonPath('data.fullName', $patient->full_name);

    expect(AuditEvent::query()->where('action', 'patient.viewed')->where('resource_id', $patient->getKey())->exists())->toBeTrue();
});

it('updates demographics with optimistic locking (409 on stale lockVersion)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/patients/'.$patient->getKey(), [
            'lockVersion' => 0,
            'fullName' => 'Renamed Patient',
        ])
        ->assertOk()
        ->assertJsonPath('data.fullName', 'Renamed Patient')
        ->assertJsonPath('data.status', 'active');

    // Same stale version now → conflict.
    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/patients/'.$patient->getKey(), [
            'lockVersion' => 0,
            'fullName' => 'Stale Write',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'LOCK_CONFLICT');

    expect($patient->refresh()->full_name)->toBe('Renamed Patient');
});

it('rejects a cross-tenant facility at registration (validator)', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/patients', [
            'facilityId' => $facilityB->getKey(),
            'fullName' => 'Cross Tenant',
            'dateOfBirth' => '1990-01-01',
            'sex' => 'female',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('keeps mrn counters consistent under sequential registrations', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    for ($i = 1; $i <= 3; $i++) {
        $this->withToken(Identity::tokenFor($admin))
            ->postJson('/api/v1/organizations/'.$org->getKey().'/patients', [
                'facilityId' => $facility->getKey(),
                'fullName' => 'Counter #'.$i,
                'dateOfBirth' => '1990-01-0'.$i,
                'sex' => 'female',
            ])->assertCreated();
    }

    $counter = DB::table('mrn_counters')->where('tenant_id', $org->getKey())->value('last_value');
    expect((int) $counter)->toBe(3);

    $mrns = Patient::query()->where('tenant_id', $org->getKey())->orderBy('mrn')->pluck('mrn')->all();
    expect($mrns)->toBe(['MRN-000001', 'MRN-000002', 'MRN-000003']);
});
