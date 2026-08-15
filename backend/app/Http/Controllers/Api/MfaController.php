<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MfaChallengeRequest;
use App\Http\Requests\Auth\MfaCodeRequest;
use App\Http\Requests\Auth\MfaDisableRequest;
use App\Http\Requests\Auth\MfaEnrollRequest;
use App\Models\User;
use App\Services\MfaService;
use App\Services\RefreshTokenService;
use App\Support\AuditLogger;
use App\Support\DatabaseTenantContext;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * PROGRAM PHASE 2 (MFA) — TOTP multi-factor lifecycle (SECURITY.md §3).
 *
 *  - status / enroll / activate / disable / recovery-codes run on the
 *    authenticated session (access token). enroll and disable require the
 *    current password (step-up); disable additionally requires a valid TOTP
 *    code, so MFA cannot be removed with a stolen session or a recovery code.
 *  - challenge is the PUBLIC completion of a login challenge: the only path
 *    to tokens for an MFA-enabled account. It is throttled per user (5/15
 *    min) and per IP (route throttle), and the challenge is one-shot.
 *  - No MFA bypass: with MFA enabled, POST auth/login returns MFA_REQUIRED
 *    and no tokens; refresh rotates tokens the challenged session already
 *    holds (access tokens stay short-lived; sensitive-scope step-up on
 *    refresh is a documented future item per SECURITY.md §3).
 */
final class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly RefreshTokenService $refreshTokens,
        private readonly AuditLogger $audit,
    ) {}

    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return Envelope::success(data: ['enabled' => $user->mfaEnabled()], request: $request);
    }

    public function enroll(MfaEnrollRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->validated('password'), (string) $user->password_hash)) {
            throw new ApiException(ErrorCodes::INVALID_CREDENTIALS, 'The password is incorrect.', 401);
        }

        if ($user->mfaEnabled()) {
            throw new ApiException(ErrorCodes::RESOURCE_EXISTS, 'MFA is already enabled. Disable it before re-enrolling.', 409);
        }

        $enrollment = $this->mfa->enroll($user, (string) $user->email);

        $this->audit->record('mfa.enroll', 'user', $user->getKey(), [], $request, $user);

        return Envelope::success(data: $enrollment, request: $request);
    }

    public function activate(MfaCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->mfaEnabled()) {
            throw new ApiException(ErrorCodes::RESOURCE_EXISTS, 'MFA is already enabled.', 409);
        }

        $codes = $this->mfa->activate($user, (string) $request->validated('code'));

        $this->audit->record('mfa.activate', 'user', $user->getKey(), [], $request, $user);

        // Plaintext recovery codes travel to the client exactly once.
        return Envelope::success(data: ['recoveryCodes' => $codes], request: $request);
    }

    /**
     * Public: completes a login challenge with a TOTP or recovery code and
     * issues the session (access + refresh tokens), mirroring login.
     */
    public function challenge(MfaChallengeRequest $request): JsonResponse
    {
        $user = $this->mfa->completeChallenge(
            (string) $request->validated('challengeId'),
            (string) $request->validated('code'),
            $request->ip(),
            $request->userAgent(),
        );

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

        $loginTenantId = $this->withUserDbContext($user, function () use ($user): ?string {
            return $user->roleAssignments()->active()->orderByDesc('granted_at')->value('tenant_id');
        });
        $event = $this->audit->record(
            'auth.login',
            'session',
            null,
            ['tokenId' => (string) $accessToken->accessToken->getKey(), 'mfa' => true],
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

    public function disable(MfaDisableRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->validated('password'), (string) $user->password_hash)) {
            throw new ApiException(ErrorCodes::INVALID_CREDENTIALS, 'The password is incorrect.', 401);
        }

        $this->mfa->disable($user, (string) $request->validated('code'));

        $this->audit->record('mfa.disable', 'user', $user->getKey(), [], $request, $user);

        return response()->json(null, 204);
    }

    public function regenerateRecoveryCodes(MfaCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $codes = $this->mfa->regenerateRecoveryCodes($user, (string) $request->validated('code'));

        $this->audit->record('mfa.recovery_codes_regenerated', 'user', $user->getKey(), [], $request, $user);

        return Envelope::success(data: ['recoveryCodes' => $codes], request: $request);
    }

    /* ------------------------------------------------------------------ */
    /* Session issuance (mirrors AuthController::login) */
    /* ------------------------------------------------------------------ */

    /**
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
}
