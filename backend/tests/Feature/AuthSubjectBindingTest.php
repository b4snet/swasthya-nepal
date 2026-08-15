<?php

use App\Models\User;
use App\Support\IdentityProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 5 — identity-binding DATABASE invariants (CHECKPOINT 5).
 *
 * The provisioning adapter (IdentityProvisioner) enforces the rules at the
 * application layer; this suite proves the same rules are HARD at the
 * database layer, independent of any code:
 *
 *  1. the Phase 3 partial unique index: one GoTrue subject → at most one
 *     application account, and one account → at most one subject;
 *  2. the Phase 5 rebind-guard trigger: an already-bound account cannot be
 *     silently re-pointed at a different non-null subject — a direct UPDATE
 *     (by a buggy function, an operator, or a compromised server-side path)
 *     fails closed and leaves the row unchanged;
 *  3. an explicit unlink (→ NULL) remains possible — the controlled path
 *     for a deliberate re-bind;
 *  4. provisioning never alters the account lifecycle (status), and a
 *     failed bind leaves NO partial state.
 *
 * These run through Eloquent/DB on the test (owner) connection — RLS is not
 * the concern here; the constraints apply to every role, including the
 * owner and swasthya_app. Failure assertions are wrapped in a nested
 * DB::transaction (savepoint), so the expected constraint violation rolls
 * back to the savepoint and the surrounding RefreshDatabase transaction
 * stays usable for the follow-up assertions.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('the unique index refuses a second account claiming an already-bound subject', function () {
    $subject = (string) Str::uuid();
    $first = Identity::user(['auth_subject_id' => $subject]);

    // The constraint holds even against a direct insert (no adapter in the
    // loop) — one subject maps to at most one account.
    expect(fn () => DB::transaction(fn () => Identity::user(['auth_subject_id' => $subject])))
        ->toThrow(QueryException::class);

    expect(User::query()->where('auth_subject_id', $subject)->count())->toBe(1)
        ->and($first->auth_subject_id)->toBe($subject);
});

it('the rebind-guard trigger refuses a silent subject change on a bound account', function () {
    $subjectA = (string) Str::uuid();
    $subjectB = (string) Str::uuid();
    $user = Identity::user(['auth_subject_id' => $subjectA]);

    // A direct UPDATE (bypassing the adapter) must fail closed.
    expect(fn () => DB::transaction(fn () => $user->forceFill(['auth_subject_id' => $subjectB])->save()))
        ->toThrow(QueryException::class);

    // The row is unchanged — no partial state.
    expect($user->refresh()->auth_subject_id)->toBe($subjectA);
});

it('the rebind-guard trigger does not fire on INSERT or on unchanged updates', function () {
    $subject = (string) Str::uuid();
    $user = Identity::user(['auth_subject_id' => $subject]);

    // Ordinary account updates (status, last_login_at) are unaffected.
    $user->forceFill(['status' => User::STATUS_ACTIVE, 'last_login_at' => now()])->save();
    expect($user->refresh()->auth_subject_id)->toBe($subject);
});

it('an explicit unlink (set NULL) remains the controlled re-bind path', function () {
    $user = Identity::user(['auth_subject_id' => (string) Str::uuid()]);

    $user->forceFill(['auth_subject_id' => null])->save();
    expect($user->refresh()->auth_subject_id)->toBeNull();

    // A fresh subject can then be bound via the adapter (which audits it).
    app(IdentityProvisioner::class)->bind($user, (string) Str::uuid(), IdentityProvisioner::AUTHORITY_SERVICE_ROLE);
    expect($user->refresh()->auth_subject_id)->not->toBeNull();
});

it('binding never changes the account lifecycle, and a failed bind leaves no partial state', function () {
    $subject = (string) Str::uuid();
    $user = Identity::user(['status' => User::STATUS_LOCKED]);

    try {
        DB::transaction(function () use ($user, $subject): void {
            $user->forceFill(['auth_subject_id' => $subject])->save();
            expect($user->refresh()->status)->toBe(User::STATUS_LOCKED);

            // Force the transaction to fail AFTER the write — the bind must
            // roll back with it (no identity half-state).
            throw new RuntimeException('boom');
        });
        expect(true)->toBeFalse('the forced failure must propagate');
    } catch (RuntimeException) {
        // Expected: the whole unit rolled back.
    }

    expect($user->refresh()->auth_subject_id)->toBeNull()
        ->and($user->status)->toBe(User::STATUS_LOCKED);
});
