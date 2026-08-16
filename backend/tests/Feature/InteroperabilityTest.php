<?php

use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\Consent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Integration;
use App\Models\LabOrder;
use App\Models\OauthPartner;
use App\Models\OauthPartnerToken;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use App\Services\FhirProjection;
use App\Services\IntegrationRegistryService;
use App\Support\Hl7\AdtA01Mapper;
use App\Support\Hl7\Hl7Message;
use App\Support\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 slice 23 — Interoperability readiness (ROADMAP Phase 18,
 * INTEROPERABILITY.md §13–14, DATABASE.md §3.42).
 *
 * Core proofs:
 *   - MAPPING: FHIR R4 projections and the HL7 ADT mapper are contract-
 *     tested against fixtures (mapping drift fails CI).
 *   - REGISTRY TRUTH: integration status is MEASURED (recorded by checks,
 *     CAS-guarded — never asserted); duplicates are 409; kill-switch is
 *     independent and audited.
 *   - SECURITY: egress allowlist is the SSRF guard; OAuth2 client_credentials
 *     is scoped, short-lived, hash-at-rest, revocable; the FHIR surface is
 *     scope-gated AND consent-gated at the boundary; cross-tenant partner
 *     reads are impossible (RLS); webhook signatures are HMAC-verified with
 *     replay protection; no PHI in any audit payload.
 */
beforeEach(function (): void {
    seedIdentity();
    // The partner token endpoint sits behind throttle:auth like staff login.
    config()->set('swasthya.rate_limits.auth', 1000);
});

/**
 * @return array{org: Organization, facility: Facility, admin: User, staff: Staff, department: Department}
 */
function interopAdmin(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $staff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $admin->getKey(),
    ]);

    return ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff, 'department' => $department];
}

function interopPatient(Organization $org, Facility $facility, array $attributes = []): Patient
{
    return Patient::factory()->create(array_merge([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ], $attributes));
}

/**
 * Register a partner through the API and return the plaintext secret.
 */
function interopRegisterPartner(array $ctx, array $scopes): array
{
    $response = test()->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/interop/partners', [
            'name' => 'Partner '.substr((string) Str::uuid(), 0, 8),
            'scopes' => $scopes,
            'tokenTtlSeconds' => 3600,
        ]);

    $response->assertStatus(201);

    return $response->json('data');
}

/**
 * Issue a partner bearer token (client_credentials).
 */
function interopToken(array $partnerData, array $scopes): string
{
    $response = test()->postJson('/api/v1/interop/oauth/token', [
        'clientId' => $partnerData['clientId'],
        'clientSecret' => $partnerData['clientSecret'],
        'scope' => $scopes,
    ]);

    $response->assertStatus(201);

    return $response->json('data.accessToken');
}

/**
 * Give a patient an ACTIVE data-use consent covering the given scope.
 */
function interopConsent(array $ctx, Patient $patient, string $scope): Consent
{
    return Consent::query()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'patient_id' => $patient->getKey(),
        'consent_type' => Consent::TYPE_DATA_USE,
        'version' => 1,
        'status' => Consent::STATUS_ACTIVE,
        'scope' => [$scope],
        'given_by' => $ctx['staff']->getKey(),
        'given_at' => now(),
    ]);
}

// ──────────────────────────── Mapping fixtures ───────────────────────────

it('projects a patient to FHIR R4 exactly as the fixture', function (): void {
    $projected = FhirProjection::patient([
        'id' => 'p1',
        'mrn' => 'MRN-1001',
        'full_name' => 'John Doe',
        'date_of_birth' => '1990-01-01',
        'sex' => 'male',
    ]);

    expect($projected)->toBe(json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/fhir/patient.json'),
        true,
    ));
});

it('projects an encounter to FHIR R4 exactly as the fixture', function (): void {
    $projected = FhirProjection::encounter([
        'id' => 'enc-1',
        'patient_id' => 'p1',
        'type' => 'opd',
        'status' => 'completed',
        'started_at' => CarbonImmutable::parse('2026-08-16T09:00:00+00:00'),
        'ended_at' => CarbonImmutable::parse('2026-08-16T09:30:00+00:00'),
    ]);

    expect($projected)->toBe(json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/fhir/encounter.json'),
        true,
    ));
});

