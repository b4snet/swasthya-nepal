<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 5 — server-side identity provisioning (GoTrue ↔ application user).
 *
 * The ONLY writer of users.auth_subject_id (besides raw database fixtures).
 * It implements the provisioning contract from the Phase 5 design:
 *
 *  1. The subject is always server-provided — either the `sub` extracted
 *     from a VERIFIED GoTrue JWT (authority SELF) or a subject looked up
 *     through the service-role admin API (authority SERVICE_ROLE). A
 *     client-supplied subject is never accepted as authoritative; the SELF
 *     path refuses unless the account being bound IS the caller's own
 *     (possession of `sub` is established by the upstream JWT verification
 *     in the edge function — the adapter never re-derives it).
 *  2. `users.auth_subject_id` stays the unique nullable mapping (Phase 3
 *     partial index + Phase 5 rebind-guard trigger are the DB-level
 *     invariants this adapter drives, never bypasses).
 *  3. Claims ALWAYS key off the application user id (users.id), never the
 *     GoTrue subject — this adapter only establishes the mapping.
 *  4. Duplicate subject (bound to a different account) and rebind (this
 *     account already bound elsewhere) fail closed (409). Re-binding the
 *     SAME user+subject is idempotent (a retried import is a no-op).
 *  5. Provisioning NEVER changes account status: a locked/disabled/pending
 *     account stays locked/disabled/pending — the request-time status gate
 *     (EdgeFunctionPipeline / ResolveTenantContext) remains authoritative.
 *  6. Email matching alone never creates a binding — the caller supplies an
 *     explicit application user id.
 *  7. Every successful and failed transition is audited
 *     (auth.identity_linked / auth.identity_link_denied).
 *  8. Deleting an auth identity (or unlinking it) never deletes the
 *     application account.
 *
 * Credential import is a separate concern: planCredentialImport() derives
 * the import decision from CredentialMigration (argon2id/bcrypt import
 * directly; legacy hashes → controlled reset). The actual write into
 * auth.users.encrypted_password is a service-role Admin API call that exists
 * only in the real Supabase deployment — this adapter never holds the hash
 * in a response surface.
 */
final class IdentityProvisioner
{
    /** Server-side provisioning path (service-role admin API / import job). */
    public const AUTHORITY_SERVICE_ROLE = 'service_role';

    /** The identity's own verified GoTrue JWT (self-link through the edge function). */
    public const AUTHORITY_SELF = 'self';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Bind an application user to a GoTrue subject.
     *
     * @param  User|string  $user  the application account (or its id)
     * @param  string  $subject  the GoTrue auth.users.id (UUID) — server-provided
     * @param  string  $authority  AUTHORITY_SERVICE_ROLE | AUTHORITY_SELF
     * @param  User|null  $actor  auditing actor (null for unattended service-role import)
     *
     * @throws ApiException NOT_FOUND (no such account) / VALIDATION_ERROR
     *                      (malformed subject) / FORBIDDEN (SELF authority
     *                      mismatch) / RESOURCE_EXISTS (duplicate or rebind)
     */
    public function bind(User|string $user, string $subject, string $authority, ?User $actor = null, ?Request $request = null): User
    {
        $account = is_string($user) ? User::query()->find($user) : $user;

        if ($account === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'The application account does not exist.', 404);
        }

        if (! self::isWellFormedSubject($subject)) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'The auth subject must be a UUID.',
                422,
            );
        }

        if (! in_array($authority, [self::AUTHORITY_SERVICE_ROLE, self::AUTHORITY_SELF], true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Unknown provisioning authority.', 422);
        }

        // SELF authority: possession of `sub` was proven upstream by the
        // verified JWT (the edge function only passes the verified subject).
        // Here we enforce the remaining half of the rule: the account being
        // bound MUST be the caller's own account — no cross-account binding
        // is ever possible through this path, no matter what subject value
        // arrives.
        if ($authority === self::AUTHORITY_SELF) {
            if ($actor === null || $actor->getKey() !== $account->getKey()) {
                $this->audit->record(
                    'auth.identity_link_denied',
                    'user',
                    $account->getKey(),
                    ['reason' => 'self_link_subject_mismatch', 'authority' => $authority],
                    $request,
                    $actor,
                );

                throw new ApiException(
                    ErrorCodes::FORBIDDEN,
                    'You may only link your own authentication identity.',
                    403,
                );
            }
        }

        // Duplicate: the subject already belongs to a different account.
        $existing = User::query()
            ->where('auth_subject_id', $subject)
            ->where('id', '!=', $account->getKey())
            ->first();

        if ($existing !== null) {
            $this->audit->record(
                'auth.identity_link_denied',
                'user',
                $account->getKey(),
                ['reason' => 'subject_already_bound', 'authority' => $authority],
                $request,
                $actor,
            );

            throw new ApiException(
                ErrorCodes::RESOURCE_EXISTS,
                'This authentication identity is already linked to another account.',
                409,
            );
        }

        // Rebind: this account is already bound to a different subject.
        if ($account->auth_subject_id !== null && $account->auth_subject_id !== $subject) {
            $this->audit->record(
                'auth.identity_link_denied',
                'user',
                $account->getKey(),
                ['reason' => 'account_already_bound', 'authority' => $authority],
                $request,
                $actor,
            );

            throw new ApiException(
                ErrorCodes::RESOURCE_EXISTS,
                'This account is already linked to a different authentication identity.',
                409,
            );
        }

        // Idempotent retry: the exact mapping already exists → no-op success.
        if ($account->auth_subject_id === $subject) {
            return $account;
        }

        DB::transaction(function () use ($account, $subject): void {
            $account->forceFill(['auth_subject_id' => $subject])->save();
        });

        $this->audit->record(
            'auth.identity_linked',
            'user',
            $account->getKey(),
            ['authority' => $authority, 'subject' => $subject],
            $request,
            $actor,
        );

        return $account->refresh();
    }

    /**
     * The credential import plan for a stored password hash (Phase 3 rules).
     *
     * The returned payload NEVER contains the hash itself — it reports the
     * decision so the caller can route the import (service-role admin API)
     * or the controlled reset. A hash that is neither importable nor
     * verifiable maps to action 'reset' (never carried over).
     *
     * @return array{algorithm: string, importable: bool, action: string, rehashOnFirstLogin: bool}
     */
    public function planCredentialImport(User $user): array
    {
        // Read the STORED hash as-is (getRawOriginal bypasses the 'hashed'
        // cast): the cast refuses non-default-driver hashes (bcrypt under an
        // argon2id default config) and re-hashes legacy strings, while the
        // import path must see exactly what sits in the database.
        $hash = (string) $user->getRawOriginal('password_hash');
        $algorithm = CredentialMigration::algorithm($hash);
        $importable = CredentialMigration::isImportable($hash);

        return [
            'algorithm' => $algorithm,
            'importable' => $importable,
            // 'import' → the hash goes into auth.users.encrypted_password;
            // 'reset' → the hash cannot be carried over (controlled reset).
            'action' => $importable ? 'import' : 'reset',
            // argon2id/argon2i converge to GoTrue's native bcrypt on the
            // first successful login (after the original hash verifies).
            'rehashOnFirstLogin' => $importable && CredentialMigration::shouldRehash($hash),
        ];
    }

    /**
     * GoTrue subjects are UUIDs (auth.users.id); users.auth_subject_id is a
     * uuid column. Anything else is rejected up front — fail closed.
     */
    public static function isWellFormedSubject(string $subject): bool
    {
        return Str::isUuid($subject);
    }
}
