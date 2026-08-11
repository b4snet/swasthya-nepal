<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\ErrorCodes;
use Illuminate\Support\Str;

/**
 * Rotating refresh-token lifecycle (SECURITY.md §4–5, MASTER_RULES.md §7.1).
 *
 *  - issue(): returns the plaintext token (given to the client exactly once)
 *    and stores only its SHA-256 hash.
 *  - rotate(): validates a presented token, revokes it, issues a successor
 *    in the same family.
 *  - Reuse detection: presenting an already-revoked token revokes the ENTIRE
 *    family and is an audited, alerted event — a replayed token is a theft
 *    signal, not a bug (SECURITY.md §4).
 */
final class RefreshTokenService
{
    /**
     * @return array{0: string, 1: RefreshToken} the plaintext token (given
     *                                           to the client exactly once) and its stored record
     */
    public function issue(User $user, ?string $ip = null, ?string $userAgent = null, int $ttlDays = 7): array
    {
        $token = $this->generate();

        return [$token, $this->store($user, $token, $ip, $userAgent, $ttlDays)];
    }

    /**
     * @return array{0: string, 1: RefreshToken, 2: User} the successor's
     *                                                    plaintext token, its record, and the user
     *
     * @throws ApiException INVALID_TOKEN / TOKEN_EXPIRED / TOKEN_REVOKED
     */
    public function rotate(string $plaintext, ?string $ip = null, ?string $userAgent = null, int $ttlDays = 7): array
    {
        $record = RefreshToken::query()
            ->where('token_hash', $this->hash($plaintext))
            ->first();

        if ($record === null) {
            throw new ApiException(ErrorCodes::INVALID_TOKEN, 'The refresh token is invalid.', 401);
        }

        if ($record->revoked_at !== null) {
            // Reuse of a rotated token: revoke the whole family.
            $this->revokeFamily((string) $record->family_id);

            throw new ApiException(
                ErrorCodes::TOKEN_REVOKED,
                'The refresh token has already been used and is revoked.',
                401,
            );
        }

        if ($record->expires_at->isPast()) {
            throw new ApiException(ErrorCodes::TOKEN_EXPIRED, 'The refresh token has expired.', 401);
        }

        $user = $record->user;
        $successorPlaintext = $this->generate();

        $successor = $this->store(
            $user,
            $successorPlaintext,
            $ip,
            $userAgent,
            $ttlDays,
            (string) $record->family_id,
        );

        $record->update([
            'revoked_at' => now(),
            'replaced_by' => $successor->getKey(),
        ]);

        return [$successorPlaintext, $successor, $user];
    }

    public function revokeAllForUser(User $user): void
    {
        RefreshToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeFamily(string $familyId): void
    {
        RefreshToken::query()
            ->where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function store(
        User $user,
        string $plaintext,
        ?string $ip,
        ?string $userAgent,
        int $ttlDays,
        ?string $familyId = null,
    ): RefreshToken {
        return RefreshToken::query()->create([
            'user_id' => $user->getKey(),
            'family_id' => $familyId ?? (string) Str::uuid(),
            'token_hash' => $this->hash($plaintext),
            'expires_at' => now()->addDays($ttlDays),
            'ip_address' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
        ]);
    }

    private function generate(): string
    {
        return 'swr_'.Str::random(64);
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
