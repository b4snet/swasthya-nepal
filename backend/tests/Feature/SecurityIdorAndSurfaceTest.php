<?php

use App\Models\Facility;
use App\Models\FacilitySetting;
use App\Models\InsurancePolicy;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\ScheduleException;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * PROGRAM PHASE 2 (IDOR/surface) — the remaining nested resources not swept
 * by the Phase-1 suite, plus the security-header contract on every response.
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * @return array{orgB: Organization, facilityB: Facility, patientB: Patient}
 */
function idorVictim(): array
{
    $orgB = Identity::organization(['code' => 'idor-victim']);
    $facilityB = Identity::facility($orgB);
    $patientB = Patient::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);

    return compact('orgB', 'facilityB', 'patientB');
}

it('denies the remaining nested-resource writes cross-tenant (policy/document/schedule/settings)', function () {
    $victim = idorVictim();
    $orgB = $victim['orgB'];
    $facilityB = $victim['facilityB'];

    $policyB = InsurancePolicy::factory()->create(['tenant_id' => $orgB->getKey(), 'patient_id' => $victim['patientB']->getKey()]);
    $documentB = PatientDocument::factory()->create(['tenant_id' => $orgB->getKey(), 'patient_id' => $victim['patientB']->getKey()]);
    $settingB = FacilitySetting::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'key' => 'idor.key']);
    $staffB = Staff::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    $exceptionB = ScheduleException::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'staff_id' => $staffB->getKey()]);

    $orgA = Identity::organization(['code' => 'idor-attacker']);
    $facilityA = Identity::facility($orgA);
    $adminA = Identity::user(['email' => 'idor-a@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);
    $token = Identity::tokenFor($adminA);

    $attacks = [
        // Insurance policy: cross-tenant UPDATE and cancel.
        ["/api/v1/insurance-policies/{$policyB->getKey()}", 'PATCH', ['policyNumber' => 'PWNED']],
        ["/api/v1/insurance-policies/{$policyB->getKey()}/cancel", 'POST', ['reason' => 'attack']],
        // Patient document: metadata update and delete.
        ["/api/v1/patients/{$victim['patientB']->getKey()}/documents/{$documentB->getKey()}", 'PATCH', ['status' => 'active']],
        ["/api/v1/patients/{$victim['patientB']->getKey()}/documents/{$documentB->getKey()}", 'DELETE', []],
        // Facility setting: destroy a victim key.
        ["/api/v1/facilities/{$facilityB->getKey()}/settings/{$settingB->key}", 'DELETE', []],
        // Schedule exception: update.
        ["/api/v1/schedule-exceptions/{$exceptionB->getKey()}", 'PATCH', ['reason' => 'attack']],
    ];

    foreach ($attacks as [$uri, $method, $payload]) {
        $response = match ($method) {
            'POST' => $this->withToken($token)->postJson($uri, $payload),
            'PATCH' => $this->withToken($token)->patchJson($uri, $payload),
            default => $this->withToken($token)->deleteJson($uri),
        };

        expect(in_array($response->status(), [403, 404, 422], true))
            ->toBeTrue("$method $uri fails safely (got {$response->status()})");
    }

    // Victim graph untouched.
    expect($policyB->refresh()->policy_number)->not->toBe('PWNED')
        ->and(PatientDocument::query()->find($documentB->getKey()))->not->toBeNull()
        ->and(FacilitySetting::query()->find($settingB->getKey()))->not->toBeNull()
        ->and($exceptionB->refresh()->reason)->not->toBe('attack');
});

it('does not leak cross-tenant data through role-assignment revoke or user listing', function () {
    $victim = idorVictim();
    $orgB = $victim['orgB'];

    // Tenant B's user and assignment.
    $userB = Identity::user(['email' => 'user-b@idor.test']);
    $assignmentB = Identity::assign($userB, 'hospital_admin', $orgB);

    $orgA = Identity::organization(['code' => 'idor-attacker-2']);
    $facilityA = Identity::facility($orgA);
    $adminA = Identity::user(['email' => 'idor-a2@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);
    $token = Identity::tokenFor($adminA);

    // Revoking tenant B's assignment by id → safe denial (403 SCOPE_DENIED:
    // the tenant gate fires before binding, so the victim's identity is
    // never even resolved).
    $this->withToken($token)
        ->deleteJson("/api/v1/organizations/{$orgB->getKey()}/users/{$userB->getKey()}/assignments/{$assignmentB->getKey()}")
        ->assertStatus(403);

    // There is no org-scoped user-listing GET route at all → 405 is a safe
    // "no such operation" denial, not a leak.
    $this->withToken($token)
        ->getJson("/api/v1/organizations/{$orgB->getKey()}/users")
        ->assertStatus(405);

    expect($assignmentB->refresh()->status)->toBe('active');
});

it('sends the documented security headers on every API response', function () {
    $orgA = Identity::organization(['code' => 'headers-org']);
    $adminA = Identity::user(['email' => 'headers@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);

    $response = $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000');

    // Error responses carry the same set.
    $error = $this->withToken('bogus-token')->getJson('/api/v1/auth/me')->assertStatus(401);
    expect($error->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
});

it('rejects mass-assigned tenant context fields at the validation boundary', function () {
    $orgA = Identity::organization(['code' => 'mass-org']);
    $facilityA = Identity::facility($orgA);
    $adminA = Identity::user(['email' => 'mass@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);

    // A forged tenant_id/facility_id is an unknown field → 422; the record
    // would land in the caller's tenant (server-derived), never the forged one.
    $this->withToken(Identity::tokenFor($adminA))
        ->postJson("/api/v1/organizations/{$orgA->getKey()}/departments", [
            'name' => 'Mass Dept',
            'code' => 'mass-'.substr((string) Str::uuid(), 0, 6),
            'tenant_id' => (string) Str::uuid(),
            'facility_id' => (string) Str::uuid(),
            'created_by' => (string) Str::uuid(),
            'id' => (string) Str::uuid(),
        ])
        ->assertStatus(422);
});
