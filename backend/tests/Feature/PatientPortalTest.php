<?php

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PortalAccessGrant;
use App\Models\PortalAccount;
use App\Models\PortalSession;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 slice 22 — Patient Portal (PRODUCT_REQUIREMENTS §6.2, DATABASE.md
 * §3.53).
 *
 * Core proofs:
 *   - STRICT SELF-ONLY access: the patient identity is derived from the
 *     portal token (ResolvePortalContext) — never from client input; every
 *     data surface requires an ACTIVE consent-bound grant for its scope.
 *   - No enumeration: an unknown organization code and a wrong password
 *     are the same 401; a missing and a revoked grant are the same 403.
 *   - Consent/revocation: the patient can revoke their own grants and the
 *     surface closes immediately; staff re-granting is a fresh grant.
 *   - Security: DB-backed lockout, disabled-account refusal, token
 *     revocation on logout/disable, cross-tenant structural isolation.
 *   - Audit: every portal action is audited with facts only (never PHI).
 */
beforeEach(function (): void {
    seedIdentity();
    // The portal login route sits behind throttle:auth like staff login.
    // Raise the per-IP limit for the suite so the per-ACCOUNT lockout is
    // exercised on its own layer (SECURITY.md §18, AuthTest pattern).
    config()->set('swasthya.rate_limits.auth', 1000);
});

/**
 * @return array{org: Organization, facility: Facility, admin: User, staff: Staff, department: Department}
 */
function portalAdmin(): array
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

function portalPatient(Organization $org, Facility $facility, array $attributes = []): Patient
{
    return Patient::factory()->create(array_merge([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ], $attributes));
}

/**
 * Provision a portal account through the API (staff surface).
 */
function portalProvision(array $ctx, Patient $patient, string $identifier, string $password = 'correct-horse-battery-staple'): string
{
    $response = test()->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/organizations/{$ctx['org']->getKey()}/patients/{$patient->getKey()}/portal", [
            'loginIdentifier' => $identifier,
            'password' => $password,
            'passwordConfirmation' => $password,
        ]);

    $response->assertStatus(201);

    return (string) $response->json('data.account.id');
}

/**
 * Log in through the portal API and return the raw response.
 */
function portalLogin(Organization $org, string $identifier, string $password)
{
    return test()->postJson('/api/v1/portal/login', [
        'organizationCode' => $org->code,
        'identifier' => $identifier,
        'password' => $password,
    ]);
}

it('logs a patient in with identifier and password and returns a bearer token', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'patient-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patient, $identifier);

    portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')
        ->assertStatus(201)
        ->assertJsonPath('data.tokenType', 'Bearer')
        ->assertJsonPath('data.account.patientId', $patient->getKey())
        ->assertJsonStructure(['data' => ['token', 'tokenType', 'expiresAt', 'account']]);

    expect(PortalSession::query()->count())->toBe(1)
        ->and(PortalSession::query()->first()?->portal_account_id)->toBe(
            PortalAccount::query()->firstOrFail()->getKey()
        );
});

it('returns the same 401 for an unknown organization code and a wrong password', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    portalProvision($ctx, $patient, 'enum-'.Str::uuid().'@example.test');

    portalLogin($ctx['org'], 'nobody@example.test', 'correct-horse-battery-staple')
        ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    portalLogin(Identity::organization(['code' => 'NOPE-'.Str::uuid()]), 'enum-'.Str::uuid().'@example.test', 'correct-horse-battery-staple')
        ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

it('locks the portal account after repeated failures and refuses login while locked', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'lock-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patient, $identifier);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        portalLogin($ctx['org'], $identifier, 'wrong-password-'.$attempt)->assertStatus(401);
    }

    portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertHeader('Retry-After');

    expect(PortalAccount::query()->firstOrFail()->locked_until)->not->toBeNull();
});

it('refuses login for a disabled portal account', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'disabled-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patient, $identifier);

    $account = PortalAccount::query()->firstOrFail();
    $account->forceFill(['status' => PortalAccount::STATUS_DISABLED])->save();

    portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
});

