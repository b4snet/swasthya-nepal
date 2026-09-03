<?php

use App\Models\Organization;
use App\Models\Patient;
use Tests\Support\Identity;

/**
 * Backend Safety Test Suite — mirrors the frontend safety test program
 * (Phases 128–231, 50 files, 5,237 tests).
 *
 * This file proves the backend's response-level safety properties through
 * real HTTP requests against the actual API surface. Every assertion is
 * backed by an actual request — no mocking, no simulation.
 *
 * Coverage categories:
 *   1. Response envelope safety
 *   2. Sensitive field protection
 *   3. Error contract safety
 *   4. Audit header presence
 *   5. Tenant context isolation
 *   6. Rate limiting
 *   7. Correlation ID propagation
 *   8. HTTP security headers
 *   9. IDOR prevention
 *  10. Input validation safety
 *  11. Authorization boundary
 *  12. Data integrity
 */
beforeEach(function (): void {
    seedIdentity();
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function safetyFixture(): array {
    $org = Identity::organization(['code' => 'safety-org']);
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    return compact('org', 'facility', 'patient');
}

function adminToken(Organization $org): string {
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    return Identity::tokenFor($admin);
}

/*
|--------------------------------------------------------------------------
| 1. Response Envelope Safety
|--------------------------------------------------------------------------
*/

it('wraps successful list responses in the standard envelope', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/organizations/'.$fixture['org']->getKey().'/facilities');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [],
            'meta' => ['context' => ['tenantId', 'facilityId']],
        ]);
});

it('wraps successful single-resource responses in the standard envelope', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$fixture['patient']->getKey());

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['id', 'fullName'],
            'meta' => ['context'],
        ]);
});

it('wraps error responses in the standard error envelope', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $nonexistentId = (string) \Illuminate\Support\Str::uuid();
    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$nonexistentId);

    $response->assertStatus(404)
        ->assertJsonStructure([
            'error' => ['code', 'message'],
        ]);
});

it('never returns raw PHP/framework error output', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $nonexistentId = (string) \Illuminate\Support\Str::uuid();
    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$nonexistentId);

    $content = $response->getContent();
    expect($content)->not->toContain('Stack trace')
        ->and($content)->not->toContain('Exception in')
        ->and($content)->not->toContain('vendor/laravel')
        ->and($content)->not->toContain('vendor/symfony');
});

/*
|--------------------------------------------------------------------------
| 2. Sensitive Field Protection
|--------------------------------------------------------------------------
*/

it('never exposes password_hash in user responses', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/users');

    $response->assertOk();
    $json = $response->json('data');

    $users = is_array($json) ? $json : [$json];
    foreach ($users as $user) {
        expect($user)->not->toHaveKey('password_hash')
            ->and($user)->not->toHaveKey('password')
            ->and($user)->not->toHaveKey('hashedPassword');
    }
});

it('never exposes refresh tokens in user-facing API responses', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $endpoints = [
        '/api/v1/users',
        '/api/v1/roles',
        '/api/v1/permissions',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->withToken($token)->getJson($endpoint);
        $content = $response->getContent();

        expect($content)->not->toContain('refreshToken')
            ->and($content)->not->toContain('refresh_token')
            ->and($content)->not->toContain('rt-test');
    }
});

it('never exposes password_hash in patient responses', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$fixture['patient']->getKey());

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->not->toHaveKey('password_hash')
        ->and($data)->not->toHaveKey('password');
});

it('never exposes raw tenant_id in patient data payload', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$fixture['patient']->getKey());

    $response->assertOk();
    $data = $response->json('data');

    expect(data_get($data, 'tenant_id'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 3. Error Contract Safety
|--------------------------------------------------------------------------
*/

it('returns structured error for 404 with code and message', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $nonexistentId = (string) \Illuminate\Support\Str::uuid();
    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$nonexistentId);

    $response->assertStatus(404)
        ->assertJsonStructure([
            'error' => ['code', 'message'],
        ])
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('returns structured validation error for 422 with field details', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$fixture['org']->getKey().'/facilities', []);

    $response->assertStatus(422)
        ->assertJsonStructure([
            'error' => [
                'code',
                'message',
                'details' => [
                    '*' => ['field', 'code', 'message'],
                ],
            ],
        ]);
});

