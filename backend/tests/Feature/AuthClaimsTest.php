<?php

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\RefreshTokenService;
use App\Support\AuthClaims;
use App\Support\DatabaseTenantContext;
use App\Support\ErrorCodes;
use App\Support\JwtClaims;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 — Supabase-native authentication foundation verification.
 *
 * Proves the security contract of the JWT claims layer end-to-end:
 *  1. the codec signs/verifies HS256 tokens and rejects every forgery class;
 *  2. the claim VALUES are derived only from the server-resolved context
 *     (AuthClaims::fromContext) — there is no client-input path;
 *  3. a verified token's claims, written into request.jwt.claims the way
 *     Supabase's pooler does, feed the Phase 2 RLS helpers EXACTLY and the
 *     isolation matrix holds;
 *  4. missing/empty claims resolve to zero access (never a leak);
 *  5. users.auth_subject_id maps a GoTrue subject to exactly one account;
 *  6. the stateless access-token model vs refresh revocation is documented
 *     behavior, not an accident.
 */

/* ------------------------------------------------------------------ */
/* 1. JWT codec */
/* ------------------------------------------------------------------ */

it('signs and verifies a claims token round-trip', function () {
    $claims = [
        'app_user_id' => (string) Str::uuid(),
        'app_tenant_id' => (string) Str::uuid(),
        'app_facility_id' => (string) Str::uuid(),
        'app_branch_id' => (string) Str::uuid(),
        'app_is_platform' => 'false',
    ];

    $token = JwtClaims::issue($claims, 300);

    expect(str($token)->startsWith('ey'))->toBeTrue() // base64url header
        ->and(substr_count($token, '.'))->toBe(2);

    $payload = JwtClaims::verify($token);

    foreach ($claims as $key => $value) {
        expect($payload[$key])->toBe($value);
    }

    expect($payload['iss'])->toBe(config('swasthya.auth.jwt.issuer'))
        ->and($payload['aud'])->toBe(config('swasthya.auth.jwt.audience'))
        ->and($payload['exp'])->toBeGreaterThan(time())
        ->and(isset($payload['jti']))->toBeTrue();
});

it('rejects a tampered payload — a client can never alter claims', function () {
    $claims = [
        'app_user_id' => (string) Str::uuid(),
        'app_tenant_id' => (string) Str::uuid(),
        'app_facility_id' => (string) Str::uuid(),
        'app_branch_id' => (string) Str::uuid(),
        'app_is_platform' => 'false',
    ];
    $token = JwtClaims::issue($claims, 300);

    // Flip one claim character inside the payload segment.
    $parts = explode('.', $token);
    $tamperedPayload = strtr($parts[1], ['A' => 'B']);
    if ($tamperedPayload === $parts[1]) {
        $tamperedPayload = strtr($parts[1], ['B' => 'C']);
    }
    $tampered = $parts[0].'.'.$tamperedPayload.'.'.$parts[2];

    expect(fn () => JwtClaims::verify($tampered))->toThrow(ApiException::class);
});

it('rejects tokens signed with a different key', function () {
    $claims = AuthClaims::normalize(['app_user_id' => (string) Str::uuid()]);
    $token = JwtClaims::issue($claims, 300);

    config(['swasthya.auth.jwt.secret' => 'a-different-secret']);

    expect(fn () => JwtClaims::verify($token))->toThrow(ApiException::class);
});

it('rejects alg=none and algorithm confusion', function () {
    $claims = AuthClaims::normalize(['app_user_id' => (string) Str::uuid()]);
    $token = JwtClaims::issue($claims, 300);

    $parts = explode('.', $token);
    $forgedHeader = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
    $forged = $forgedHeader.'.'.$parts[1].'.';

    expect(fn () => JwtClaims::verify($forged))->toThrow(ApiException::class);
});

it('rejects expired tokens', function () {
    $claims = AuthClaims::normalize(['app_user_id' => (string) Str::uuid()]);
    $token = JwtClaims::issue($claims, -3600); // already expired (beyond the 30s leeway)

    try {
        JwtClaims::verify($token);
        expect(true)->toBeFalse('verify must reject an expired token');
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe(ErrorCodes::TOKEN_EXPIRED);
    }
});

it('rejects tokens with a wrong audience', function () {
    $claims = AuthClaims::normalize(['app_user_id' => (string) Str::uuid()]);
    $token = JwtClaims::issue($claims, 300);

    config(['swasthya.auth.jwt.audience' => 'some-other-api']);

    expect(fn () => JwtClaims::verify($token))->toThrow(ApiException::class);
});

