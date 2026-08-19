<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Patient;
use App\Models\PatientConsentRecord;
use App\Models\PortalAccessGrant;
use App\Models\PortalAccount;
use App\Models\Staff;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 16 — Patient Portal PHR tests.
 *
 * Uses the established Identity/portalLogin pattern from PatientPortalTest.
 * Covers PHR data endpoints, messaging, preferences, consent, self-only.
 */
beforeEach(function (): void {
    seedIdentity();
    config()->set('swasthya.rate_limits.auth', 1000);
});

function phrSetup(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $patient = Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $staff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $admin->getKey(),
        'status' => 'active',
    ]);

    // Provision portal account
    $identifier = 'phr-test-'.Str::random(8);
    $password = 'correct-horse-battery-staple';
    $account = PortalAccount::create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'login_identifier' => $identifier,
        'password_hash' => bcrypt($password),
        'status' => 'active',
        'failed_attempts' => 0,
        'mfa_enabled' => false,
        'lock_version' => 0,
    ]);

    // Grant all PHR scopes
    $scopes = [
        'appointments', 'results', 'bills', 'medical_history', 'prescriptions',
        'documents', 'radiology', 'referrals', 'care_plans', 'immunizations', 'messaging',
    ];
    foreach ($scopes as $scope) {
        PortalAccessGrant::create([
            'tenant_id' => $org->getKey(),
            'facility_id' => $facility->getKey(),
            'portal_account_id' => $account->getKey(),
            'patient_id' => $patient->getKey(),
            'data_scope' => $scope,
            'purpose' => 'patient_access',
            'status' => 'granted',
            'granted_at' => now(),
            'granted_by_staff_id' => $staff->getKey(),
        ]);
    }

    // Create a portal token directly (bypassing login throttle for test speed)
    $token = $account->createToken('test-portal')->plainTextToken;

    return compact('org', 'facility', 'patient', 'staff', 'account', 'token');
}

// ── Authorization ──

it('rejects unauthenticated portal access', function (): void {
    $this->getJson('/api/v1/portal/me')->assertStatus(401);
});

it('returns portal account info via /me', function (): void {
    $ctx = phrSetup();
    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/me');
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['account' => ['id', 'patientId', 'status'], 'grants']]);
    expect($response->json('data.account.id'))->not->toBeNull();
});

it('returns patient profile', function (): void {
    $ctx = phrSetup();
    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/profile');
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['patient' => ['id', 'fullName', 'mrn']]]);
});

// ── PHR Data ──

it('returns medical history', function (): void {
    $ctx = phrSetup();
    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/medical-history');
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['allergies', 'diagnoses']]);
});

it('returns medications', function (): void {
    $ctx = phrSetup();
    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/medications');
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['medications']]);
});

it('returns lab results', function (): void {
    $ctx = phrSetup();
    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/lab-results');
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['results']]);
});

it('returns radiology reports', function (): void {
    $ctx = phrSetup();
    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/radiology-reports');
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['reports']]);
});

it('returns documents', function (): void {
    $ctx = phrSetup();
    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/documents');
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['documents']]);
});

it('returns immunizations', function (): void {
    $ctx = phrSetup();
    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/immunizations');
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['immunizations']]);
});

it('returns referrals', function (): void {
    $ctx = phrSetup();
    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/referrals');
    $response->assertOk();
    $response->assertJsonStructure(['data' => ['referrals']]);
});

// ── Messaging ──

it('sends and reads secure messages', function (): void {
    $ctx = phrSetup();

    $sendResponse = $this->withToken($ctx['token'])
        ->postJson('/api/v1/portal/messages', [
            'recipientStaffId' => $ctx['staff']->getKey(),
            'subject' => 'Test question',
            'body' => 'Hello doctor',
            'category' => 'clinical',
        ]);
    $sendResponse->assertCreated();

    $listResponse = $this->withToken($ctx['token'])->getJson('/api/v1/portal/messages');
    $listResponse->assertOk();
    expect($listResponse->json('data.messages'))->toHaveCount(1)
        ->and($listResponse->json('data.messages.0.subject'))->toBe('Test question');
});

