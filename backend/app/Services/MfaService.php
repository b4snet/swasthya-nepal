<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\MfaChallenge;
use App\Models\User;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * PROGRAM PHASE 2 (MFA) — TOTP multi-factor lifecycle (SECURITY.md §3).
 *
 *  - Secrets are app-layer encrypted at rest (Crypt::encryptString, APP_KEY —
 *    KMS-backed column encryption is the recorded production hardening).
 *  - Recovery codes are single-use: only SHA-256 hashes are stored; a
 *    database read never yields a usable code, and using one removes it.
 *  - Login challenges are one-shot and short-lived (5 min); only their hash
 *    is stored (mfa_challenges).
 *  - Failed challenge attempts are throttled per user (5 / 15 min), layered
 *    on the per-IP auth throttle.
 *  - Every MFA event is audited by the controller (enroll, activate, use of
 *    recovery code, disable, code regeneration).
 *
 * No bypass path: a staff account with MFA enabled CANNOT obtain tokens from
 * login or refresh without completing a challenge (login refuses token
 * issuance; refresh only rotates tokens that a challenged session already
 * holds, and access tokens stay short-lived).
 */
final class MfaService
{
    public const CHALLENGE_TTL_MINUTES = 5;

    public const RECOVERY_CODE_COUNT = 10;

    private const FAILURE_THRESHOLD = 5;

    private const LOCKOUT_MINUTES = 15;

    /* ------------------------------------------------------------------ */
    /* Enrollment */
    /* ------------------------------------------------------------------ */

    /**
     * Generate a fresh secret for enrollment. Persisted immediately; the
     * account is only ENABLED once activation stores the recovery hashes.
     *
     * @return array{secretBase32: string, otpauthUrl: string}
     */
    public function enroll(User $user, string $email): array
    {
        $secret = Totp::generateSecret();
        $user->setMfaSecret($secret);

        return [
            'secretBase32' => $secret,
            'otpauthUrl' => Totp::otpauthUrl('Swasthya', $email, $secret),
        ];
    }

    /**
     * Verify a first TOTP code and finalize enrollment. Returns the ten
     * single-use recovery codes (plaintext, exactly once — only their
     * hashes are stored).
     *
     * @return list<string>
     */
    public function activate(User $user, string $code): array
    {
        if (! $this->verifyCode($user, $code)) {
            $this->registerFailure($user, 'MFA verification failed.');

            throw new ApiException(ErrorCodes::INVALID_CODE, 'The MFA code is invalid.', 422);
        }

        $codes = $this->generateRecoveryCodes();
        $user->setMfaRecoveryHashes(array_map(
            static fn (string $code): string => hash('sha256', $code),
            $codes,
        ));

        return $codes;
    }

    /* ------------------------------------------------------------------ */
    /* Login challenges */
    /* ------------------------------------------------------------------ */

    /**
     * @return string the plaintext challenge id (given to the client once)
     */
    public function issueChallenge(User $user, ?string $ip = null, ?string $userAgent = null): string
    {
        $challengeId = 'mfa_'.Str::random(64);

        MfaChallenge::query()->create([
            'user_id' => $user->getKey(),
            'challenge_hash' => hash('sha256', $challengeId),
            'expires_at' => now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
            'ip_address' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
        ]);

        return $challengeId;
    }

    /**
     * Complete a challenge with a TOTP code or a single-use recovery code.
     * Resolves the principal from the challenge, consumes it regardless of
     * outcome (one-shot), and returns the user on success. Throws
     * RATE_LIMITED after repeated failures.
     */
    public function completeChallenge(string $challengeId, string $code, ?string $ip = null, ?string $userAgent = null): User
    {
        $challenge = MfaChallenge::query()
            ->where('challenge_hash', hash('sha256', $challengeId))
            ->whereNull('consumed_at')
            ->latest('expires_at')
            ->first();

        if ($challenge === null || $challenge->expires_at->isPast()) {
            throw new ApiException(ErrorCodes::INVALID_TOKEN, 'The MFA challenge is invalid or has expired.', 401);
        }

        $user = $challenge->user;
        $this->assertNotThrottled($user);

        $challenge->forceFill(['consumed_at' => now()])->save();

        if (! $this->verifyCode($user, $code) && ! $this->consumeRecoveryCode($user, $code)) {
            $this->registerFailure($user, 'MFA verification failed.');

            throw new ApiException(ErrorCodes::INVALID_CODE, 'The MFA code is invalid.', 422);
        }

        Cache::forget($this->failureKey($user));

        return $user;
    }

    /* ------------------------------------------------------------------ */
    /* Recovery codes / disable */
    /* ------------------------------------------------------------------ */

    /**
     * Rotate the recovery codes (requires a valid TOTP code). Returns the
     * new plaintext codes exactly once.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        if (! $this->verifyCode($user, $code)) {
            $this->registerFailure($user, 'MFA verification failed.');

            throw new ApiException(ErrorCodes::INVALID_CODE, 'The MFA code is invalid.', 422);
        }

        $codes = $this->generateRecoveryCodes();
        $user->setMfaRecoveryHashes(array_map(
            static fn (string $code): string => hash('sha256', $code),
            $codes,
        ));

        return $codes;
    }

    public function disable(User $user, string $code): void
    {
        if (! $this->verifyCode($user, $code)) {
            $this->registerFailure($user, 'MFA verification failed.');

            throw new ApiException(ErrorCodes::INVALID_CODE, 'The MFA code is invalid.', 422);
        }

        $user->clearMfa();
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    private function verifyCode(User $user, string $code): bool
    {
        $secret = $user->mfaSecret();

        return $secret !== null && Totp::verify($secret, $code);
    }

    /**
     * @return bool true when the code matched and was consumed
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->mfaRecoveryHashes();
        $needle = hash('sha256', $code);

        $index = array_search($needle, $hashes, true);
        if ($index === false) {
            return false;
        }

        unset($hashes[$index]);
        $user->setMfaRecoveryHashes(array_values($hashes));

        return true;
    }

    /**
     * @return list<string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = 'swc_'.Str::random(20);
        }

        return $codes;
    }

    private function assertNotThrottled(User $user): void
    {
        $failures = (int) Cache::get($this->failureKey($user), 0);

        if ($failures >= self::FAILURE_THRESHOLD) {
            throw new ApiException(
                ErrorCodes::RATE_LIMITED,
                'Too many failed MFA attempts. Try again later.',
                429,
                [],
                ['Retry-After' => (string) (self::LOCKOUT_MINUTES * 60)],
            );
        }
    }

    private function registerFailure(User $user, string $reason): void
    {
        $key = $this->failureKey($user);
        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, now()->addMinutes(self::LOCKOUT_MINUTES));
    }

    private function failureKey(User $user): string
    {
        return 'mfa.failures:'.$user->getKey();
    }
}