it('projects a lab observation to FHIR R4 exactly as the fixture', function (): void {
    $projected = FhirProjection::observation([
        'id' => 'item-1',
        'test_name' => 'Hemoglobin',
        'result_value' => '14.5',
        'result_unit' => 'g/dL',
        'reference_range' => '13.5-17.5',
        'verified_at' => CarbonImmutable::parse('2026-08-16T12:05:00+00:00'),
    ], [
        'patient_id' => 'p1',
        'reported_at' => CarbonImmutable::parse('2026-08-16T12:00:00+00:00'),
    ]);

    expect($projected)->toBe(json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/fhir/observation.json'),
        true,
    ));
});

it('projects a medication request to FHIR R4 exactly as the fixture', function (): void {
    $projected = FhirProjection::medicationRequest([
        'id' => 'rx-1',
        'patient_id' => 'p1',
        'status' => 'active',
        'created_at' => CarbonImmutable::parse('2026-08-16T09:00:00+00:00'),
    ], [
        ['dose' => '500 mg', 'route' => 'oral', 'frequency' => 'three times daily', 'duration' => '5 days', 'medication_name' => 'Amoxicillin'],
        ['dose' => '650 mg', 'route' => 'oral', 'frequency' => 'twice daily', 'duration' => '3 days', 'medication_name' => 'Paracetamol'],
    ]);

    expect($projected)->toBe(json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/fhir/medication_request.json'),
        true,
    ));
});

it('projects a reported lab order to FHIR R4 exactly as the fixture', function (): void {
    $projected = FhirProjection::diagnosticReport([
        'id' => 'ord-1',
        'patient_id' => 'p1',
        'order_code' => 'CBC',
        'reported_at' => CarbonImmutable::parse('2026-08-16T12:00:00+00:00'),
    ], [
        ['id' => 'item-1'],
        ['id' => 'item-2'],
    ]);

    expect($projected)->toBe(json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/fhir/diagnostic_report.json'),
        true,
    ));
});

it('maps an ADT^A01 message to the canonical internal shape (fixture)', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../Fixtures/hl7/adt_a01_basic.hl7');
    $mapped = (new AdtA01Mapper)->map(Hl7Message::fromString($raw));

    expect($mapped['messageType'])->toBe('ADT')
        ->and($mapped['messageTrigger'])->toBe('A01')
        ->and($mapped['messageControlId'])->toBe('ADT-0001')
        ->and($mapped['patientClass'])->toBe('I')
        ->and($mapped['admissionType'])->toBe('E')
        ->and($mapped['location'])->toBe('GEN WARD-1 BED-12')
        ->and($mapped['patient']['mrn'])->toBe('MRN-2001')
        ->and($mapped['patient']['familyName'])->toBe('Shrestha')
        ->and($mapped['patient']['givenName'])->toBe('Anita')
        ->and($mapped['patient']['sex'])->toBe('F');
});

it('verifies webhook signatures with replay protection', function (): void {
    $secret = 'webhook-secret-'.Str::uuid();
    $payload = '{"event":"lab.result","correlationId":"'.Str::uuid().'"}';
    $timestamp = time();
    $signature = WebhookSignature::sign($payload, $secret, $timestamp);

    expect(WebhookSignature::verify($payload, [
        'x-swasthya-signature' => $signature,
        'x-swasthya-timestamp' => (string) $timestamp,
    ], $secret))->toBeTrue();

    // Tampered payload fails.
    expect(WebhookSignature::verify($payload.'x', [
        'x-swasthya-signature' => $signature,
        'x-swasthya-timestamp' => (string) $timestamp,
    ], $secret))->toBeFalse();

    // Replayed (stale) timestamp fails.
    expect(WebhookSignature::verify($payload, [
        'x-swasthya-signature' => $signature,
        'x-swasthya-timestamp' => (string) ($timestamp - 3600),
    ], $secret))->toBeFalse();

    // Missing/malformed headers fail.
    expect(WebhookSignature::verify($payload, [], $secret))->toBeFalse()
        ->and(WebhookSignature::verify($payload, [
            'x-swasthya-signature' => $signature,
            'x-swasthya-timestamp' => 'not-a-number',
        ], $secret))->toBeFalse();
});

