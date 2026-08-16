<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\OauthPartner;
use App\Models\OauthPartnerToken;
use App\Support\ErrorCodes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The OAuth2 partner surface (INTEROPERABILITY.md §11, SECURITY.md §5):
 * tenant-scoped client_credentials machine access. Partner registration is
 * a staff action; the client secret is returned ONCE in plaintext and stored
 * as a hash. Token issuance verifies client_id + secret (hash) and the
 * partner's active status, and grants only the intersection of the
 * partner's scopes and the requested scopes. Tokens are short-lived,
 * scoped, stored as sha256 hashes, and revocable (revoking the partner
 * revokes every active token). The partner lookup is by the globally unique
 * client_id; RLS binds every token row to its tenant.
 */
final class PartnerOauthService
{
    /**
     * @param  list<string>  $scopes
     * @return array{partner: OauthPartner, clientSecret: string}
     */
    public function registerPartner(
        string $tenantId,
        string $name,
        array $scopes,
        int $tokenTtlSeconds,
        ?string $webhookUrl,
        ?string $webhookSecret,
        string $staffId,
    ): array {
        $this->assertScopes($scopes);

        $clientId = 'swasthya_'.substr((string) Str::uuid(), 0, 24);
        $clientSecret = 'sec_'.bin2hex(random_bytes(32));

        $partner = $this->guardUnique(fn (): OauthPartner => OauthPartner::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'client_id' => $clientId,
            'client_secret_hash' => hash('sha256', $clientSecret),
            'scopes' => $scopes,
            'status' => OauthPartner::STATUS_ACTIVE,
            'token_ttl_seconds' => $tokenTtlSeconds,
            'webhook_url' => $webhookUrl,
            'webhook_secret_hash' => $webhookSecret !== null ? hash('sha256', $webhookSecret) : null,
            'created_by_staff_id' => $staffId,
            'lock_version' => 0,
        ]));

        return ['partner' => $partner, 'clientSecret' => $clientSecret];
    }

    /**
     * client_credentials token issuance. The client_id is globally unique so
     * the tenant is derived from the partner row — the client can never
     * choose a tenant. The partner's OWN tenant GUC is projected onto the
     * token row by the request transaction (ResolvePartnerContext), so RLS
     * binds the write.
     *
     * @param  list<string>  $requestedScopes
     * @return array{token: string, tokenRow: OauthPartnerToken}
     */
    public function issueToken(string $clientId, string $clientSecret, array $requestedScopes): array
    {
        $partner = OauthPartner::query()->where('client_id', $clientId)->first();

        if ($partner === null
            || $partner->status !== OauthPartner::STATUS_ACTIVE
            || ! hash_equals((string) $partner->client_secret_hash, hash('sha256', $clientSecret))) {
            throw new ApiException(ErrorCodes::INVALID_CREDENTIALS, 'The client credentials are incorrect.', 401);
        }

        $granted = array_values(array_intersect($requestedScopes, $partner->scopes ?? []));
        if ($granted === []) {
            throw new ApiException(
                ErrorCodes::FORBIDDEN,
                'None of the requested scopes are granted to this partner.',
                403,
            );
        }

        $bearer = 'ptr_'.bin2hex(random_bytes(32));
        $ttl = (int) $partner->token_ttl_seconds;

        $tokenRow = $this->guardUnique(fn (): OauthPartnerToken => OauthPartnerToken::query()->create([
            'tenant_id' => $partner->tenant_id,
            'oauth_partner_id' => $partner->getKey(),
            'token_hash' => hash('sha256', $bearer),
            'scopes' => $granted,
            'expires_at' => now()->addSeconds($ttl),
            'revoked_at' => null,
            'last_used_at' => null,
        ]));

        return ['token' => $bearer, 'tokenRow' => $tokenRow];
    }

    /**
     * Resolve a partner bearer token: hash lookup + expiry + revocation.
     * Returns null for anything invalid (callers map to 401 — no existence
     * leak about which part failed).
     */
    public function resolveToken(string $bearer): ?OauthPartnerToken
    {
        if ($bearer === '') {
            return null;
        }

        /** @var OauthPartnerToken|null $token */
        $token = OauthPartnerToken::query()
            ->where('token_hash', hash('sha256', $bearer))
            ->first();

        if ($token === null) {
            return null;
        }

        if ($token->expires_at->isPast() || $token->revoked_at !== null) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $token;
    }

    public function assertScope(OauthPartnerToken $token, string $scope): void
    {
        if (! in_array($scope, $token->scopes ?? [], true)) {
            throw new ApiException(
                ErrorCodes::FORBIDDEN,
                'This token does not grant the requested scope.',
                403,
            );
        }
    }

    public function revokePartner(OauthPartner $partner, string $staffId): OauthPartner
    {
        DB::transaction(function () use ($partner): void {
            OauthPartner::query()
                ->whereKey($partner->getKey())
                ->where('status', OauthPartner::STATUS_ACTIVE)
                ->where('lock_version', $partner->lock_version)
                ->update([
                    'status' => OauthPartner::STATUS_REVOKED,
                    'lock_version' => $partner->lock_version + 1,
                ]);

            OauthPartnerToken::query()
                ->where('oauth_partner_id', $partner->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        });

        return $partner->refresh();
    }

    /**
     * @param  list<string>  $scopes
     */
    private function assertScopes(array $scopes): void
    {
        foreach ($scopes as $scope) {
            if (! in_array($scope, OauthPartner::ALL_SCOPES, true)) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The scope is not supported.', 422);
            }
        }
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  callable(): T  $create
     * @return T
     */
    private function guardUnique(callable $create)
    {
        try {
            return DB::transaction($create);
        } catch (QueryException $e) {
            $pdo = $e->getPrevious();
            if ($pdo instanceof \PDOException && str_starts_with((string) $pdo->getCode(), '23505')) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'The client id or token already exists.',
                    409,
                );
            }

            throw $e;
        }
    }
}
