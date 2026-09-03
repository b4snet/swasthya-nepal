<?php

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Identity;

/**
 * DB-backed per-account login lockout (SECURITY.md §18, section 37 DB proof).
 * The failure counter and lockout window live in the `users` row — NOT the
 * cache — so a lockout survives restarts, cache eviction, and multi-node
 * deployments. Attempts are serialized with a row lock so concurrent failures
 * can never under-count.
 */
beforeEach(function (): void {
    seedIdentity();
    config()->set('swasthya.rate_limits.auth', 10000);
    config()->set('swasthya.auth.login_failure_threshold', 5);
    config()->set('swasthya.auth.login_lockout_minutes', 15);
});

it('persists failed attempts and the lockout window in the users row', function () {
    $user = Identity::user(['email' => 'db@one.test']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'db@one.test',
            'password' => 'wrong-password-'.$attempt,
        ])->assertStatus(401);
    }

    expect($user->fresh()->failed_attempts)->toBe(5)
        ->and($user->fresh()->locked_until)->not->toBeNull()
        ->and($user->fresh()->locked_until->isFuture())->toBeTrue()
        ->and($user->fresh()->last_failed_at)->not->toBeNull();
});

it('honors a lockout stored in the database even when the cache is empty', function () {
    Identity::user(['email' => 'db@one.test']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'db@one.test',
            'password' => 'wrong-password-'.$attempt,
        ])->assertStatus(401);
    }

    // Wipe every cache entry — the lockout must still hold because it lives
    // in the database, proving it is durable and not cache-dependent.
    Cache::flush();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'db@one.test',
        'password' => Identity::PASSWORD,
    ])->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertHeader('Retry-After');
});

it('does not lock the account below the threshold and then locks at it', function () {
    Identity::user(['email' => 'db@one.test']);

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'db@one.test',
            'password' => 'wrong-password-'.$attempt,
        ])->assertStatus(401);
        expect(User::query()->where('email', 'db@one.test')->first()->locked_until)->toBeNull();
    }

    // The 5th failure (== threshold) arms the lockout.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'db@one.test',
        'password' => 'wrong-password-5',
    ])->assertStatus(401);
    expect(User::query()->where('email', 'db@one.test')->first()->locked_until)->not->toBeNull();
});

it('clears the DB lockout state on a successful login', function () {
    Identity::user(['email' => 'db@one.test']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'db@one.test',
            'password' => 'wrong-password-'.$attempt,
        ])->assertStatus(401);
    }

    $user = User::query()->where('email', 'db@one.test')->first();
    $user->forceFill(['locked_until' => null])->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'db@one.test',
        'password' => Identity::PASSWORD,
    ])->assertOk();

    expect(User::query()->where('email', 'db@one.test')->first()->failed_attempts)->toBe(0)
        ->and(User::query()->where('email', 'db@one.test')->first()->locked_until)->toBeNull();
});

it('audits the DB-backed lockout event', function () {
    Identity::user(['email' => 'db@one.test']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'db@one.test',
            'password' => 'wrong-password-'.$attempt,
        ])->assertStatus(401);
    }

    // A 6th attempt while locked is what surfaces the audited lockout refuse.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'db@one.test',
        'password' => Identity::PASSWORD,
    ])->assertStatus(429);

    expect(AuditEvent::query()->where('action', 'auth.lockout')->count())->toBe(1);
});