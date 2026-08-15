<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshRequest;
use App\Models\User;
use App\Services\MfaService;
use App\Services\RefreshTokenService;
use App\Support\AuditLogger;
use App\Support\DatabaseTenantContext;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Authentication (API_CONTRACTS.md §21.1, SECURITY.md §1–5, MASTER_RULES.md §7).
 *
 *  - Access tokens: short-lived Sanctum bearer tokens (hashed at rest),
 *    revoked server-side on logout.
 *  - Refresh tokens: rotating, reuse-detected, family-revoked; delivered in
 *    the body for API clients AND as an httpOnly SameSite=Strict cookie for
 *    the SPA (SECURITY.md §4).
 *  - Every auth event is audited: success, failure, lockout, refresh,
 *    logout (MASTER_RULES.md §7.5).
 *  - Brute-force protection: per-account failure counter with lockout
 *    (SECURITY.md §18) layered on the per-IP auth throttle.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly AuditLogger $audit,
        private readonly MfaService $mfa,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->validated('email')));
        $password = (string) $request->validated('password');

        $this->assertNotLockedOut($email, $request);

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user === null || ! Hash::check($password, (string) $user->password_hash)) {
            $this->recordFailedAttempt($email);

            $this->audit->record('auth.login_failed', 'user', $user?->getKey(), [], $request, actorEmail: $email);

            throw new ApiException(
                ErrorCodes::INVALID_CREDENTIALS,
                'The provided credentials are incorrect.',
                401,
            );
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            $this->audit->record('auth.login_denied', 'user', $user->getKey(), ['reason' => 'account_not_active'], $request, $user);

            throw new ApiException(ErrorCodes::FORBIDDEN, 'This account is not active.', 403);
        }

        // Phase 2 (MFA, SECURITY.md §3): an MFA-enabled account receives a
        // one-shot challenge and NO tokens — password alone is never enough.
        // The challengeId travels in error.details so the client can prompt
        // for a code and complete it at POST auth/mfa/challenge.
        if ($user->mfaEnabled()) {
            $challengeId = $this->mfa->issueChallenge($user, $request->ip(), $request->userAgent());
            $this->audit->record('auth.mfa_challenge', 'user', $user->getKey(), [], $request, $user);

            throw new ApiException(
                ErrorCodes::MFA_REQUIRED,
                'MFA verification required.',
                403,
                ['challengeId' => $challengeId],
            );
        }

        Cache::forget('auth.failures:'.$email);

        $user->forceFill(['last_login_at' => now()])->save();

        $accessToken = $user->createToken(
            'access',
            [],
            now()->addMinutes(config('swasthya.auth.access_token_ttl_minutes')),
        );
        [$refreshToken, $refresh] = $this->refreshTokens->issue(
            $user,
            $request->ip(),
            $request->userAgent(),
            config('swasthya.auth.refresh_token_ttl_days'),
        );

        // resource_id stays null: the Sanctum token row id is a bigint, not
        // a domain UUID — it travels in the payload instead (never the token
        // value itself; tokens are hashed at rest). The tenant is resolved
        // from the principal's most recent active assignment because login
        // runs before tenant-context resolution (TENANCY.md §11).
        //
        // Login is a public route, so no tenant-context middleware has run:
        // the assignment lookups below run inside a user-scoped transaction
        // that sets app.user_id — the RLS policy on role_assignments lets a
        // principal read ITS OWN rows, which is what makes login, the
        // assignments payload, and the audit tenant resolution possible
        // under RLS without any tenant context (TENANCY.md V2 §5, §7).
        $loginTenantId = $this->withUserDbContext($user, function () use ($user): ?string {
            return $user->roleAssignments()->active()->orderByDesc('granted_at')->value('tenant_id');
        });
        $event = $this->audit->record(
            'auth.login',
            'session',
            null,
            ['tokenId' => (string) $accessToken->accessToken->getKey()],
            $request,
            $user,
            tenantId: is_string($loginTenantId) ? $loginTenantId : null,
        );

        $response = Envelope::success(
            data: [
                'accessToken' => $accessToken->plainTextToken,
                'tokenType' => 'Bearer',
                'expiresIn' => config('swasthya.auth.access_token_ttl_minutes') * 60,
                'refreshToken' => $refreshToken,
                'refreshExpiresIn' => config('swasthya.auth.refresh_token_ttl_days') * 86400,
                'user' => $this->userPayload($user),
                'assignments' => $this->withUserDbContext($user, fn (): array => $this->assignmentsPayload($user)),
            ],
            request: $request,
            headers: ['X-Audit-Event-Id' => (string) $event->getKey()],
        );

        return $this->withRefreshCookie($response, $refreshToken);
    }

    public function refresh(RefreshRequest $request): JsonResponse
    {
        $token = (string) ($request->validated('refreshToken') ?? $request->cookie('swasthya_refresh'));

        if ($token === '') {
            throw new ApiException(ErrorCodes::INVALID_TOKEN, 'A refresh token is required.', 401);
        }

        try {
            [$successorToken, $successor, $user] = $this->refreshTokens->rotate(
                $token,
                $request->ip(),
                $request->userAgent(),
                config('swasthya.auth.refresh_token_ttl_days'),
            );
        } catch (ApiException $exception) {
            // Reuse of a rotated token is a theft signal: distinct, audited
            // events so alerting can treat it as one (SECURITY.md §4).
            $this->audit->record(
                $exception->errorCode === ErrorCodes::TOKEN_REVOKED ? 'auth.refresh_reuse' : 'auth.refresh_denied',
                'refresh_token',
                null,
                ['code' => $exception->errorCode],
                $request,
            );

            throw $exception;
        }

        $accessToken = $user->createToken(
            'access',
            [],
            now()->addMinutes(config('swasthya.auth.access_token_ttl_minutes')),
        );
        $event = $this->audit->record('auth.refresh', 'session', null, ['tokenId' => (string) $accessToken->accessToken->getKey()], $request, $user);

        $response = Envelope::success(
            data: [
                'accessToken' => $accessToken->plainTextToken,
                'tokenType' => 'Bearer',
                'expiresIn' => config('swasthya.auth.access_token_ttl_minutes') * 60,
                'refreshToken' => $successorToken,
                'refreshExpiresIn' => config('swasthya.auth.refresh_token_ttl_days') * 86400,
                'user' => $this->userPayload($user),
                // Contract parity with login: the SPA restores a session from
                // refresh and derives its facility/role context from the same
                // assignments payload (FRONTEND_FOUNDATION_REPORT §5). Like
                // login, refresh is public, so the assignments query runs in a
                // user-scoped transaction that satisfies the RLS policy on
                // role_assignments without any tenant context.
                'assignments' => $this->withUserDbContext($user, fn (): array => $this->assignmentsPayload($user)),
            ],
            request: $request,
            headers: ['X-Audit-Event-Id' => (string) $event->getKey()],
        );

        return $this->withRefreshCookie($response, $successorToken);
    }

    /**
     * Run a callback with app.user_id set in a short-lived transaction, so
     * the RLS policy on role_assignments reveals only the principal's own
     * rows (used by the public login route, where the tenant-context
     * middleware has not run). The LOCAL GUC dies with the transaction.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withUserDbContext(User $user, callable $callback): mixed
    {
        return DB::transaction(function () use ($user, $callback): mixed {
            DatabaseTenantContext::setUser($user->getKey());

            return $callback();
        });
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $this->audit->record('auth.logout', 'session', null, [], $request, $user);

            // Immediate, everywhere: revoke the current access token and all
            // of the user's refresh tokens (SECURITY.md §4, MASTER_RULES.md §7.6).
            $request->user()?->currentAccessToken()?->delete();
            $this->refreshTokens->revokeAllForUser($user);
        }

        return response()->json(null, 204)
            ->withCookie($this->expiredRefreshCookie());
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return Envelope::success(
            data: [
                'user' => $this->userPayload($user),
                'assignments' => $this->assignmentsPayload($user),
            ],
            request: $request,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'email' => $user->email,
            'status' => $user->status,
            'mfaEnabled' => $user->mfaEnabled(),
        ];
    }

    /**
     * The principal's scoped assignments, grouped per organization/facility
     * (API_CONTRACTS.md §21.4) — the same shape the context is derived from.
     *
     * @return list<array<string, mixed>>
     */
    private function assignmentsPayload(User $user): array
    {
        $groups = [];

        foreach ($user->roleAssignments()->active()->with(['role', 'organization', 'facility'])->get() as $assignment) {
            $key = ($assignment->tenant_id ?? 'platform').'|'.($assignment->facility_id ?? '');

            $groups[$key] ??= [
                'organizationId' => $assignment->organization?->getKey(),
                'organizationCode' => $assignment->organization?->code,
                'facilityId' => $assignment->facility_id,
                'facilityName' => $assignment->facility?->name,
                'roles' => [],
            ];

            $groups[$key]['roles'][] = $assignment->role?->code;
        }

        return array_values($groups);
    }

    private function withRefreshCookie(JsonResponse $response, string $token): JsonResponse
    {
        $response->headers->setCookie(new Cookie(
            name: 'swasthya_refresh',
            value: $token,
            expire: now()->addDays(config('swasthya.auth.refresh_token_ttl_days')),
            path: '/',
            domain: null,
            secure: config('app.env') === 'production',
            httpOnly: true,
            raw: false,
            sameSite: 'strict',
        ));

        return $response;
    }

    private function expiredRefreshCookie(): Cookie
    {
        return new Cookie(
            name: 'swasthya_refresh',
            value: null,
            expire: now()->subYear(),
            path: '/',
            domain: null,
            secure: config('app.env') === 'production',
            httpOnly: true,
            raw: false,
            sameSite: 'strict',
        );
    }

    private function assertNotLockedOut(string $email, Request $request): void
    {
        $failures = (int) Cache::get('auth.failures:'.$email, 0);

        if ($failures >= config('swasthya.auth.login_failure_threshold')) {
            $this->audit->record('auth.lockout', 'user', null, ['email' => $email], $request);

            throw new ApiException(
                ErrorCodes::RATE_LIMITED,
                'Too many failed login attempts. Try again later.',
                429,
                [],
                ['Retry-After' => (string) (config('swasthya.auth.login_lockout_minutes') * 60)],
            );
        }
    }

    private function recordFailedAttempt(string $email): void
    {
        $key = 'auth.failures:'.$email;
        $failures = (int) Cache::get($key, 0) + 1;

        Cache::put($key, $failures, now()->addMinutes(config('swasthya.auth.login_lockout_minutes')));
    }
}
