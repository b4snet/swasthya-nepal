<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Database foundation (MASTER_RULES.md §5, DATABASE.md §0): PostgreSQL is
 * the only engine; the schema is built from migrations on every run (this
 * suite IS the migration test — TESTING_STRATEGY.md §3.15).
 */
it('connects to PostgreSQL, never SQLite', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('answers a live query on the real engine', function () {
    expect(DB::select('select 1 as one')[0]->one)->toBe(1);
});

it('builds the schema from migrations with our conventions', function () {
    $columns = collect(DB::select(
        "select column_name, data_type, is_nullable from information_schema.columns where table_name = 'users'"
    ))->keyBy('column_name');

    expect($columns)->toHaveKeys(['id', 'email', 'password_hash', 'status', 'created_at', 'updated_at', 'deleted_at'])
        ->and($columns['id']->data_type)->toBe('uuid')
        ->and($columns['created_at']->data_type)->toBe('timestamp with time zone')
        ->and($columns['deleted_at']->data_type)->toBe('timestamp with time zone');
});

it('enforces the case-insensitive unique email index', function () {
    $email = 'nurse.poudel@example.org';

    User::factory()->create(['email' => $email]);

    // The lower(email) unique index rejects the same address in any case.
    expect(fn () => User::factory()->create(['email' => strtoupper($email)]))
        ->toThrow(QueryException::class);
});

it('generates UUIDv7 primary keys at insert time, not via sequences', function () {
    $user = User::factory()->create();

    expect($user->id)->toBeString()
        ->and(Str::isUuid($user->id))->toBeTrue()
        ->and(User::find($user->id)->id)->toBe($user->id);
});

it('persists timestamptz values in UTC', function () {
    $user = User::factory()->create();

    expect($user->created_at->timezone->getName())->toBe('UTC');
});
