<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Both suites run inside the Laravel application (container, config).
| Feature tests additionally run against a real PostgreSQL test database
| whose schema is built from migrations on every run — the migration test
| (TESTING_STRATEGY.md §3.15). RefreshDatabase keeps each test isolated in a
| transaction; RLS behavior stays live because the engine is PostgreSQL,
| never SQLite in-memory (MASTER_RULES.md §16.2).
|
*/

pest()->extend(TestCase::class)
    ->in('Unit', 'Feature');

pest()
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Seed the platform RBAC catalog (roles/permissions/grants) for a test.
 */
function seedIdentity(): void
{
    test()->seed(RolePermissionSeeder::class);
}
