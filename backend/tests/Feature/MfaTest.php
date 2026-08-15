<?php

use App\Models\AuditEvent;
use App\Models\MfaChallenge;
use App\Models\User;
use App\Services\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Tests\Support\Identity;

/**
 * PROGRAM PHASE 2 (MFA) — adversarial suite for the TOTP lifecycle
 * (SECURITY.md §3): enrollment gated by password, one-shot login challenges,
 * TOTP and single-use recovery codes, replay resistance, per-user
 * throttling, gated disable, and audit coverage. Every path is exercised
 * through the real HTTP surface.
 */
beforeEach(function (): void {
    seedIdentity();

    $this->org = Identity::organization(['code' => 'mfa-org']);
    $this->facility = Identity::facility($this->org);
    $this->user = Identity::user(['email' => 'mfa@isolation.test']);
    Identity::assign($this->user, 'org_admin', $this->org);
    $this->token = Identity::tokenFor($this->user);
});

/**
 * Enroll + activate the user through the real endpoints and return the
 * recovery codes and the TOTP secret.
 *
 * @return array{secret: string, codes: list<string>}
 */
function enrollAndActivate(object $test): array
{
    $enroll = $test->withToken($test->token)
        ->postJson('/api/v1/auth/mfa/enroll', ['password' => Identity::PASSWORD])
        ->assertOk()
        ->json('data');

    $codes = $test->withToken($test->token)
        ->postJson('/api/v1/auth/mfa/activate', ['code' => Totp::code($enroll['secretBase32'])])
        ->assertOk()
        ->json('data.recoveryCodes');

    expect($codes)->toHaveCount(10);

    return ['secret' => $enroll['secretBase32'], 'codes' => $codes];
}

function login(object $test, string $email = 'mfa@isolation.test'): TestResponse
{
    return $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => Identity::PASSWORD]);
}