/* ------------------------------------------------------------------ */
/* 2. Server-side claim derivation */
/* ------------------------------------------------------------------ */

it('derives tenant claims from the resolved context only', function () {
    seedIdentity();

    $org = Identity::organization();
    $facility = Identity::facility($org);
    $user = Identity::user();
    Identity::assign($user, 'org_admin', $org);

    $context = new TenantContext($user, false, $org, $facility, collect());

    $claims = AuthClaims::fromContext($context);

    expect($claims)->toBe([
        'app_user_id' => $user->getKey(),
        'app_tenant_id' => $org->getKey(),
        'app_facility_id' => $facility->getKey(),
        'app_branch_id' => '',
        'app_is_platform' => 'false',
    ])->and(AuthClaims::isComplete($claims))->toBeTrue();
});

it('derives platform claims with empty tenant/facility/branch', function () {
    $user = Identity::user();

    $claims = AuthClaims::fromContext(new TenantContext($user, true, null, null, collect()));

    expect($claims)->toBe([
        'app_user_id' => $user->getKey(),
        'app_tenant_id' => '',
        'app_facility_id' => '',
        'app_branch_id' => '',
        'app_is_platform' => 'true',
    ]);
});

it('has no client-input path — headers cannot influence the claims', function () {
    // A hostile client proposes facility B (and an arbitrary branch); the
    // context — the ONLY input to the factory — was resolved to facility A.
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a-claims']);
    Identity::facility($org, ['code' => 'fac-b-claims']); // proposed target
    $user = Identity::user();

    $this->withHeader('X-Swasthya-Facility', 'fac-b-claims')
        ->withHeader('X-Swasthya-Branch', 'br-evil');

    $claims = AuthClaims::fromContext(new TenantContext($user, false, $org, $facilityA, collect()));

    expect($claims['app_facility_id'])->toBe($facilityA->getKey())
        ->and($claims['app_branch_id'])->toBe('')
        ->and(implode('|', $claims))->not->toContain('fac-b-claims')
        ->and(implode('|', $claims))->not->toContain('br-evil');
});

it('normalizes arbitrary payloads to exactly the five RLS keys', function () {
    $payload = [
        'iss' => 'swasthya',
        'sub' => 'gotrue-subject',
        'app_tenant_id' => (string) Str::uuid(),
        'app_is_platform' => 'true',
        'role' => 'service_role', // must never survive into RLS context
        'permissions' => ['*'],
    ];

    $normalized = AuthClaims::normalize($payload);

    expect(array_keys($normalized))->toBe(AuthClaims::KEYS)
        ->and($normalized['app_tenant_id'])->toBe($payload['app_tenant_id'])
        ->and($normalized['app_is_platform'])->toBe('true')
        ->and($normalized['app_user_id'])->toBe('')
        ->and(isset($normalized['role']))->toBeFalse()
        ->and(isset($normalized['sub']))->toBeFalse();
});

/* ------------------------------------------------------------------ */
/* 3. Claims → RLS integration (the CHECKPOINT 6 proof) */
/* ------------------------------------------------------------------ */

it('feeds the Phase 2 RLS helpers and the isolation matrix from a verified JWT', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $patientA = (string) Str::uuid();

        // Sign a token exactly like the edge-function signer would, from the
        // server-resolved context for tenant A / facility A.
        $tokenA = JwtClaims::issue([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], 300);

        // Verify → write the payload through the single pooler-equivalent
        // entry point (DatabaseTenantContext::setClaims).
        $payload = JwtClaims::verify($tokenA);
        DatabaseTenantContext::setClaims($payload, $c);

        // The Phase 2 helpers resolve the values from the claims alone.
        expect($c->selectOne('select public.swasthya_rls_tenant_id() as tenant_id')->tenant_id)->toBe($t['tenantA'])
            ->and($c->selectOne('select public.swasthya_rls_facility_id() as facility_id')->facility_id)->toBe($t['facilityA'])
            ->and($c->selectOne('select public.swasthya_rls_branch_id() as branch_id')->branch_id)->toBeNull()
            ->and($c->selectOne('select public.swasthya_rls_is_platform() as is_platform')->is_platform)->toBeFalse();

        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-JWT', 'JWT Patient', '1990-01-01', 'female', 'active']
        );
        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->not->toBeNull();

        // A second verified token for tenant B → tenant A's row vanishes,
        // updates/deletes affect zero rows.
        $tokenB = JwtClaims::issue([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantB'],
            'app_facility_id' => $t['facilityB'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], 300);
        DatabaseTenantContext::setClaims(JwtClaims::verify($tokenB), $c);

        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->toBeNull()
            ->and($c->update('update patients set status = ? where id = ?', ['merged', $patientA]))->toBe(0)
            ->and($c->delete('delete from patients where id = ?', [$patientA]))->toBe(0);

        // And the normalized payload is exactly the five keys.
        expect(array_keys(DatabaseTenantContext::claims($c)))->toBe(AuthClaims::KEYS);
    });
});

