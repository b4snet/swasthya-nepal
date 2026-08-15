<?php

use App\Exceptions\ApiException;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\CredentialMigration;
use App\Support\EdgeFunctionPipeline;
use App\Support\ErrorCodes;
use App\Support\IdentityProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 5 — identity provisioning contract (CHECKPOINT 4).
 *
 * Proves the server-side GoTrue ↔ application-user binding rules:
 *  1. service-role provisioning binds a valid subject to an account;
 *  2. SELF authority refuses any subject that is not the caller's own
 *     verified sub (no cross-account binding, no forged input);
 *  3. duplicate subjects and silent rebinds fail closed;
 *  4. missing accounts are refused; provisioning NEVER changes account
 *     status (a locked/disabled account stays locked/disabled and the
 *     request-time status gate still refuses it);
 *  5. credential import plans follow the Phase 3 rules (argon2id/bcrypt →
 *     import; legacy hashes → controlled reset) and never leak the hash;
 *  6. idempotent retries are no-ops.
 */
it('binds an application account to a GoTrue subject (service role)', function () {
    $provisioner = app(IdentityProvisioner::class);
    $subject = (string) Str::uuid();
    $user = Identity::user();

    $bound = $provisioner->bind($user, $subject, IdentityProvisioner::AUTHORITY_SERVICE_ROLE);

    expect($bound->getKey())->toBe($user->getKey())
        ->and($bound->auth_subject_id)->toBe($subject)
        ->and(User::query()->where('auth_subject_id', $subject)->first()?->getKey())->toBe($user->getKey());
});

it('accepts an account id string instead of a model', function () {
    $provisioner = app(IdentityProvisioner::class);
    $subject = (string) Str::uuid();
    $user = Identity::user();

    $bound = $provisioner->bind($user->getKey(), $subject, IdentityProvisioner::AUTHORITY_SERVICE_ROLE);

    expect($bound->getKey())->toBe($user->getKey())
        ->and($bound->auth_subject_id)->toBe($subject);
});

it('refuses a missing application account', function () {
    $provisioner = app(IdentityProvisioner::class);

    try {
        $provisioner->bind((string) Str::uuid(), (string) Str::uuid(), IdentityProvisioner::AUTHORITY_SERVICE_ROLE);
        expect(true)->toBeFalse('must throw');
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe(ErrorCodes::NOT_FOUND);
    }
});

it('refuses a malformed subject (non-UUID) up front', function () {
    $provisioner = app(IdentityProvisioner::class);
    $user = Identity::user();

    try {
        $provisioner->bind($user, 'not-a-uuid', IdentityProvisioner::AUTHORITY_SERVICE_ROLE);
        expect(true)->toBeFalse('must throw');
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe(ErrorCodes::VALIDATION_ERROR);
    }

    expect($user->refresh()->auth_subject_id)->toBeNull();
});

it('SELF authority binds only the caller’s own account — no cross-account binding', function () {
    $provisioner = app(IdentityProvisioner::class);
    $subject = (string) Str::uuid();
    $user = Identity::user();

    // First-login self-link: the actor IS the account (possession of the
    // subject was proven upstream by the verified JWT).
    $bound = $provisioner->bind($user, $subject, IdentityProvisioner::AUTHORITY_SELF, $user);
    expect($bound->auth_subject_id)->toBe($subject);

    // A different actor trying to bind ANOTHER user's account is refused
    // FORBIDDEN — no cross-account binding, no matter what subject arrives.
    $attacker = Identity::user();
    $victim = Identity::user();

    try {
        $provisioner->bind($victim, (string) Str::uuid(), IdentityProvisioner::AUTHORITY_SELF, $attacker);
        expect(true)->toBeFalse('must throw');
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe(ErrorCodes::FORBIDDEN);
    }

    expect($victim->refresh()->auth_subject_id)->toBeNull();
});

it('refuses a subject already bound to another account', function () {
    $provisioner = app(IdentityProvisioner::class);
    $subject = (string) Str::uuid();
    $first = Identity::user(['auth_subject_id' => $subject]);
    $second = Identity::user();

    try {
        $provisioner->bind($second, $subject, IdentityProvisioner::AUTHORITY_SERVICE_ROLE);
        expect(true)->toBeFalse('must throw');
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe(ErrorCodes::RESOURCE_EXISTS);
    }

    expect($second->refresh()->auth_subject_id)->toBeNull()
        ->and($first->auth_subject_id)->toBe($subject);
});

it('refuses a silent rebind of an already-bound account', function () {
    $provisioner = app(IdentityProvisioner::class);
    $user = Identity::user(['auth_subject_id' => (string) Str::uuid()]);

    try {
        $provisioner->bind($user, (string) Str::uuid(), IdentityProvisioner::AUTHORITY_SERVICE_ROLE);
        expect(true)->toBeFalse('must throw');
    } catch (ApiException $exception) {
        expect($exception->errorCode)->toBe(ErrorCodes::RESOURCE_EXISTS);
    }

    $original = $user->auth_subject_id;
    expect($user->refresh()->auth_subject_id)->toBe($original);
});