it('rejects message without required fields', function (): void {
    $ctx = phrSetup();
    $this->withToken($ctx['token'])
        ->postJson('/api/v1/portal/messages', ['subject' => 'test'])
        ->assertStatus(422);
});

// ── Notification Preferences ──

it('returns and updates notification preferences', function (): void {
    $ctx = phrSetup();
    $getResponse = $this->withToken($ctx['token'])->getJson('/api/v1/portal/notification-preferences');
    $getResponse->assertOk();
    $getResponse->assertJsonPath('data.preferences.emailEnabled', true);

    $updateResponse = $this->withToken($ctx['token'])
        ->putJson('/api/v1/portal/notification-preferences', [
            'emailEnabled' => false,
            'smsEnabled' => true,
            'preferredLanguage' => 'ne',
        ]);
    $updateResponse->assertOk();
    $updateResponse->assertJsonPath('data.preferences.emailEnabled', false)
        ->assertJsonPath('data.preferences.smsEnabled', true)
        ->assertJsonPath('data.preferences.preferredLanguage', 'ne');
});

// ── Consent Management ──

it('returns consent records and revokes consent', function (): void {
    $ctx = phrSetup();

    PatientConsentRecord::create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $ctx['patient']->getKey(),
        'data_category' => 'lab_results',
        'consent_status' => 'granted',
        'purpose' => 'patient_access',
        'granted_by' => 'patient',
        'granted_at' => now(),
    ]);

    $listResponse = $this->withToken($ctx['token'])->getJson('/api/v1/portal/consents');
    $listResponse->assertOk();
    expect($listResponse->json('data.consents'))->toHaveCount(1);

    $consentId = $listResponse->json('data.consents.0.id');
    $revokeResponse = $this->withToken($ctx['token'])
        ->postJson('/api/v1/portal/consents/revoke', [
            'consentId' => $consentId,
            'reason' => 'No longer needed',
        ]);
    $revokeResponse->assertOk();
    $revokeResponse->assertJsonPath('data.consent.status', 'revoked');
});

// ── Self-Only Access ──

it('enforces self-only access across patients', function (): void {
    $ctx = phrSetup();

    // Create second patient
    $patient2 = Patient::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
    ]);
    $account2 = PortalAccount::create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient2->getKey(),
        'login_identifier' => 'phr-other-'.Str::random(8),
        'password_hash' => bcrypt('correct-horse-battery-staple'),
        'status' => 'active',
        'failed_attempts' => 0,
        'mfa_enabled' => false,
        'lock_version' => 0,
    ]);
    PortalAccessGrant::create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'portal_account_id' => $account2->getKey(),
        'patient_id' => $patient2->getKey(),
        'data_scope' => 'messaging',
        'purpose' => 'patient_access',
        'status' => 'granted',
        'granted_at' => now(),
        'granted_by_staff_id' => $ctx['staff']->getKey(),
    ]);

    $token2 = $account2->createToken('test-portal-2')->plainTextToken;

    // Patient 2 sends a message
    $this->withToken($token2)->postJson('/api/v1/portal/messages', [
        'recipientStaffId' => $ctx['staff']->getKey(),
        'subject' => 'Patient 2 message',
        'body' => 'Hello from patient 2',
    ])->assertCreated();

    // Patient 1 sees only their own messages (empty)
    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/messages');
    $response->assertOk();
    expect($response->json('data.messages'))->toHaveCount(0);

    // Patient 2 sees only their own
    $response2 = $this->withToken($token2)->getJson('/api/v1/portal/messages');
    $response2->assertOk();
    expect($response2->json('data.messages'))->toHaveCount(1);
});

// ── Revoked Grant Blocks Access ──

it('denies PHR access when grant is revoked', function (): void {
    $ctx = phrSetup();

    PortalAccessGrant::where('portal_account_id', $ctx['account']->getKey())
        ->where('data_scope', 'medical_history')
        ->update(['status' => 'revoked', 'revoked_at' => now()]);

    $response = $this->withToken($ctx['token'])->getJson('/api/v1/portal/medical-history');
    $response->assertStatus(403);
});