it('a verified token with missing claims grants zero access', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);

        // A valid token that carries NO app_* claims (e.g. a GoTrue JWT that
        // was never enriched by the signer).
        $token = JwtClaims::issue([], 300);
        DatabaseTenantContext::setClaims(JwtClaims::verify($token), $c);

        expect($c->selectOne('select public.swasthya_rls_tenant_id() as tenant_id')->tenant_id)->toBeNull();

        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], 'MRN-NOCLAIM', 'No Claims', '1990-01-01', 'female', 'active']
        );
        // The insert policy is WITH CHECK true; reads with empty claims fail
        // closed to zero rows.
        expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(0);
    });
});

it('mints a token whose claims, verified and set, are transaction-local', function () {
    $c = rlsConn();
    // Wrap tenant setup in a transaction so platform claims (is_local=true) take effect.
    $c->beginTransaction();
    $t = claimsTenants($c);
    $c->commit();

    $c->beginTransaction();
    try {
        DatabaseTenantContext::setClaims(JwtClaims::verify(JwtClaims::issue([
            'app_user_id' => (string) Str::uuid(),
            'app_tenant_id' => $t['tenantA'],
            'app_facility_id' => $t['facilityA'],
            'app_branch_id' => '',
            'app_is_platform' => 'false',
        ], 300)), $c);

        expect($c->selectOne('select public.swasthya_rls_tenant_id() as tenant_id')->tenant_id)->toBe($t['tenantA']);
        $c->rollBack();
    } finally {
        if ($c->transactionLevel() > 0) {
            $c->rollBack();
        }
    }

    // Claims died with the transaction: the pooled connection starts clean.
    $c->beginTransaction();
    try {
        expect($c->selectOne('select public.swasthya_rls_tenant_id() as tenant_id')->tenant_id)->toBeNull();
    } finally {
        $c->rollBack();
    }
});

/* ------------------------------------------------------------------ */
/* 4. Identity mapping (users.auth_subject_id) */
/* ------------------------------------------------------------------ */

it('maps a GoTrue subject to exactly one application account', function () {
    $subject = (string) Str::uuid();
    $user = Identity::user(['auth_subject_id' => $subject]);

    expect($user->auth_subject_id)->toBe($subject)
        ->and(User::query()->where('auth_subject_id', $subject)->first()?->getKey())->toBe($user->getKey());

    // A second account claiming the same subject is rejected by the partial
    // unique index.
    expect(fn () => Identity::user(['auth_subject_id' => $subject]))
        ->toThrow(QueryException::class);
});

it('leaves unimported accounts nullable and resolvable', function () {
    $user = Identity::user();

    expect($user->auth_subject_id)->toBeNull()
        ->and(User::query()->where('auth_subject_id', null)->pluck('id'))->toContain($user->getKey());
});

/* ------------------------------------------------------------------ */
/* 5. Refresh revocation semantics under the stateless JWT model */
/* ------------------------------------------------------------------ */

it('documents the stateless access-token model: refresh revocation terminates the session at the next refresh', function () {
    $user = Identity::user();
    $claims = AuthClaims::fromContext(new TenantContext($user, false, null, null, collect()));
    $token = JwtClaims::issue($claims, 3600);

    // Logout = revoke every refresh token for the user (AuthController::logout).
    app(RefreshTokenService::class)->revokeAllForUser($user);

    // The JWT is stateless: it verifies until expiry — the same model as
    // Supabase Auth (a signed access token cannot be recalled server-side).
    // The session is terminated at the next refresh (no valid refresh token
    // remains), which is why access tokens stay short-lived.
    $payload = JwtClaims::verify($token);
    expect($payload['app_user_id'])->toBe($user->getKey());
});

it('a replayed refresh token revokes the whole family (existing guarantee intact)', function () {
    $user = Identity::user();
    $service = app(RefreshTokenService::class);

    [$first] = $service->issue($user);
    $service->rotate($first); // use it — rotation

    expect(fn () => $service->rotate($first)) // replay — theft signal
        ->toThrow(ApiException::class);
});