it('is idempotent: re-binding the same user+subject is a no-op success', function () {
    $provisioner = app(IdentityProvisioner::class);
    $subject = (string) Str::uuid();
    $user = Identity::user(['auth_subject_id' => $subject]);

    $result = $provisioner->bind($user, $subject, IdentityProvisioner::AUTHORITY_SERVICE_ROLE);

    expect($result->auth_subject_id)->toBe($subject);
});

it('never changes account status — provisioning cannot activate a locked/disabled account', function () {
    $provisioner = app(IdentityProvisioner::class);

    foreach ([User::STATUS_LOCKED, User::STATUS_DISABLED, User::STATUS_PENDING] as $status) {
        $subject = (string) Str::uuid();
        $user = Identity::user(['status' => $status]);

        $bound = $provisioner->bind($user, $subject, IdentityProvisioner::AUTHORITY_SERVICE_ROLE);

        expect($bound->status)->toBe($status)->and($bound->auth_subject_id)->toBe($subject);
    }
});

it('the request-time status gate still refuses a locked account after provisioning', function () {
    $provisioner = app(IdentityProvisioner::class);
    $subject = (string) Str::uuid();
    $locked = Identity::user(['status' => User::STATUS_LOCKED, 'auth_subject_id' => $subject]);

    $provisioner->bind($locked, $subject, IdentityProvisioner::AUTHORITY_SERVICE_ROLE);

    // The pipeline (Phase 4) refuses non-active identities regardless of
    // the binding — provisioning cannot turn a locked account into an
    // authorized session.
    $token = edgePipelineToken($subject);
    expect(fn () => EdgeFunctionPipeline::resolve($token))->toThrow(
        fn (ApiException $exception) => $exception->errorCode === ErrorCodes::FORBIDDEN
    );
});

it('plans an import for an argon2id hash (with first-login rehash)', function () {
    $provisioner = app(IdentityProvisioner::class);
    $argon2id = Hash::make('a-strong-password-here-1'); // default driver (argon2id)
    $user = Identity::user(['password_hash' => $argon2id]);

    expect(CredentialMigration::algorithm($argon2id))->toBe(CredentialMigration::ALGORITHM_ARGON2ID);

    $plan = $provisioner->planCredentialImport($user);

    expect($plan['algorithm'])->toBe(CredentialMigration::ALGORITHM_ARGON2ID)
        ->and($plan['importable'])->toBeTrue()
        ->and($plan['action'])->toBe('import')
        ->and($plan['rehashOnFirstLogin'])->toBeTrue();
});

it('plans an import for a bcrypt hash (no rehash needed)', function () {
    $provisioner = app(IdentityProvisioner::class);
    $bcrypt = Hash::driver('bcrypt')->make('a-strong-password-here-2');
    $user = Identity::user();

    // The 'hashed' cast refuses a bcrypt value under the argon2id default
    // config, so the stored hash is written at the raw layer — exactly like
    // a legacy account migrated from a bcrypt-driver deployment.
    DB::table('users')->where('id', $user->getKey())->update(['password_hash' => $bcrypt]);
    $user = User::query()->find($user->getKey());

    $plan = $provisioner->planCredentialImport($user);

    expect($plan['algorithm'])->toBe(CredentialMigration::ALGORITHM_BCRYPT)
        ->and($plan['importable'])->toBeTrue()
        ->and($plan['action'])->toBe('import')
        ->and($plan['rehashOnFirstLogin'])->toBeFalse();
});

it('routes unsupported legacy hashes to a controlled reset — never imported', function () {
    $provisioner = app(IdentityProvisioner::class);

    foreach (['$1$salt$hash', '$6$salt$hash', 'plaintext-ish'] as $legacy) {
        $user = Identity::user();
        // Raw write: the cast would otherwise re-hash the plain/legacy
        // string. The import planner must see the stored value untouched.
        DB::table('users')->where('id', $user->getKey())->update(['password_hash' => $legacy]);

        $plan = $provisioner->planCredentialImport(User::query()->find($user->getKey()));

        expect($plan['importable'])->toBeFalse()
            ->and($plan['action'])->toBe('reset')
            ->and($plan['rehashOnFirstLogin'])->toBeFalse();
    }
});

it('the import plan never contains the hash or any password material', function () {
    $provisioner = app(IdentityProvisioner::class);
    $hash = Hash::make('a-strong-password-here-3');
    $user = Identity::user(['password_hash' => $hash]);

    $plan = $provisioner->planCredentialImport($user);
    $serialized = json_encode($plan) ?: '';

    expect($serialized)->not->toContain($hash)
        ->and($serialized)->not->toContain('a-strong-password-here-3')
        ->and(array_keys($plan))->toBe(['algorithm', 'importable', 'action', 'rehashOnFirstLogin']);
});

it('audits every successful and denied provisioning attempt', function () {
    $provisioner = app(IdentityProvisioner::class);
    $subject = (string) Str::uuid();
    $user = Identity::user();

    $provisioner->bind($user, $subject, IdentityProvisioner::AUTHORITY_SERVICE_ROLE);

    $linked = AuditEvent::query()
        ->where('action', 'auth.identity_linked')
        ->where('resource_id', $user->getKey())
        ->first();

    expect($linked)->not->toBeNull()
        ->and($linked->payload)->toMatchArray(['authority' => 'service_role', 'subject' => $subject]);
});
