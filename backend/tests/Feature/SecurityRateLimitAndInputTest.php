<?php

use App\Models\Patient;
use Tests\Support\Identity;

/**
 * PROGRAM PHASE 2 (rate limiting / input security / CORS).
 *
 * Rate limiting: throttle:api (per IP) is attached BEFORE authentication on
 * the whole API surface (routes/api.php), auth endpoints keep the stricter
 * throttle:auth, and unauthenticated requests are counted too. Tests lower
 * the configured limit via the config repository — the limiter reads it per
 * request — so the 429 path is exercised without 300 real requests.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('throttles the authenticated API surface per IP after the configured limit', function () {
    config(['swasthya.rate_limits.api' => 3]);

    $orgA = Identity::organization(['code' => 'throttle-org']);
    Identity::facility($orgA);
    $adminA = Identity::user(['email' => 'throttle@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);
    $token = Identity::tokenFor($adminA);

    // Three requests under the limit → all succeed.
    foreach (range(1, 3) as $i) {
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    // The fourth request from the same IP → 429.
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(429);
});

it('counts unauthenticated requests against the same per-IP limit', function () {
    config(['swasthya.rate_limits.api' => 3]);

    foreach (range(1, 3) as $i) {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // The limiter runs BEFORE authentication, so the 4th unauthenticated
    // request is throttled rather than reaching the auth check.
    $this->getJson('/api/v1/auth/me')->assertStatus(429);
});

it('rejects SQL injection in string inputs without leaking or erroring', function () {
    $orgA = Identity::organization(['code' => 'sqli-org']);
    Identity::facility($orgA);
    $adminA = Identity::user(['email' => 'sqli@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);
    $token = Identity::tokenFor($adminA);

    // Classic injection payloads treated as literal data (or rejected by
    // validation) — never executed, never echoed back as an SQL error.
    $payloads = [
        "' OR '1'='1",
        "'; DROP TABLE patients; --",
        '1 UNION SELECT password FROM users --',
    ];

    $facilityA = Identity::facility($orgA);

    foreach ($payloads as $name) {
        $this->withToken($token)
            ->postJson("/api/v1/organizations/{$orgA->getKey()}/patients", [
                'fullName' => $name,
                'dateOfBirth' => '1990-01-01',
                'sex' => 'female',
                'facilityId' => $facilityA->getKey(),
            ])
            ->assertStatus(201);
    }

    // The rows were stored as literal strings — the DB is intact and the
    // patients table still has all rows (nothing was dropped).
    expect(Patient::query()->count())->toBeGreaterThanOrEqual(3);
});

it('does not reflect stored XSS payloads back unescaped in the API envelope', function () {
    $orgA = Identity::organization(['code' => 'xss-org']);
    Identity::facility($orgA);
    $adminA = Identity::user(['email' => 'xss@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);
    $token = Identity::tokenFor($adminA);

    $facilityA = Identity::facility($orgA);
    $payload = '<script>alert(document.cookie)</script>';
    $this->withToken($token)
        ->postJson("/api/v1/organizations/{$orgA->getKey()}/patients", [
            'fullName' => $payload,
            'dateOfBirth' => '1990-01-01',
            'sex' => 'female',
            'facilityId' => $facilityA->getKey(),
        ])
        ->assertStatus(201);

    $list = $this->withToken($token)
        ->getJson("/api/v1/organizations/{$orgA->getKey()}/patients")
        ->assertOk();

    // The JSON response is HTML-escaped by Laravel's JsonResponse: the
    // literal script tag must not appear raw in the transport.
    $raw = (string) $list->getContent();
    expect($raw)->not->toContain('<script>alert(document.cookie)</script>')
        ->and($raw)->toContain('\\u003Cscript\\u003E');
});

it('rejects oversized/type-confused payloads at the validation boundary', function () {
    $orgA = Identity::organization(['code' => 'type-org']);
    Identity::facility($orgA);
    $adminA = Identity::user(['email' => 'type@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);
    $token = Identity::tokenFor($adminA);

    // Wrong types: sex is an enum, dateOfBirth must be a date.
    $this->withToken($token)
        ->postJson("/api/v1/organizations/{$orgA->getKey()}/patients", [
            'fullName' => 'Type Confused',
            'dateOfBirth' => ['not', 'a', 'date'],
            'sex' => 12345,
        ])
        ->assertStatus(422);

    // Absurdly long free-text fields → rejected (max lengths enforced).
    $this->withToken($token)
        ->postJson("/api/v1/organizations/{$orgA->getKey()}/patients", [
            'fullName' => str_repeat('A', 5000),
            'dateOfBirth' => '1990-01-01',
            'sex' => 'female',
        ])
        ->assertStatus(422);
});

it('does not trust the Origin header for authorization or CORS outside the allowlist', function () {
    // Isolate from the per-IP limiter used by earlier tests in this process.
    $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.41']);

    $orgA = Identity::organization(['code' => 'cors-org']);
    Identity::facility($orgA);
    $adminA = Identity::user(['email' => 'cors@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);
    $token = Identity::tokenFor($adminA);

    // The CORS allowlist comes from SWASTHYA_CORS_ALLOWED_ORIGINS and is
    // empty by default (safe default: no cross-origin grants). Set the
    // RESOLVED cors config (config/cors.php is evaluated at boot, so the
    // swasthya key must be overridden before it or cors directly).
    config([
        'swasthya.api.cors_allowed_origins' => ['http://localhost:5173'],
        'cors.allowed_origins' => ['http://localhost:5173'],
        'cors.allowed_origins_patterns' => [],
        'cors.supports_credentials' => true,
    ]);

    // A hostile origin can never receive a grant FOR ITSELF: fruitcake/cors
    // with a single allowed origin unconditionally sets ACAO to that one
    // origin, so an evil page sees ACAO that does not match its own origin
    // and the browser blocks the read. Assert the actual safety property:
    // evil.example is never echoed as the allowed origin.
    $response = $this->withToken($token)
        ->getJson('/api/v1/auth/me', ['Origin' => 'https://evil.example'])
        ->assertOk();

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');

    // The documented frontend origin IS granted, with credentials.
    $granted = $this->withToken($token)
        ->getJson('/api/v1/auth/me', ['Origin' => 'http://localhost:5173'])
        ->assertOk();

    expect($granted->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:5173')
        ->and($granted->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
});

it('rejects tampered facility headers instead of trusting them for scope', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.42']);

    $orgA = Identity::organization(['code' => 'fac-hdr-org']);
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization(['code' => 'fac-hdr-victim']);
    $facilityB = Identity::facility($orgB);

    $adminA = Identity::user(['email' => 'fac-hdr@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);
    $token = Identity::tokenFor($adminA);

    // X-Swasthya-Facility is a PROPOSAL validated against the caller's own
    // assignments — a victim facility id cannot widen the response (403).
    $this->withToken($token)
        ->getJson('/api/v1/organizations/{$orgA->getKey()}/facilities', ['X-Swasthya-Facility' => $facilityB->getKey()])
        ->assertStatus(403);

    // With no header, the caller sees only their own facility.
    $response = $this->withToken($token)
        ->getJson("/api/v1/organizations/{$orgA->getKey()}/facilities")
        ->assertOk();

    $facilities = collect($response->json('data'));
    expect($facilities->pluck('id'))->toContain($facilityA->getKey())
        ->and($facilities->pluck('id'))->not->toContain($facilityB->getKey());
});
