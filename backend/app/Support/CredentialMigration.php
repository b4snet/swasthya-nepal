<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * Phase 3 — credential migration path to Supabase Auth (SECURITY.md §2,
 * config/hashing.php).
 *
 * Supabase Auth stores password hashes as bcrypt in auth.users.encrypted_password
 * (official docs, "Password security"), and its user-import feature verifies
 * passwords stored as bcrypt, scrypt (Firebase), or argon2 hashes (official
 * supabase-js Admin types: "Supports bcrypt, scrypt (firebase), and argon2
 * password hashes"). The application currently stores argon2id by default
 * (config/hashing.php) with bcrypt as the documented fallback.
 *
 * Import strategy (never weakening — MASTER_RULES.md §7.3):
 *  1. argon2id / argon2i / bcrypt hashes import directly into
 *     auth.users.encrypted_password (both are GoTrue-verifiable).
 *  2. On the first successful login, the application verifies against the
 *     imported hash and re-hashes to the target (bcrypt) so the account
 *     converges to GoTrue's native format. Rehash happens ONLY after the
 *     original hash verifies — a wrong password never triggers a rehash.
 *  3. Any hash that is neither importable nor verifiable (legacy md5-crypt,
 *     sha-crypt, unknown) is never carried over: the account is flagged for
 *     a CONTROLLED password reset instead. No plaintext, no downgrade.
 */
final class CredentialMigration
{
    public const ALGORITHM_ARGON2ID = 'argon2id';

    public const ALGORITHM_ARGON2I = 'argon2i';

    public const ALGORITHM_BCRYPT = 'bcrypt';

    public const ALGORITHM_MD5_CRYPT = 'md5-crypt';

    public const ALGORITHM_SHA_CRYPT = 'sha-crypt';

    public const ALGORITHM_UNKNOWN = 'unknown';

    /** Algorithms GoTrue's import verifier accepts (evidence above). */
    private const IMPORTABLE = [
        self::ALGORITHM_ARGON2ID => true,
        self::ALGORITHM_ARGON2I => true,
        self::ALGORITHM_BCRYPT => true,
    ];

    /**
     * Detect the hash algorithm from its `$...$` prefix.
     */
    public static function algorithm(string $hash): string
    {
        return match (true) {
            str_starts_with($hash, '$argon2id$') => self::ALGORITHM_ARGON2ID,
            str_starts_with($hash, '$argon2i$') => self::ALGORITHM_ARGON2I,
            str_starts_with($hash, '$2y$'), str_starts_with($hash, '$2a$'), str_starts_with($hash, '$2b$') => self::ALGORITHM_BCRYPT,
            str_starts_with($hash, '$1$') => self::ALGORITHM_MD5_CRYPT,
            str_starts_with($hash, '$5$'), str_starts_with($hash, '$6$') => self::ALGORITHM_SHA_CRYPT,
            default => self::ALGORITHM_UNKNOWN,
        };
    }

    /**
     * Whether a stored hash can be imported into Supabase Auth as-is.
     */
    public static function isImportable(string $hash): bool
    {
        return isset(self::IMPORTABLE[self::algorithm($hash)]);
    }

    /**
     * Whether a hash that just verified should be re-hashed to the import
     * target (bcrypt): true when the stored algorithm differs from bcrypt,
     * so the account converges to GoTrue's native format on first login.
     */
    public static function shouldRehash(string $hash): bool
    {
        return self::algorithm($hash) !== self::ALGORITHM_BCRYPT;
    }

    /**
     * Produce a fresh bcrypt hash in GoTrue's native format (the import
     * target). Only ever called AFTER the original hash verified.
     */
    public static function targetHash(string $password): string
    {
        return Hash::driver('bcrypt')->make($password);
    }

    /**
     * Verify a password against an existing stored hash using the
     * application's verifier (argon2id + bcrypt both supported by
     * Illuminate's hasher). Returns false for hashes whose algorithm the
     * verifier cannot read (e.g. md5-crypt) — the caller then falls back to
     * the controlled password-reset path.
     */
    public static function verify(string $password, string $hash): bool
    {
        try {
            // Illuminate's default (argon2id) driver refuses to check a
            // bcrypt hash when argon.verify is on, so route by algorithm.
            $driver = self::algorithm($hash) === self::ALGORITHM_BCRYPT ? 'bcrypt' : null;

            return $driver === null
                ? Hash::check($password, $hash)
                : Hash::driver($driver)->check($password, $hash);
        } catch (\Throwable) {
            // An unreadable hash (e.g. legacy md5-crypt) must never raise:
            // it resolves to "cannot verify" → controlled password reset.
            return false;
        }
    }
}
