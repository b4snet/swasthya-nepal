<?php

use App\Models\AuditEvent;
use App\Models\Patient;
use App\Models\PatientContact;
use App\Models\PatientIdentifier;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

/**
 * Patient identifiers and contacts (DATABASE.md §3.12–3.13): identifiers are
 * encrypted at rest with a deterministic hash for duplicate detection; a
 * value active on ANOTHER patient is surfaced as a 409 candidate. Contacts
 * enforce one active primary per (patient, type) and preserve history by
 * superseding.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('encrypts identifier values at rest and returns plaintext on read', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/identifiers', [
            'type' => 'passport',
            'value' => 'NP-778899',
            'issuingCountry' => 'NP',
        ])
        ->assertCreated();

    $raw = DB::table('patient_identifiers')->where('type', 'passport')->first();
    expect($raw->value_encrypted)->not->toBe('NP-778899')           // ciphertext at rest
        ->and($raw->value_hash)->toBe(PatientIdentifier::hashValue('NP-778899'));

    // The API reads plaintext back (EncryptedString cast).
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/patients/'.$patient->getKey().'/identifiers')
        ->assertOk()
        ->assertJsonPath('data.0.value', 'NP-778899');

    // Audit never carries the value.
    $event = AuditEvent::query()->where('action', 'patient.identifier.added')->firstOrFail();
    expect(json_encode($event->payload))->not->toContain('NP-778899');
});

it('refuses an identifier already active on another patient (409 RESOURCE_EXISTS)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patientA = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patientA->getKey().'/identifiers', [
            'type' => 'national_id',
            'value' => 'NID-5556677',
        ])->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patientB->getKey().'/identifiers', [
            'type' => 'national_id',
            'value' => 'NID-5556677',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'RESOURCE_EXISTS')
        ->assertJsonPath('error.details.candidate.mrn', $patientA->mrn);
});

it('supersedes the prior identifier of the same type on the same patient', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/identifiers', [
            'type' => 'license',
            'value' => 'DL-111',
        ])->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/identifiers', [
            'type' => 'license',
            'value' => 'DL-222',
        ])->assertCreated();

    $active = PatientIdentifier::query()->where('patient_id', $patient->getKey())->where('status', 'active')->get();
    expect($active)->toHaveCount(1)
        ->and($active->first()->value_encrypted)->toBe('DL-222')
        ->and(PatientIdentifier::query()->where('patient_id', $patient->getKey())->where('status', 'superseded')->count())->toBe(1);
});

it('keeps one active primary contact per type and supersedes on change', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/contacts', [
            'type' => 'phone',
            'value' => '+977-9800-000001',
            'isPrimary' => true,
        ])->assertCreated();

    // A second primary of the same type demotes the first.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/contacts', [
            'type' => 'phone',
            'value' => '+977-9800-000002',
            'isPrimary' => true,
        ])->assertCreated();

    $primaries = PatientContact::query()
        ->where('patient_id', $patient->getKey())
        ->where('status', 'active')
        ->where('is_primary', true)
        ->where('type', 'phone')
        ->get();

    expect($primaries)->toHaveCount(1)
        ->and($primaries->first()->value)->toBe('+977-9800-000002');

    // History is preserved — nothing was deleted.
    expect(PatientContact::query()->where('patient_id', $patient->getKey())->count())->toBe(2);
});

it('requires exactly one of value or address, and a name for emergency contacts', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    // Both value AND address → rejected.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/contacts', [
            'type' => 'phone',
            'value' => '+977-1',
            'address' => ['line1' => 'x'],
        ])
        ->assertStatus(422);

    // Emergency contact without a name → rejected.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/contacts', [
            'type' => 'emergency_contact',
            'value' => '+977-2',
        ])
        ->assertStatus(422);

    // Address-only is valid.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/contacts', [
            'type' => 'address',
            'address' => ['line1' => 'Kathmandu 1', 'city' => 'Kathmandu'],
        ])
        ->assertCreated();
});

it('records identifier and contact changes on the patient timeline', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/identifiers', [
            'type' => 'national_id',
            'value' => 'NID-998877',
        ])->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/contacts', [
            'type' => 'phone',
            'value' => '+977-9811-111111',
        ])->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/patients/'.$patient->getKey().'/timeline')
        ->assertOk()
        ->assertJsonPath('data.0.eventType', 'patient.contact_added')
        ->assertJsonPath('data.1.eventType', 'patient.identifier_added');
});
