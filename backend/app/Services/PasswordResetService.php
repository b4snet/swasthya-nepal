<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * PROGRAM PHASE 2 (password reset, SECURITY.md §5).
 *
 *  - Tokens are single-use, short-lived (15 min), and stored ONLY as
 *    SHA-256 hashes — a database read never yields a usable token.
 *  - Consumption is idempotent-safe but not replayable: using a token
 *    consumes it; presenting it again is an invalid-token error.
 *  - Per-account failure throttling (5 / 15 min) layers on the per-IP auth
 *    throttle on the routes.
 *  - Resetting the password revokes every refresh-token family for the
 *    account and clears the failed-login counter.
 */
final class PasswordResetService
{
    public const TOKEN_TTL_MINUTES = 15;

    private const FAILURE_THRESHOLD = 5;

    private const LOCKOUT_MINUTES = 15;

    /**
     * Issue a reset token for the user. Returns the plaintext token (given
     * to the delivery channel exactly once).
     */
    public function issue(User $user, ?string $ip = null, ?string $userAgent = null): string
    {
        $token = 'swpr_'.Str::random(64);

        PasswordResetToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $this->hash($token),
            'expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
            'ip_address' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
        ]);

        return $token;
    }

    /**
     * Validate + consume a reset token and set a new password. Throws
     * INVALID_TOKEN / TOKEN_EXPIRED / RATE_LIMITED; on success the token is
     * consumed, all refresh-token families are revoked, and the failed-login
     * counter is cleared.
     */
    public function consume(string $plaintext, string $newPassword, ?string $ip = null, ?string $userAgent = null): User
    {
        $record = PasswordResetToken::query()
            ->where('token_hash', $this->hash($plaintext))
            ->whereNull('consumed_at')
            ->latest('expires_at')
            ->first();

        if ($record === null || $record->expires_at->isPast()) {
            $this->registerFailure($record?->user);

            throw new ApiException(ErrorCodes::INVALID_TOKEN, 'The password-reset token is invalid or has expired.', 401);
        }

        $user = $record->user;
        $this->assertNotThrottled($user);

        $record->forceFill([
            'consumed_at' => now(),
            'ip_address' => $ip ?? $record->ip_address,
            'user_agent' => $userAgent ?? $record->user_agent,
        ])->save();

        $user->forceFill([
            'password_hash' => $newPassword, // hashed by the model cast
            'password_changed_at' => now(),
            'status' => User::STATUS_ACTIVE,
        ])->save();

        // A compromised or lost token is not a credential anyone keeps using:
        // every session dies on password change (SECURITY.md §5).
        app(RefreshTokenService::class)->revokeAllForUser($user);
        Cache::forget('auth.failures:'.strtolower((string) $user->email));
        Cache::forget($this->failureKey($user));

        return $user;
    }

    private function assertNotThrottled(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $failures = (int) Cache::get($this->failureKey($user), 0);

        if ($failures >= self::FAILURE_THRESHOLD) {
            throw new ApiException(
                ErrorCodes::RATE_LIMITED,
                'Too many invalid password-reset attempts. Try again later.',
                429,
                [],
                ['Retry-After' => (string) (self::LOCKOUT_MINUTES * 60)],
            );
        }
    }

    private function registerFailure(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $key = $this->failureKey($user);
        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, now()->addMinutes(self::LOCKOUT_MINUTES));
    }

    private function failureKey(User $user): string
    {
        return 'password-reset.failures:'.$user->getKey();
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
