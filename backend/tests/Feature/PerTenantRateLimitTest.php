<?php

use Tests\Support\Identity;

/**
 * Per-tenant rate limiting (TENANCY.md V2 §7, SECURITY.md §17).
 *
 * The `tenant` limiter keys on the RESOLVED tenant id (never the client), so
 * one tenant's request burst cannot exhaust a shared budget and starve
 * another. Because throttle:api runs BEFORE ResolveTenantContext, the
 * per-tenant dimension must be applied AFTER tenant resolution — here wired to
 * tenant write routes (throttle:api → auth → ResolveTenantContext →
 * throttle:tenant). Tests lower the configured tenant limit via the config
 * repository (the limiter reads it per request) and drive two tenants from the
 * same IP to prove cross-tenant isolation, isolating each IP so the per-IP
 * api limiter does not mask the result.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('exhausting one tenant budget does not throttle another tenant', function (): void {
    config(['swasthya.rate_limits.tenant' => 2]);

    $orgA = Identity::organization(['code' => 'rl-org-a']);
    $facilityA = Identity::facility($orgA);
    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    $orgB = Identity::organization(['code' => 'rl-org-b']);
    Identity::facility($orgB);
    $adminB = Identity::user();
    Identity::assign($adminB, 'org_admin', $orgB);

    $ip = '192.168.77.1';

    $createA = fn () => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken(Identity::tokenFor($adminA))
        ->postJson("/api/v1/organizations/{$orgA->getKey()}/facilities", [
            'name' => 'A Facility',
            'code' => 'rl-fac-a-'.uniqid(),
            'timezone' => 'Asia/Kathmandu',
        ]);

    $createB = fn () => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken(Identity::tokenFor($adminB))
        ->postJson("/api/v1/organizations/{$orgB->getKey()}/facilities", [
            'name' => 'B Facility',
            'code' => 'rl-fac-b-'.uniqid(),
            'timezone' => 'Asia/Kathmandu',
        ]);

    // Tenant A consumes its full budget (2) then is throttled on the 3rd.
    $createA()->assertCreated();
    $createA()->assertCreated();
    $createA()->assertStatus(429);

    // Tenant B, same IP, budget intact because the key is tenant-scoped.
    $createB()->assertCreated();
    $createB()->assertCreated();
    $createB()->assertStatus(429);
});

it('tenant B can continue after tenant A is throttled', function (): void {
    config(['swasthya.rate_limits.tenant' => 1]);

    $orgA = Identity::organization(['code' => 'rl-org-c']);
    $facilityA = Identity::facility($orgA);
    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    $orgB = Identity::organization(['code' => 'rl-org-d']);
    Identity::facility($orgB);
    $adminB = Identity::user();
    Identity::assign($adminB, 'org_admin', $orgB);

    $ip = '192.168.77.2';

    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken(Identity::tokenFor($adminA))
        ->postJson("/api/v1/organizations/{$orgA->getKey()}/facilities", [
            'name' => 'A Facility',
            'code' => 'rl-fac-c-'.uniqid(),
            'timezone' => 'Asia/Kathmandu',
        ])->assertCreated();

    // A is now throttled.
    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken(Identity::tokenFor($adminA))
        ->postJson("/api/v1/organizations/{$orgA->getKey()}/facilities", [
            'name' => 'A Facility 2',
            'code' => 'rl-fac-c2-'.uniqid(),
            'timezone' => 'Asia/Kathmandu',
        ])->assertStatus(429);

    // B, same IP, unaffected.
    $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken(Identity::tokenFor($adminB))
        ->postJson("/api/v1/organizations/{$orgB->getKey()}/facilities", [
            'name' => 'B Facility',
            'code' => 'rl-fac-d-'.uniqid(),
            'timezone' => 'Asia/Kathmandu',
        ])->assertCreated();
});