// ─────────────────────────── Registry (staff) ────────────────────────────

it('registers an integration and refuses duplicates with 409', function (): void {
    $ctx = interopAdmin();

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/interop/integrations', [
            'type' => Integration::TYPE_FHIR,
            'provider' => 'swasthya',
            'purpose' => 'Readiness projection layer serving the partner FHIR surface',
            'contractVersion' => '1.0.0',
            'standardsVersion' => 'FHIR R4.0.1',
            'mappingVersion' => '1',
        ])->assertStatus(201)
        ->assertJsonPath('data.integration.status', Integration::STATUS_CONFIGURED)
        ->assertJsonPath('data.integration.killSwitched', false);

    // Same (tenant, type, provider) → 409, never a duplicate row.
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/interop/integrations', [
            'type' => Integration::TYPE_FHIR,
            'provider' => 'swasthya',
            'purpose' => 'Duplicate registration',
            'contractVersion' => '1.0.0',
        ])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

    expect(Integration::query()->count())->toBe(1);
});

it('denies registry management without integration:manage', function (): void {
    $ctx = interopAdmin();

    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $ctx['org'], $ctx['facility']);

    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/interop/integrations', [
            'type' => Integration::TYPE_SMS,
            'provider' => 'aggregator',
            'purpose' => 'Outbound SMS notifications',
            'contractVersion' => '1.0.0',
        ])->assertStatus(403)->assertJsonPath('error.code', 'SCOPE_DENIED');

    expect(Integration::query()->count())->toBe(0);
});

it('records a measured integration status (CAS — concurrent writer loses with 409)', function (): void {
    $ctx = interopAdmin();
    $integration = Integration::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'provider' => 'aggregator-'.substr((string) Str::uuid(), 0, 8),
        'type' => Integration::TYPE_SMS,
    ]);

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/interop/integrations/{$integration->getKey()}/status", [
            'status' => Integration::STATUS_ACTIVE,
            'health' => ['latencyMs' => 120, 'errorRate' => 0.01],
        ])->assertStatus(200)
        ->assertJsonPath('data.integration.status', Integration::STATUS_ACTIVE)
        ->assertJsonPath('data.integration.health.latencyMs', 120)
        ->assertJsonPath('data.integration.lastCheckedAt', fn ($v) => $v !== null);

    // A concurrent writer holding a stale lock_version loses the CAS. Route
    // binding always re-fetches the fresh row, so the proof is service-level
    // with a deliberately stale in-memory instance (the same pattern as the
    // analytics supersede CAS proof).
    $stale = Integration::query()->findOrFail($integration->getKey());
    $stale->forceFill(['lock_version' => 0]);

    $registry = app(IntegrationRegistryService::class);
    expect(fn () => $registry->recordStatusCheck($stale, Integration::STATUS_DEGRADED, null, (string) $ctx['staff']->getKey()))
        ->toThrow(ApiException::class, 'concurrently')
        ->and(Integration::query()->findOrFail($integration->getKey())->status)->toBe(Integration::STATUS_ACTIVE);
});

it('toggles the integration kill-switch (independent, audited)', function (): void {
    $ctx = interopAdmin();
    $integration = Integration::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'provider' => 'lis-'.substr((string) Str::uuid(), 0, 8),
        'type' => Integration::TYPE_LAB,
    ]);

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/interop/integrations/{$integration->getKey()}/kill-switch", [
            'killSwitched' => true,
        ])->assertStatus(200)->assertJsonPath('data.integration.killSwitched', true);

    expect(AuditEvent::query()->where('action', 'interop.integration_kill_switched')->count())->toBe(1);
});

