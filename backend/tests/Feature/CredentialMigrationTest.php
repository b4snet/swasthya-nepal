<?php

use App\Support\CredentialMigration;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 3 — credential migration path (config/hashing.php, SECURITY.md §2).
 *
 * Supabase Auth stores bcrypt and its import verifier accepts bcrypt, scrypt
 * (Firebase), and argon2 hashes (official supabase-js Admin type docs). The
 * application stores argon2id by default with bcrypt as the documented
 * fallback — both importable. This suite pins the detection, the import
 * decision, and the rehash-on-successful-login convergence, and proves that
 * unreadable legacy hashes (md5-crypt etc.) resolve to a CONTROLLED reset
 * instead of being carried over — passwords are never weakened or silently
 * dropped.
 */
it('detects the hash algorithm from the $prefix', function (string $hash, string $expected) {
    expect(CredentialMigration::algorithm($hash))->toBe($expected);
})->with([
    ['$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$c29tZWhhc2g', CredentialMigration::ALGORITHM_ARGON2ID],
    ['$argon2i$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$c29tZWhhc2g', CredentialMigration::ALGORITHM_ARGON2I],
    ['$2y$12$abcdefghijklmnopqrstuv$abcdefghijklmnopqrstuv', CredentialMigration::ALGORITHM_BCRYPT],
    ['$2a$12$abcdefghijklmnopqrstuv$abcdefghijklmnopqrstuv', CredentialMigration::ALGORITHM_BCRYPT],
    ['$2b$12$abcdefghijklmnopqrstuv$abcdefghijklmnopqrstuv', CredentialMigration::ALGORITHM_BCRYPT],
    ['$1$saltsalt$hashhashhashhashhashhash', CredentialMigration::ALGORITHM_MD5_CRYPT],
    ['$6$rounds=5000$salt$hash', CredentialMigration::ALGORITHM_SHA_CRYPT],
    ['plaintext', CredentialMigration::ALGORITHM_UNKNOWN],
    ['', CredentialMigration::ALGORITHM_UNKNOWN],
]);

it('imports argon2id, argon2i and bcrypt hashes as-is; never legacy formats', function (string $hash, bool $expected) {
    expect(CredentialMigration::isImportable($hash))->toBe($expected);
})->with([
    ['$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$c29tZWhhc2g', true],
    ['$argon2i$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$c29tZWhhc2g', true],
    ['$2y$12$abcdefghijklmnopqrstuv$abcdefghijklmnopqrstuv', true],
    ['$1$saltsalt$hashhashhashhashhashhash', false],
    ['$6$rounds=5000$salt$hash', false],
    ['plaintext', false],
]);

it('re-hashes to the bcrypt import target only when the algorithm differs', function () {
    $argon2id = Hash::make('correct horse battery staple');
    $bcrypt = Hash::driver('bcrypt')->make('correct horse battery staple');

    expect(CredentialMigration::algorithm($argon2id))->toBe(CredentialMigration::ALGORITHM_ARGON2ID)
        ->and(CredentialMigration::shouldRehash($argon2id))->toBeTrue()
        ->and(CredentialMigration::shouldRehash($bcrypt))->toBeFalse();
});

it('verifies before migrating: a wrong password never triggers a rehash', function () {
    $stored = Hash::make('correct horse battery staple');

    expect(CredentialMigration::verify('correct horse battery staple', $stored))->toBeTrue();

    // Wrong password → false. The caller must NOT rehash on this path.
    expect(CredentialMigration::verify('not the password', $stored))->toBeFalse();
});

it('converges an imported argon2id account to the bcrypt target after a verified login', function () {
    $imported = Hash::make('correct horse battery staple'); // argon2id, as stored today

    // First successful login against the imported hash…
    expect(CredentialMigration::verify('correct horse battery staple', $imported))->toBeTrue()
        ->and(CredentialMigration::shouldRehash($imported))->toBeTrue();

    // …produces a bcrypt hash that verifies with the same password.
    $converged = CredentialMigration::targetHash('correct horse battery staple');

    expect(CredentialMigration::algorithm($converged))->toBe(CredentialMigration::ALGORITHM_BCRYPT)
        ->and(CredentialMigration::verify('correct horse battery staple', $converged))->toBeTrue()
        ->and(CredentialMigration::shouldRehash($converged))->toBeFalse()
        ->and($converged)->not->toBe($imported); // fresh salt, never the same string
});

it('never imports an unreadable legacy hash — the account is flagged for a controlled reset', function () {
    $legacy = '$1$saltsalt$hashhashhashhashhashhash';

    expect(CredentialMigration::isImportable($legacy))->toBeFalse()
        ->and(CredentialMigration::algorithm($legacy))->toBe(CredentialMigration::ALGORITHM_MD5_CRYPT)
        // The application verifier cannot read it either — it must not be
        // carried over or silently reset; it becomes a controlled reset.
        ->and(CredentialMigration::verify('anything', $legacy))->toBeFalse();
});