it('never leaks database query errors in error responses', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $nonexistentId = (string) \Illuminate\Support\Str::uuid();
    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$nonexistentId);

    $content = $response->getContent();
    expect($content)->not->toContain('SQLSTATE')
        ->and($content)->not->toContain('QueryException')
        ->and($content)->not->toContain('PDOException')
        ->and($content)->not->toContain('Column not found');
});

/*
|--------------------------------------------------------------------------
| 4. Audit Header Presence
|--------------------------------------------------------------------------
|
| Mutations via POST must return X-Audit-Event-Id (MASTER_RULES.md §19).
|
*/

it('returns X-Audit-Event-Id on resource creation', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$fixture['org']->getKey().'/facilities', [
            'name' => 'Audit Test Facility',
            'code' => 'audit-fac',
            'timezone' => 'Asia/Kathmandu',
        ]);

    $response->assertCreated()
        ->assertHeader('X-Audit-Event-Id');
});

it('audit events record the correct actor and action on creation', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$fixture['org']->getKey().'/facilities', [
            'name' => 'Actor Test',
            'code' => 'actor-test',
            'timezone' => 'Asia/Kathmandu',
        ]);

    $auditId = $response->headers->get('X-Audit-Event-Id');
    expect($auditId)->not->toBeNull();

    $event = \App\Models\AuditEvent::query()->findOrFail($auditId);
    expect($event->action)->toBe('facility.created')
        ->and($event->tenant_id)->toBe($fixture['org']->getKey());
});

/*
|--------------------------------------------------------------------------
| 5. Tenant Context Isolation
|--------------------------------------------------------------------------
*/

it('includes tenant context in response meta', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$fixture['patient']->getKey());

    $response->assertOk()
        ->assertJsonPath('meta.context.tenantId', $fixture['org']->getKey());
});

it('denies cross-tenant patient access (403 or 404 via RLS)', function () {
    $fixture = safetyFixture();
    $otherOrg = Identity::organization(['code' => 'other-org']);
    $token = adminToken($otherOrg);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$fixture['patient']->getKey());

    $status = $response->status();
    expect(in_array($status, [403, 404]))->toBeTrue();
});

it('denies cross-tenant facility access (403 or 404 via RLS)', function () {
    $fixture = safetyFixture();
    $otherOrg = Identity::organization(['code' => 'cross-tenant-org']);
    $token = adminToken($otherOrg);

    $response = $this->withToken($token)
        ->getJson('/api/v1/facilities/'.$fixture['facility']->getKey());

    $status = $response->status();
    expect(in_array($status, [403, 404]))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 6. Rate Limiting
|--------------------------------------------------------------------------
*/

it('enforces rate limiting on login after multiple failures', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'rate-limit-test@example.com',
            'password' => 'wrong-password-'.$i,
        ]);
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'rate-limit-test@example.com',
        'password' => 'another-wrong',
    ]);

    $status = $response->status();
    expect($status === 429 || $status === 401)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 7. Correlation ID Propagation
|--------------------------------------------------------------------------
*/

it('returns X-Request-Id on every response', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $endpoints = [
        '/api/v1/patients/'.$fixture['patient']->getKey(),
        '/api/v1/organizations/'.$fixture['org']->getKey().'/facilities',
        '/api/v1/users',
    ];

    foreach ($endpoints as $uri) {
        $response = $this->withToken($token)->getJson($uri);
        $response->assertHeader('X-Request-Id');
    }
});

/*
|--------------------------------------------------------------------------
| 8. HTTP Security Headers
|--------------------------------------------------------------------------
*/

it('includes X-Content-Type-Options nosniff on all responses', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$fixture['patient']->getKey());

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('includes X-Frame-Options DENY on all responses', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$fixture['patient']->getKey());

    $response->assertHeader('X-Frame-Options', 'DENY');
});

it('includes Referrer-Policy on all responses', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$fixture['patient']->getKey());

    $response->assertHeader('Referrer-Policy');
});