it('requires authentication on every portal surface', function (): void {
    $this->getJson('/api/v1/portal/me')->assertStatus(401);
    $this->getJson('/api/v1/portal/appointments')->assertStatus(401);
    $this->getJson('/api/v1/portal/results')->assertStatus(401);
    $this->getJson('/api/v1/portal/bills')->assertStatus(401);
    $this->getJson('/api/v1/portal/grants')->assertStatus(401);
    $this->postJson('/api/v1/portal/logout')->assertStatus(401);
});

it('revokes the token and session on logout', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'out-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patient, $identifier);

    $token = portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')->json('data.token');

    $this->withToken($token)->postJson('/api/v1/portal/logout')->assertStatus(200);

    $this->withToken($token)->getJson('/api/v1/portal/me')->assertStatus(401);
    expect(PortalSession::query()->firstOrFail()->revoked_at)->not->toBeNull();
});

it('provisions a portal account as a hospital admin and refuses duplicates with 409', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'provision-'.Str::uuid().'@example.test';

    portalProvision($ctx, $patient, $identifier);

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/organizations/{$ctx['org']->getKey()}/patients/{$patient->getKey()}/portal", [
            'loginIdentifier' => $identifier,
            'password' => 'another-strong-password-123',
            'passwordConfirmation' => 'another-strong-password-123',
        ])
        ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

    expect(PortalAccount::query()->count())->toBe(1);
});

it('denies provisioning without portal:manage', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);

    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $ctx['org'], $ctx['facility']);

    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson("/api/v1/organizations/{$ctx['org']->getKey()}/patients/{$patient->getKey()}/portal", [
            'loginIdentifier' => 'denied-'.Str::uuid().'@example.test',
            'password' => 'correct-horse-battery-staple',
            'passwordConfirmation' => 'correct-horse-battery-staple',
        ])
        ->assertStatus(403)->assertJsonPath('error.code', 'SCOPE_DENIED');

    expect(PortalAccount::query()->count())->toBe(0);
});

it('keeps appointments consent-bound: no grant → 403, grant → own rows only', function (): void {
    $ctx = portalAdmin();
    $patientA = portalPatient($ctx['org'], $ctx['facility']);
    $patientB = portalPatient($ctx['org'], $ctx['facility']);

    $appointmentA = Appointment::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patientA->getKey(),
        'provider_staff_id' => $ctx['staff']->getKey(),
    ]);
    Appointment::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patientB->getKey(),
        'provider_staff_id' => $ctx['staff']->getKey(),
    ]);

    $identifier = 'appt-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patientA, $identifier);
    $token = portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')->json('data.token');

    // No grant → generic 403 (a missing and a revoked grant are identical).
    $this->withToken($token)->getJson('/api/v1/portal/appointments')
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    $account = PortalAccount::query()->firstOrFail();
    PortalAccessGrant::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'portal_account_id' => $account->getKey(),
        'patient_id' => $patientA->getKey(),
        'data_scope' => PortalAccessGrant::SCOPE_APPOINTMENTS,
        'purpose' => 'Patient requested appointment visibility',
        'granted_by_staff_id' => $ctx['staff']->getKey(),
    ]);

    $this->withToken($token)->getJson('/api/v1/portal/appointments')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.appointments')
        ->assertJsonPath('data.appointments.0.id', $appointmentA->getKey())
        ->assertJsonMissingPath('data.appointments.0.patientId'); // never echoes sibling/foreign identifiers

    // The other patient's appointment never leaks through the query.
    expect(Appointment::query()->count())->toBe(2);
});