it('enforces the egress allowlist (SSRF guard)', function (): void {
    $ctx = interopAdmin();

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/interop/egress-allowlist', [
            'host' => 'api.partner.example.test',
            'port' => 443,
            'purpose' => 'Partner API egress',
        ])->assertStatus(201);

    // Duplicate destination → 409.
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson('/api/v1/interop/egress-allowlist', [
            'host' => 'api.partner.example.test',
            'port' => 443,
            'purpose' => 'Duplicate',
        ])->assertStatus(409);

    // The guard admits the allowlisted destination and refuses everything
    // else — even with a registered integration (a missing entry is a hard
    // refusal: the allowlist, not credentials, is the boundary).
    $registry = app(IntegrationRegistryService::class);
    $registry->assertEgressAllowed($ctx['org']->getKey(), 'api.partner.example.test', 443);
    expect(fn () => $registry->assertEgressAllowed($ctx['org']->getKey(), 'evil.example.net', 443))
        ->toThrow(ApiException::class, 'egress allowlist');
});

it('registers an OAuth2 partner and returns the client secret exactly once', function (): void {
    $ctx = interopAdmin();
    $data = interopRegisterPartner($ctx, [OauthPartner::SCOPE_FHIR_PATIENT]);

    expect($data['clientSecret'])->toStartWith('sec_')
        ->and($data['clientId'])->toStartWith('swasthya_');

    $partner = OauthPartner::query()->firstOrFail();
    expect($partner->client_secret_hash)->not->toBe($data['clientSecret']) // hash at rest
        ->and($partner->scopes)->toBe([OauthPartner::SCOPE_FHIR_PATIENT]);
});

it('issues scoped client_credentials tokens and refuses bad or revoked clients', function (): void {
    $ctx = interopAdmin();
    $data = interopRegisterPartner($ctx, [OauthPartner::SCOPE_FHIR_PATIENT, OauthPartner::SCOPE_FHIR_ENCOUNTER]);

    $token = interopToken($data, [OauthPartner::SCOPE_FHIR_PATIENT]);
    expect($token)->toStartWith('ptr_');

    // The stored value is the hash, never the token.
    expect(OauthPartnerToken::query()->firstOrFail()->token_hash)->not->toBe($token);

    // Wrong secret → 401 (no enumeration).
    $this->postJson('/api/v1/interop/oauth/token', [
        'clientId' => $data['clientId'],
        'clientSecret' => 'wrong-secret-'.Str::uuid(),
        'scope' => [OauthPartner::SCOPE_FHIR_PATIENT],
    ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    // Requesting a scope the partner does not hold → 403.
    $this->postJson('/api/v1/interop/oauth/token', [
        'clientId' => $data['clientId'],
        'clientSecret' => $data['clientSecret'],
        'scope' => [OauthPartner::SCOPE_FHIR_DIAGNOSTIC_REPORT],
    ])->assertStatus(403);

    // A revoked partner can no longer obtain tokens.
    $partner = OauthPartner::query()->firstOrFail();
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/interop/partners/{$partner->getKey()}/revoke")
        ->assertStatus(200)->assertJsonPath('data.partner.status', OauthPartner::STATUS_REVOKED);

    $this->postJson('/api/v1/interop/oauth/token', [
        'clientId' => $data['clientId'],
        'clientSecret' => $data['clientSecret'],
        'scope' => [OauthPartner::SCOPE_FHIR_PATIENT],
    ])->assertStatus(401);
});

it('revoking a partner revokes every active token', function (): void {
    $ctx = interopAdmin();
    $data = interopRegisterPartner($ctx, [OauthPartner::SCOPE_FHIR_PATIENT]);
    $token = interopToken($data, [OauthPartner::SCOPE_FHIR_PATIENT]);

    $partner = OauthPartner::query()->firstOrFail();
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/interop/partners/{$partner->getKey()}/revoke")
        ->assertStatus(200);

    $this->withToken($token)->getJson('/api/v1/interop/fhir/Patient/'.Str::uuid())
        ->assertStatus(401);
});

// ───────────────────────── Partner FHIR surface ──────────────────────────

it('requires partner authentication and the resource scope on the FHIR surface', function (): void {
    $ctx = interopAdmin();
    $patient = interopPatient($ctx['org'], $ctx['facility']);
    $data = interopRegisterPartner($ctx, [OauthPartner::SCOPE_FHIR_PATIENT]);
    $token = interopToken($data, [OauthPartner::SCOPE_FHIR_PATIENT]);

    // No token → 401.
    $this->getJson("/api/v1/interop/fhir/Patient/{$patient->getKey()}")->assertStatus(401);

    // A token scoped for Patient cannot read an Encounter.
    $encounter = Encounter::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $ctx['staff']->getKey(),
    ]);
    $this->withToken($token)->getJson("/api/v1/interop/fhir/Encounter/{$encounter->getKey()}")
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
});

