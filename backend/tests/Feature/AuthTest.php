<?php

use App\Models\AuditEvent;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Authentication (SECURITY.md §1–5, MASTER_RULES.md §7, TESTING_STRATEGY.md
 * §4.1): login, refresh rotation with reuse detection, logout revocation,
 * and account-state handling. Every auth event is audited.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('logs in and returns tokens, the user, assignments, and an audit header', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $user = Identity::user(['email' => 'admin@one.test']);
    Identity::assign($user, 'org_admin', $org);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@one.test',
        'password' => Identity::PASSWORD,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.email', 'admin@one.test')
        ->assertJsonPath('data.user.status', 'active')
        ->assertJsonStructure([
            'data' => ['accessToken', 'refreshToken', 'tokenType', 'expiresIn', 'refreshExpiresIn', 'user', 'assignments'],
            'meta' => ['context'],
        ])
        ->assertHeader('X-Audit-Event-Id');

    expect($response->json('data.assignments'))->toHaveCount(1)
        ->and($response->json('data.assignments.0.organizationId'))->toBe($org->getKey())
        ->and($response->json('data.assignments.0.roles'))->toBe(['org_admin'])
        ->and($response->headers->getCookies())->toHaveCount(1);

    expect(AuditEvent::query()->where('action', 'auth.login')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'auth.login')->first()?->tenant_id)->toBe($org->getKey());
});

it('rejects a wrong password without revealing account existence', function () {
    Identity::user(['email' => 'admin@one.test']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@one.test',
        'password' => 'definitely-wrong-password',
    ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@nowhere.test',
        'password' => 'definitely-wrong-password',
    ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    expect(AuditEvent::query()->where('action', 'auth.login_failed')->count())->toBe(2);
});

it('blocks login for accounts that are not active', function () {
    Identity::user(['email' => 'locked@one.test', 'status' => User::STATUS_LOCKED]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'locked@one.test',
        'password' => Identity::PASSWORD,
    ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
});

it('locks out an account after repeated failures', function () {
    // Raise the per-IP auth throttle so THIS test exercises the per-ACCOUNT
    // lockout (SECURITY.md §18), not the network-level throttle — both are
    // separate control layers and are tested separately.
    config()->set('swasthya.rate_limits.auth', 1000);

    Identity::user(['email' => 'target@one.test']);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'target@one.test',
            'password' => 'wrong-password-'.$attempt,
        ])->assertStatus(401);
    }

    // The sixth attempt is refused by the account lockout, before
    // verification, with a Retry-After.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'target@one.test',
        'password' => Identity::PASSWORD,
    ])->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertHeader('Retry-After');

    expect(AuditEvent::query()->where('action', 'auth.lockout')->count())->toBe(1);
});

it('returns the current user via auth/me with the derived context echoed', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $user = Identity::user();
    Identity::assign($user, 'hospital_admin', $org, $facility);
    $token = Identity::tokenFor($user);

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->getKey())
        ->assertJsonPath('data.assignments.0.roles', ['hospital_admin'])
        ->assertJsonPath('meta.context.tenantId', $org->getKey())
        ->assertJsonPath('meta.context.facilityId', $facility->getKey());
});

it('rejects requests without a token and with a bogus token', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'INVALID_TOKEN');

    $this->withToken('not-a-real-token')->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');
});

it('logout revokes the access token immediately and everywhere', function () {
    $org = Identity::organization();
    $user = Identity::user(['email' => 'bye@one.test']);
    Identity::assign($user, 'org_admin', $org);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'bye@one.test',
        'password' => Identity::PASSWORD,
    ]);
    $token = $login->json('data.accessToken');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(204);

    // All refresh tokens for the user are revoked — none remain usable, and
    // the revoked rows persist as session history (revocation, not deletion).
    expect(RefreshToken::query()
        ->where('user_id', $user->getKey())
        ->whereNull('revoked_at')
        ->count())->toBe(0)
        ->and(RefreshToken::query()->where('user_id', $user->getKey())->count())->toBe(1);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);

    expect(AuditEvent::query()->where('action', 'auth.logout')->count())->toBe(1);
});

it('rotates refresh tokens and detects reuse by revoking the whole family', function () {
    $org = Identity::organization();
    $user = Identity::user(['email' => 'rotate@one.test']);
    Identity::assign($user, 'org_admin', $org);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'rotate@one.test',
        'password' => Identity::PASSWORD,
    ]);
    $firstRefresh = $login->json('data.refreshToken');

    // Rotate: the first token must be single-use.
    $refresh = $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $firstRefresh]);
    // Contract parity with login: session restore (SPA page reload) derives
    // facility/role context from the same assignments payload.
    $refresh->assertOk()->assertJsonStructure([
        'data' => ['accessToken', 'refreshToken', 'expiresIn', 'refreshExpiresIn', 'user', 'assignments'],
    ]);
    expect($refresh->json('data.assignments'))->toHaveCount(1)
        ->and($refresh->json('data.assignments.0.organizationId'))->toBe($org->getKey());
    $secondRefresh = $refresh->json('data.refreshToken');

    // Replaying the rotated token is theft: family revoked, audited.
    $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $firstRefresh])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'TOKEN_REVOKED');

    expect(AuditEvent::query()->where('action', 'auth.refresh_reuse')->count())->toBe(1);

    // The successor is dead too — the whole family was revoked.
    $this->postJson('/api/v1/auth/refresh', ['refreshToken' => $secondRefresh])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'TOKEN_REVOKED');
});

it('refuses an unknown or expired refresh token with distinct codes', function () {
    $this->postJson('/api/v1/auth/refresh', ['refreshToken' => 'swr_not-a-real-token'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');

    $org = Identity::organization();
    $user = Identity::user();
    Identity::assign($user, 'org_admin', $org);

    RefreshToken::query()->create([
        'user_id' => $user->getKey(),
        'family_id' => Str::uuid(),
        'token_hash' => hash('sha256', 'swr_expired-token'),
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson('/api/v1/auth/refresh', ['refreshToken' => 'swr_expired-token'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'TOKEN_EXPIRED');
});