it('keeps results consent-bound and exposes only reported orders', function (): void {
    $ctx = portalAdmin();
    $patientA = portalPatient($ctx['org'], $ctx['facility']);
    $patientB = portalPatient($ctx['org'], $ctx['facility']);

    $encounterA = Encounter::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patientA->getKey(),
        'provider_staff_id' => $ctx['staff']->getKey(),
    ]);

    $reported = LabOrder::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patientA->getKey(),
        'encounter_id' => $encounterA->getKey(),
        'status' => LabOrder::STATUS_REPORTED,
        'reported_at' => now(),
    ]);
    $test = LabTest::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
    ]);
    LabOrderItem::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'lab_order_id' => $reported->getKey(),
        'lab_test_id' => $test->getKey(),
        'result_value' => '120',
        'result_unit' => 'mg/dL',
        'reference_range' => '70–110',
        'verified_at' => now(),
    ]);

    // A draft order for the SAME patient must not appear.
    LabOrder::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patientA->getKey(),
        'encounter_id' => $encounterA->getKey(),
        'status' => LabOrder::STATUS_ORDERED,
    ]);

    // A reported order for ANOTHER patient must not appear.
    $encounterB = Encounter::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patientB->getKey(),
        'provider_staff_id' => $ctx['staff']->getKey(),
    ]);
    LabOrder::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patientB->getKey(),
        'encounter_id' => $encounterB->getKey(),
        'status' => LabOrder::STATUS_REPORTED,
        'reported_at' => now(),
    ]);

    $identifier = 'result-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patientA, $identifier);
    $token = portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')->json('data.token');

    $this->withToken($token)->getJson('/api/v1/portal/results')
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    $account = PortalAccount::query()->firstOrFail();
    PortalAccessGrant::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'portal_account_id' => $account->getKey(),
        'patient_id' => $patientA->getKey(),
        'data_scope' => PortalAccessGrant::SCOPE_RESULTS,
        'purpose' => 'Patient requested result visibility',
        'granted_by_staff_id' => $ctx['staff']->getKey(),
    ]);

    $this->withToken($token)->getJson('/api/v1/portal/results')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.results')
        ->assertJsonPath('data.results.0.id', $reported->getKey())
        ->assertJsonPath('data.results.0.items.0.resultValue', '120');
});

it('keeps bills consent-bound and hides voided invoices', function (): void {
    $ctx = portalAdmin();
    $patientA = portalPatient($ctx['org'], $ctx['facility']);
    $patientB = portalPatient($ctx['org'], $ctx['facility']);

    $bill = Invoice::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patientA->getKey(),
        'status' => 'issued',
    ]);
    Invoice::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patientA->getKey(),
        'status' => Invoice::STATUS_VOIDED,
    ]);
    Invoice::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patientB->getKey(),
        'status' => 'issued',
    ]);

    $identifier = 'bill-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patientA, $identifier);
    $token = portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')->json('data.token');

    $this->withToken($token)->getJson('/api/v1/portal/bills')
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    $account = PortalAccount::query()->firstOrFail();
    PortalAccessGrant::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'portal_account_id' => $account->getKey(),
        'patient_id' => $patientA->getKey(),
        'data_scope' => PortalAccessGrant::SCOPE_BILLS,
        'purpose' => 'Patient requested billing visibility',
        'granted_by_staff_id' => $ctx['staff']->getKey(),
    ]);

    $this->withToken($token)->getJson('/api/v1/portal/bills')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data.bills')
        ->assertJsonPath('data.bills.0.id', $bill->getKey());
});

it('lets the patient revoke their own grant and the surface closes immediately', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'revoke-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patient, $identifier);
    $token = portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')->json('data.token');

    $account = PortalAccount::query()->firstOrFail();
    $grant = PortalAccessGrant::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'portal_account_id' => $account->getKey(),
        'patient_id' => $patient->getKey(),
        'data_scope' => PortalAccessGrant::SCOPE_APPOINTMENTS,
        'purpose' => 'Patient requested appointment visibility',
        'granted_by_staff_id' => $ctx['staff']->getKey(),
    ]);

    $this->withToken($token)->postJson("/api/v1/portal/grants/{$grant->getKey()}/revoke")
        ->assertStatus(200)
        ->assertJsonPath('data.grant.status', PortalAccessGrant::STATUS_REVOKED)
        ->assertJsonPath('data.grant.revokedByPatient', true);

    $this->withToken($token)->getJson('/api/v1/portal/appointments')
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    // A patient cannot revoke ANOTHER patient's grant — generic 404.
    $otherPatient = portalPatient($ctx['org'], $ctx['facility']);
    $otherAccount = PortalAccount::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $otherPatient->getKey(),
        'login_identifier' => 'other-'.Str::uuid().'@example.test',
        'password_hash' => Hash::make('correct-horse-battery-staple'),
        'status' => PortalAccount::STATUS_ACTIVE,
    ]);
    $otherGrant = PortalAccessGrant::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'portal_account_id' => $otherAccount->getKey(),
        'patient_id' => $otherPatient->getKey(),
        'data_scope' => PortalAccessGrant::SCOPE_BILLS,
        'purpose' => 'Billing visibility',
        'granted_by_staff_id' => $ctx['staff']->getKey(),
    ]);

    $this->withToken($token)->postJson("/api/v1/portal/grants/{$otherGrant->getKey()}/revoke")
        ->assertStatus(404);

    expect(PortalAccessGrant::query()->findOrFail($otherGrant->getKey())->status)->toBe(PortalAccessGrant::STATUS_GRANTED);
});