it('enforces consent at the boundary: no active data-use consent → 403', function (): void {
    $ctx = interopAdmin();
    $patient = interopPatient($ctx['org'], $ctx['facility']);
    $data = interopRegisterPartner($ctx, [OauthPartner::SCOPE_FHIR_PATIENT]);
    $token = interopToken($data, [OauthPartner::SCOPE_FHIR_PATIENT]);

    $this->withToken($token)->getJson("/api/v1/interop/fhir/Patient/{$patient->getKey()}")
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
});

it('serves the FHIR Patient projection to a scoped, consented partner', function (): void {
    $ctx = interopAdmin();
    $patient = interopPatient($ctx['org'], $ctx['facility'], [
        'mrn' => 'MRN-INTEROP-1',
        'full_name' => 'Interop Test Patient',
    ]);
    interopConsent($ctx, $patient, OauthPartner::SCOPE_FHIR_PATIENT);

    $data = interopRegisterPartner($ctx, [OauthPartner::SCOPE_FHIR_PATIENT]);
    $token = interopToken($data, [OauthPartner::SCOPE_FHIR_PATIENT]);

    $this->withToken($token)->getJson("/api/v1/interop/fhir/Patient/{$patient->getKey()}")
        ->assertStatus(200)
        ->assertJsonPath('data.resourceType', 'Patient')
        ->assertJsonPath('data.id', $patient->getKey())
        ->assertJsonPath('data.identifier.0.value', 'MRN-INTEROP-1');

    // The exchange is audited with facts only (no clinical content).
    $event = AuditEvent::query()->where('action', 'interop.fhir_projected')->firstOrFail();
    expect($event->payload)->toHaveKeys(['resourceType', 'partnerId', 'scope'])
        ->and(collect($event->payload)->keys()->contains(fn (string $k): bool => str_contains(strtolower($k), 'name')))->toBeFalse();
});

it('serves the FHIR DiagnosticReport only for REPORTED orders', function (): void {
    $ctx = interopAdmin();
    $patient = interopPatient($ctx['org'], $ctx['facility']);
    interopConsent($ctx, $patient, OauthPartner::SCOPE_FHIR_DIAGNOSTIC_REPORT);

    $encounter = Encounter::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $ctx['staff']->getKey(),
    ]);

    $draft = LabOrder::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'encounter_id' => $encounter->getKey(),
        'status' => LabOrder::STATUS_ORDERED,
    ]);
    $reported = LabOrder::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'encounter_id' => $encounter->getKey(),
        'status' => LabOrder::STATUS_REPORTED,
        'reported_at' => now(),
    ]);

    $data = interopRegisterPartner($ctx, [OauthPartner::SCOPE_FHIR_DIAGNOSTIC_REPORT]);
    $token = interopToken($data, [OauthPartner::SCOPE_FHIR_DIAGNOSTIC_REPORT]);

    // A draft order is not releasable → 404 (no existence leak).
    $this->withToken($token)->getJson("/api/v1/interop/fhir/DiagnosticReport/{$draft->getKey()}")
        ->assertStatus(404);

    $this->withToken($token)->getJson("/api/v1/interop/fhir/DiagnosticReport/{$reported->getKey()}")
        ->assertStatus(200)
        ->assertJsonPath('data.resourceType', 'DiagnosticReport')
        ->assertJsonPath('data.status', 'final');
});