it('reports MFA status and gates enrollment on the current password', function () {
    // Unauthenticated → 401. (Must run first: Sanctum caches the resolved
    // user on the per-test app, so a later token-less request in the same
    // test would otherwise reuse the previous authenticated identity.)
    $this->postJson('/api/v1/auth/mfa/enroll', ['password' => Identity::PASSWORD])->assertStatus(401);

    $this->withToken($this->token)->getJson('/api/v1/auth/mfa/status')->assertOk()->assertJsonPath('data.enabled', false);

    // Wrong password → 401, no secret issued.
    $this->withToken($this->token)
        ->postJson('/api/v1/auth/mfa/enroll', ['password' => 'wrong-password'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    expect($this->user->refresh()->mfa_secret_encrypted)->toBeNull();
});

it('activates only with a real TOTP code and stores the secret encrypted', function () {
    $enroll = $this->withToken($this->token)
        ->postJson('/api/v1/auth/mfa/enroll', ['password' => Identity::PASSWORD])
        ->assertOk()
        ->json('data');

    expect($enroll['secretBase32'])->toMatch('/^[A-Z2-7]+$/')
        ->and($enroll['otpauthUrl'])->toContain('otpauth://totp/');

    // Wrong code → 422, still disabled.
    $this->withToken($this->token)
        ->postJson('/api/v1/auth/mfa/activate', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_CODE');

    expect($this->user->refresh()->mfaEnabled())->toBeFalse();

    // Correct code → enabled with 10 recovery codes.
    $codes = $this->withToken($this->token)
        ->postJson('/api/v1/auth/mfa/activate', ['code' => Totp::code($enroll['secretBase32'])])
        ->assertOk()
        ->json('data.recoveryCodes');
    expect($codes)->toHaveCount(10);

    // The stored secret is encrypted at rest and never serialized.
    $stored = $this->user->refresh();
    expect($stored->mfaEnabled())->toBeTrue()
        ->and(Crypt::decryptString($stored->mfa_secret_encrypted))->toBe($enroll['secretBase32'])
        ->and($stored->mfa_secret_encrypted)->not->toContain($enroll['secretBase32'])
        ->and($stored->toArray())->not->toHaveKeys(['mfa_secret_encrypted', 'mfa_recovery_codes_encrypted']);

    // Double activation → 409.
    $this->withToken($this->token)
        ->postJson('/api/v1/auth/mfa/activate', ['code' => Totp::code($enroll['secretBase32'])])
        ->assertStatus(409);
});

it('requires a one-shot challenge at login and never issues tokens without it', function () {
    enrollAndActivate($this);

    $login = login($this)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'MFA_REQUIRED');
    $challengeId = $login->json('error.details.challengeId');
    expect($challengeId)->toBeString()
        ->and($login->json('data'))->toBeNull()
        ->and($login->json('accessToken'))->toBeNull();

    // No token was issued.
    expect(MfaChallenge::query()->where('user_id', $this->user->getKey())->count())->toBe(1);

    // The challenge completes with a valid TOTP code → real session.
    $session = $this->postJson('/api/v1/auth/mfa/challenge', [
        'challengeId' => $challengeId,
        'code' => Totp::code($this->user->refresh()->mfaSecret()),
    ])->assertOk();

    expect($session->json('data.accessToken'))->toBeString()
        ->and($session->json('data.refreshToken'))->toBeString()
        ->and($session->json('data.user.mfaEnabled'))->toBeTrue();

    // The challenge is one-shot: replaying it fails even with the right code.
    $this->postJson('/api/v1/auth/mfa/challenge', [
        'challengeId' => $challengeId,
        'code' => Totp::code($this->user->refresh()->mfaSecret()),
    ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_TOKEN');
});

it('accepts single-use recovery codes and rejects replay', function () {
    $fixture = enrollAndActivate($this);

    $login = login($this)->assertStatus(403);
    $challengeId = $login->json('error.details.challengeId');

    // Recovery code completes the challenge.
    $this->postJson('/api/v1/auth/mfa/challenge', ['challengeId' => $challengeId, 'code' => $fixture['codes'][0]])
        ->assertOk();

    // The used code is gone from the stored hashes (single-use).
    $remaining = $this->user->refresh()->mfaRecoveryHashes();
    expect($remaining)->toHaveCount(9)
        ->and($remaining)->not->toContain(hash('sha256', $fixture['codes'][0]));

    // Replaying the same recovery code on a fresh challenge fails.
    $second = login($this)->assertStatus(403)->json('error.details.challengeId');
    $this->postJson('/api/v1/auth/mfa/challenge', ['challengeId' => $second, 'code' => $fixture['codes'][0]])
        ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CODE');
});

it('rejects wrong codes, expired challenges, and throttles repeated failures', function () {
    // Isolate the PER-USER MFA lockout from the per-IP auth throttle (a
    // separate mechanism): this test issues many challenge requests from one
    // IP, so the IP limiter must not preempt the per-user counter.
    config(['swasthya.rate_limits.auth' => 100]);

    enrollAndActivate($this);

    // Wrong code → 422 and the challenge is consumed.
    $first = login($this)->assertStatus(403)->json('error.details.challengeId');
    $this->postJson('/api/v1/auth/mfa/challenge', ['challengeId' => $first, 'code' => '000000'])
        ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CODE');

    // Expired challenge → 401 (one-shot, short-lived).
    $expired = MfaChallenge::query()->create([
        'user_id' => $this->user->getKey(),
        'challenge_hash' => hash('sha256', 'expired-probe'),
        'expires_at' => now()->subMinute(),
    ]);
    $this->postJson('/api/v1/auth/mfa/challenge', ['challengeId' => 'expired-probe', 'code' => Totp::code($this->user->refresh()->mfaSecret())])
        ->assertStatus(401)->assertJsonPath('error.code', 'INVALID_TOKEN');

    // Five consecutive failures total (1 above + 4 here) → per-user lockout
    // (429) on the next attempt.
    for ($i = 0; $i < 4; $i++) {
        $challengeId = login($this)->assertStatus(403)->json('error.details.challengeId');
        $this->postJson('/api/v1/auth/mfa/challenge', ['challengeId' => $challengeId, 'code' => '111111'])
            ->assertStatus(422);
    }

    $locked = login($this)->assertStatus(403)->json('error.details.challengeId');
    $this->postJson('/api/v1/auth/mfa/challenge', ['challengeId' => $locked, 'code' => Totp::code($this->user->refresh()->mfaSecret())])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED');
});

it('gates MFA removal on password AND a valid TOTP code, and regenerates recovery codes', function () {
    $fixture = enrollAndActivate($this);

    // Wrong password → 401.
    $this->withToken($this->token)
        ->postJson('/api/v1/auth/mfa/disable', ['password' => 'wrong-password', 'code' => Totp::code($this->user->refresh()->mfaSecret())])
        ->assertStatus(401);

    // Right password, wrong code → 422.
    $this->withToken($this->token)
        ->postJson('/api/v1/auth/mfa/disable', ['password' => Identity::PASSWORD, 'code' => '000000'])
        ->assertStatus(422);

    // Recovery code alone cannot remove MFA (no bypass).
    $this->withToken($this->token)
        ->postJson('/api/v1/auth/mfa/disable', ['password' => Identity::PASSWORD, 'code' => $fixture['codes'][0]])
        ->assertStatus(422);

    // Correct password + TOTP → disabled; login works again without MFA.
    $this->withToken($this->token)
        ->postJson('/api/v1/auth/mfa/disable', ['password' => Identity::PASSWORD, 'code' => Totp::code($this->user->refresh()->mfaSecret())])
        ->assertStatus(204);
    expect($this->user->refresh()->mfaEnabled())->toBeFalse();

    login($this)->assertOk()->assertJsonPath('data.user.mfaEnabled', false);

    // Re-enroll and rotate recovery codes.
    enrollAndActivate($this);
    $regenerated = $this->withToken($this->token)
        ->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => Totp::code($this->user->refresh()->mfaSecret())])
        ->assertOk()
        ->json('data.recoveryCodes');
    expect($regenerated)->toHaveCount(10);

    // The old codes are dead; the new ones work.
    $challengeId = login($this)->assertStatus(403)->json('error.details.challengeId');
    $this->postJson('/api/v1/auth/mfa/challenge', ['challengeId' => $challengeId, 'code' => $fixture['codes'][1]])
        ->assertStatus(422);
    $challengeId2 = login($this)->assertStatus(403)->json('error.details.challengeId');
    $this->postJson('/api/v1/auth/mfa/challenge', ['challengeId' => $challengeId2, 'code' => $regenerated[0]])
        ->assertOk();
});

it('audits every MFA event', function () {
    enrollAndActivate($this);
    $challengeId = login($this)->assertStatus(403)->json('error.details.challengeId');
    $this->postJson('/api/v1/auth/mfa/challenge', ['challengeId' => $challengeId, 'code' => Totp::code($this->user->refresh()->mfaSecret())])
        ->assertStatus(200);

    // All actions in this test's transaction (RefreshDatabase keeps the DB
    // to this test's rows). The pre-tenant challenge event is included.
    $actions = AuditEvent::query()->pluck('action')->all();

    expect($actions)->toContain('mfa.enroll', 'mfa.activate', 'auth.mfa_challenge', 'auth.login');
});