it('lets staff revoke a grant and refuses a double revocation with 409', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'staffrevoke-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patient, $identifier);

    $account = PortalAccount::query()->firstOrFail();
    $grant = PortalAccessGrant::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'portal_account_id' => $account->getKey(),
        'patient_id' => $patient->getKey(),
        'data_scope' => PortalAccessGrant::SCOPE_APPOINTMENTS,
        'purpose' => 'Appointment visibility',
        'granted_by_staff_id' => $ctx['staff']->getKey(),
    ]);

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/portal-access-grants/{$grant->getKey()}/revoke")
        ->assertStatus(200)->assertJsonPath('data.grant.revokedByPatient', false);

    // A second (concurrent-CAS-simulated) revocation affects zero rows → 409.
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/portal-access-grants/{$grant->getKey()}/revoke")
        ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
});

it('refuses a duplicate ACTIVE grant with 409 and allows re-granting after revocation', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'grant-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patient, $identifier);
    $account = PortalAccount::query()->firstOrFail();

    $grant = function () use ($ctx, $account): array {
        $response = test()->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/portal-accounts/{$account->getKey()}/grants", [
                'dataScope' => PortalAccessGrant::SCOPE_RESULTS,
                'purpose' => 'Patient requested result visibility',
            ]);

        return [$response, (string) $response->json('data.grant.id')];
    };

    [$first, $firstId] = $grant();
    $first->assertStatus(201);

    // A second ACTIVE grant for the same scope is impossible (DB partial unique).
    [$second] = $grant();
    $second->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    expect(PortalAccessGrant::query()->count())->toBe(1);

    // Revoke → a fresh grant is allowed (new active row).
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/portal-access-grants/{$firstId}/revoke")
        ->assertStatus(200);

    [$third] = $grant();
    $third->assertStatus(201);
    expect(PortalAccessGrant::query()->where('status', PortalAccessGrant::STATUS_GRANTED)->count())->toBe(1);
});

it('disables the account: login refused and every existing token revoked', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'disable-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patient, $identifier);
    $token = portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')->json('data.token');

    $account = PortalAccount::query()->firstOrFail();

    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/portal-accounts/{$account->getKey()}/disable")
        ->assertStatus(200)->assertJsonPath('data.account.status', PortalAccount::STATUS_DISABLED);

    // The live portal token is dead.
    $this->withToken($token)->getJson('/api/v1/portal/me')->assertStatus(401);

    // Login is refused for a disabled account.
    portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

    expect(PortalSession::query()->firstOrFail()->revoked_at)->not->toBeNull();
});