it('isolates the partner surface across tenants (a tenant A token cannot read tenant B data)', function (): void {
    $ctxA = interopAdmin();
    $patientA = interopPatient($ctxA['org'], $ctxA['facility'], ['mrn' => 'MRN-ISO-A']);
    interopConsent($ctxA, $patientA, OauthPartner::SCOPE_FHIR_PATIENT);
    $dataA = interopRegisterPartner($ctxA, [OauthPartner::SCOPE_FHIR_PATIENT]);
    $tokenA = interopToken($dataA, [OauthPartner::SCOPE_FHIR_PATIENT]);

    $ctxB = interopAdmin();
    $patientB = interopPatient($ctxB['org'], $ctxB['facility'], ['mrn' => 'MRN-ISO-B']);

    // Tenant A's partner token cannot even RESOLVE tenant B's patient — the
    // RLS projection makes the binding a 404 (no existence leak).
    $this->withToken($tokenA)->getJson("/api/v1/interop/fhir/Patient/{$patientB->getKey()}")
        ->assertStatus(404);

    // It CAN read its own tenant's patient.
    $this->withToken($tokenA)->getJson("/api/v1/interop/fhir/Patient/{$patientA->getKey()}")
        ->assertStatus(200)->assertJsonPath('data.id', $patientA->getKey());
});

it('audits partner token issuance and registrations with facts only', function (): void {
    $ctx = interopAdmin();
    $data = interopRegisterPartner($ctx, [OauthPartner::SCOPE_FHIR_PATIENT]);
    interopToken($data, [OauthPartner::SCOPE_FHIR_PATIENT]);

    expect(AuditEvent::query()->where('action', 'interop.partner_registered')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'interop.partner_token_issued')->count())->toBe(1);

    $tokenEvent = AuditEvent::query()->where('action', 'interop.partner_token_issued')->firstOrFail();
    expect($tokenEvent->actor_type)->toBe(AuditEvent::ACTOR_INTEGRATION)
        ->and($tokenEvent->payload)->toHaveKeys(['scopes', 'ttlSeconds']);

    // No client secret or token value anywhere in the audit payloads.
    foreach (AuditEvent::query()->where('action', 'like', 'interop.%')->get() as $event) {
        expect(collect($event->payload)->keys()->contains(
            fn (string $k): bool => str_contains(strtolower($k), 'secret') || str_contains(strtolower($k), 'token_value')
        ))->toBeFalse("audit payload leaked a secret-ish key in {$event->action}");
    }
});

it('keeps the registry cross-tenant invisible at the API level', function (): void {
    $ctxA = interopAdmin();
    Integration::factory()->create([
        'tenant_id' => $ctxA['org']->getKey(),
        'provider' => 'swasthya-a',
        'type' => Integration::TYPE_FHIR,
    ]);

    $ctxB = interopAdmin();
    $adminB = Identity::user();
    Identity::assign($adminB, 'hospital_admin', $ctxB['org'], $ctxB['facility']);

    // Tenant B's admin sees only tenant B's (empty) registry.
    $this->withToken(Identity::tokenFor($adminB))
        ->getJson('/api/v1/interop/integrations')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.integrations');

    // A tenant-B admin cannot bind tenant A's integration (RLS 404).
    $integrationA = Integration::query()->where('tenant_id', $ctxA['org']->getKey())->firstOrFail();
    $this->withToken(Identity::tokenFor($adminB))
        ->postJson("/api/v1/interop/integrations/{$integrationA->getKey()}/status", [
            'status' => Integration::STATUS_DISABLED,
        ])->assertStatus(404);
});