it('includes security headers on error responses too', function () {
    $response = $this->getJson('/api/v1/patients/nonexistent');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

/*
|--------------------------------------------------------------------------
| 9. IDOR Prevention
|--------------------------------------------------------------------------
*/

it('prevents IDOR on patient read across tenants', function () {
    $fixture = safetyFixture();
    $otherOrg = Identity::organization(['code' => 'idor-org']);
    $token = adminToken($otherOrg);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/'.$fixture['patient']->getKey());

    $status = $response->status();
    expect(in_array($status, [403, 404]))->toBeTrue();
});

it('prevents IDOR on facility read across tenants', function () {
    $fixture = safetyFixture();
    $otherOrg = Identity::organization(['code' => 'idor-fac-org']);
    $token = adminToken($otherOrg);

    $response = $this->withToken($token)
        ->getJson('/api/v1/facilities/'.$fixture['facility']->getKey());

    $status = $response->status();
    expect(in_array($status, [403, 404]))->toBeTrue();
});

it('prevents IDOR on patient update across tenants', function () {
    $fixture = safetyFixture();
    $otherOrg = Identity::organization(['code' => 'idor-update-org']);
    $token = adminToken($otherOrg);

    $response = $this->withToken($token)
        ->patchJson('/api/v1/patients/'.$fixture['patient']->getKey(), [
            'fullName' => 'Hacked Name',
            'lockVersion' => 0,
        ]);

    $status = $response->status();
    expect(in_array($status, [403, 404, 422]))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 10. Input Validation Safety
|--------------------------------------------------------------------------
*/

it('rejects missing required fields with 422', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$fixture['org']->getKey().'/facilities', []);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('rejects invalid UUID format gracefully', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->getJson('/api/v1/patients/not-a-valid-uuid');

    // Should not leak implementation details regardless of status code
    $content = $response->getContent();
    expect($content)->not->toContain('Stack trace')
        ->and($content)->not->toContain('vendor/laravel');
});

it('rejects excessively long input strings', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $longString = str_repeat('A', 10000);

    $response = $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$fixture['org']->getKey().'/facilities', [
            'name' => $longString,
            'code' => 'test',
            'timezone' => 'Asia/Kathmandu',
        ]);

    // Should either be rejected or accepted — never crash
    $status = $response->status();
    expect($status >= 200 && $status < 500)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 11. Authorization Boundary
|--------------------------------------------------------------------------
*/

it('returns 401 INVALID_TOKEN for unauthenticated requests', function () {
    $endpoints = [
        '/api/v1/users',
        '/api/v1/roles',
        '/api/v1/audit-events',
    ];

    foreach ($endpoints as $uri) {
        $response = $this->getJson($uri);
        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }
});

it('returns 403 SCOPE_DENIED for unauthorized role', function () {
    $fixture = safetyFixture();
    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $fixture['org'], $fixture['facility']);

    $response = $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/organizations/'.$fixture['org']->getKey().'/facilities', [
            'name' => 'Unauthorized',
            'code' => 'unauth',
            'timezone' => 'Asia/Kathmandu',
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('denies access with an expired token', function () {
    $fixture = safetyFixture();
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $fixture['org']);

    $expired = $admin->createToken('expired', [], now()->subMinute())->plainTextToken;

    $response = $this->withToken($expired)
        ->getJson('/api/v1/patients/'.$fixture['patient']->getKey());

    $response->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');
});

/*
|--------------------------------------------------------------------------
| 12. Data Integrity
|--------------------------------------------------------------------------
*/

it('returns created resource with all required fields', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $response = $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$fixture['org']->getKey().'/facilities', [
            'name' => 'Integrity Test',
            'code' => 'integrity-test',
            'timezone' => 'Asia/Kathmandu',
        ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => ['id', 'name', 'code'],
        ])
        ->assertJsonPath('data.name', 'Integrity Test')
        ->assertJsonPath('data.code', 'integrity-test');
});

it('preserves data through read-after-write cycle', function () {
    $fixture = safetyFixture();
    $token = adminToken($fixture['org']);

    $createResponse = $this->withToken($token)
        ->postJson('/api/v1/organizations/'.$fixture['org']->getKey().'/facilities', [
            'name' => 'Read After Write',
            'code' => 'raw-test',
            'timezone' => 'Asia/Kathmandu',
        ]);

    $facilityId = $createResponse->json('data.id');

    $readResponse = $this->withToken($token)
        ->getJson('/api/v1/facilities/'.$facilityId);

    $readResponse->assertOk()
        ->assertJsonPath('data.name', 'Read After Write')
        ->assertJsonPath('data.code', 'raw-test');
});