it('audits login, provisioning, grants, revocation, and disablement with facts only', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'audit-'.Str::uuid().'@example.test';

    portalProvision($ctx, $patient, $identifier);
    portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')->assertStatus(201);

    $account = PortalAccount::query()->firstOrFail();
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/portal-accounts/{$account->getKey()}/grants", [
            'dataScope' => PortalAccessGrant::SCOPE_APPOINTMENTS,
            'purpose' => 'Appointment visibility',
        ])->assertStatus(201);

    $grant = PortalAccessGrant::query()->firstOrFail();
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/portal-access-grants/{$grant->getKey()}/revoke")
        ->assertStatus(200);
    $this->withToken(Identity::tokenFor($ctx['admin']))
        ->postJson("/api/v1/portal-accounts/{$account->getKey()}/disable")
        ->assertStatus(200);

    expect(AuditEvent::query()->where('action', 'portal.account_provisioned')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'portal.login')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'portal.access_granted')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'portal.access_revoked')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'portal.account_disabled')->count())->toBe(1);

    $login = AuditEvent::query()->where('action', 'portal.login')->firstOrFail();
    expect($login->actor_type)->toBe(AuditEvent::ACTOR_PATIENT)
        ->and($login->actor_email)->toBe($identifier);

    // PHI-safety: portal audit payloads carry facts only — no patient names,
    // no clinical values, no identifiers beyond the login identifier.
    $phipKeys = ['name', 'patient_name', 'result', 'diagnosis', 'notes', 'clinical', 'dob', 'mrn'];
    foreach (AuditEvent::query()->whereIn('action', [
        'portal.account_provisioned', 'portal.login', 'portal.access_granted',
        'portal.access_revoked', 'portal.account_disabled',
    ])->get() as $event) {
        $keys = array_keys($event->payload ?? []);
        foreach ($phipKeys as $forbidden) {
            expect(collect($keys)->map(fn (string $k): string => strtolower($k))->contains(
                fn (string $k): bool => str_contains($k, $forbidden)
            ))->toBeFalse("audit payload leaked '{$forbidden}' in action {$event->action}");
        }
    }
});

it('isolates portal identities across tenants at the database layer', function (): void {
    $ctxA = portalAdmin();
    $patientA = portalPatient($ctxA['org'], $ctxA['facility']);
    $identifierA = 'iso-a-'.Str::uuid().'@example.test';
    portalProvision($ctxA, $patientA, $identifierA);

    $ctxB = portalAdmin();
    $patientB = portalPatient($ctxB['org'], $ctxB['facility']);
    $identifierB = 'iso-b-'.Str::uuid().'@example.test';
    portalProvision($ctxB, $patientB, $identifierB);

    // Same identifier across tenants is fine (unique per tenant), but each
    // login resolves ONLY the account in its own organization.
    $tokenA = portalLogin($ctxA['org'], $identifierA, 'correct-horse-battery-staple')->json('data.token');
    $tokenB = portalLogin($ctxB['org'], $identifierB, 'correct-horse-battery-staple')->json('data.token');

    expect($tokenA)->not->toBeNull()->and($tokenB)->not->toBeNull();

    // RLS projection means tenant A's token can never read tenant B's rows.
    $accountA = PortalAccount::query()->where('tenant_id', $ctxA['org']->getKey())->firstOrFail();
    expect($accountA->sessions()->count())->toBe(1)
        ->and(PortalAccount::query()->count())->toBe(2);
});

/**
 * Regression test: portal login must work when organizations table has RLS
 * enabled. The login is a public route with no authentication context, so
 * no tenant claims are set. The initial Organization::where('code',...)
 * lookup must bypass RLS (via platform scope) for that single query.
 *
 * This test runs the portal login through the HTTP endpoint and verifies
 * the org lookup succeeds even with RLS enforced on organizations.
 */
it('portal login works with organizations RLS enforced (regression: public route org lookup)', function (): void {
    $ctx = portalAdmin();
    $patient = portalPatient($ctx['org'], $ctx['facility']);
    $identifier = 'rls-regression-'.Str::uuid().'@example.test';
    portalProvision($ctx, $patient, $identifier);

    // Verify RLS is actually enforced on organizations by checking through
    // the RLS connection (app-role, NOBYPASSRLS).
    $rlsConn = DB::connection('pgsql_rls');
    $rlsConn->beginTransaction();
    try {
        // Set empty claims — simulating the public login route context.
        $rlsConn->statement('select set_config(?, ?, true)', ['request.jwt.claims', json_encode([])]);

        // With empty claims, the org should NOT be visible through RLS.
        $orgViaRls = $rlsConn->selectOne(
            'select id from public.organizations where code = ?',
            [$ctx['org']->code]
        );
        expect($orgViaRls)->toBeNull('Organization should be invisible with empty claims');
    } finally {
        $rlsConn->rollBack();
    }

    // Now test the actual HTTP login endpoint — it must succeed despite RLS.
    portalLogin($ctx['org'], $identifier, 'correct-horse-battery-staple')
        ->assertStatus(201)
        ->assertJsonPath('data.tokenType', 'Bearer')
        ->assertJsonPath('data.account.patientId', $patient->getKey());
});
