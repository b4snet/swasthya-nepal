<?php

use App\Support\JwtClaims;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

/**
 * Mint a GoTrue-shaped token for a subject (locally signed by the Phase 3
 * JwtClaims codec — the harness equivalent of a real Supabase Auth access
 * token whose `sub` is the auth.users UUID mapped via users.auth_subject_id).
 */
function edgePipelineToken(string $subject, array $extraClaims = []): string
{
    return JwtClaims::issue(['sub' => $subject] + $extraClaims, 3600);
}

/*
|--------------------------------------------------------------------------
| Database-layer RLS helpers (shared by every RLS suite)
|--------------------------------------------------------------------------
|
| The RLS suites connect as the APPLICATION role (swasthya_app — no
| ownership, no BYPASSRLS) on the dedicated least-privilege connection
| (config/database.php 'pgsql_rls') and set the context GUCs directly,
| proving PostgreSQL itself isolates rows — independent of the application
| authorization layer. Defined here so every test file can use them no
| matter which subset is executed (Pest loads Pest.php before all files).
|
| Phase 2: the re-keyed policies (2026_08_13_100200) read ONLY the
| Supabase-compatible `request.jwt.claims` GUC. The legacy `app.*` GUCs are
| still written by these helpers (and by DatabaseTenantContext) so the full
| matrix stays live, but the claims JSON — rebuilt from the live
| transaction-local GUCs so a partial context update never leaves stale
| sibling claims behind — is the only source the policies consume.
*/

/**
 * The dedicated least-privilege connection (config/database.php 'pgsql_rls').
 */
function rlsConn(): ConnectionInterface
{
    return DB::connection('pgsql_rls');
}

/**
 * Set a transaction-local RLS context value on the app-role connection:
 * writes the legacy app.* GUC AND mirrors it into request.jwt.claims.
 */
function rlsSet(ConnectionInterface $c, string $name, ?string $value): void
{
    $c->statement('select set_config(?, ?, true)', ['app.'.$name, $value ?? '']);

    $claims = [];
    foreach (['user_id', 'tenant_id', 'facility_id', 'branch_id', 'is_platform'] as $key) {
        $claims['app_'.$key] = (string) $c->selectOne('select current_setting(?, true) as value', ['app.'.$key])->value;
    }
    $c->statement('select set_config(?, ?, true)', ['request.jwt.claims', json_encode($claims)]);
}

/**
 * Set ONLY the claims GUC (request.jwt.claims) — the app.* GUCs stay
 * untouched so the claims-only suites prove the policies depend exclusively
 * on the claims payload.
 */
function claimsSet(ConnectionInterface $c, array $values): void
{
    $claims = array_merge([
        'app_user_id' => '',
        'app_tenant_id' => '',
        'app_facility_id' => '',
        'app_branch_id' => '',
        'app_is_platform' => '',
    ], $values);

    $c->statement('select set_config(?, ?, true)', ['request.jwt.claims', json_encode($claims)]);
}

/**
 * Run $fn inside a transaction on the app-role connection and ALWAYS roll
 * back (including on assertion failure) so the test database stays clean.
 */
function rlsTx(ConnectionInterface $c, callable $fn): mixed
{
    $c->beginTransaction();

    try {
        return $fn($c);
    } finally {
        $c->rollBack();
    }
}

/**
 * Two tenants (A/B) with one facility each, returned as uuids. Inserted
 * through the app-role connection under RLS (organizations is un-scoped;
 * facilities' INSERT policy is WITH CHECK true).
 *
 * @return array{tenantA: string, tenantB: string, facilityA: string, facilityB: string}
 */
function claimsTenants(ConnectionInterface $c): array
{
    $t = [
        'tenantA' => (string) Str::uuid(),
        'tenantB' => (string) Str::uuid(),
        'facilityA' => (string) Str::uuid(),
        'facilityB' => (string) Str::uuid(),
    ];

    $suffix = substr((string) Str::uuid(), 0, 8);

    // organizations INSERT requires is_platform (SECURITY.md §16 RLS reconciliation)
    claimsSet($c, ['app_is_platform' => 'true']);
    foreach (['tenantA', 'tenantB'] as $tenant) {
        $c->insert(
            'insert into organizations (id, name, code, status) values (?, ?, ?, ?)',
            [$t[$tenant], 'Tenant '.$tenant, 'code-'.$suffix.'-'.strtolower($tenant), 'active']
        );
    }
    claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);

    foreach (['facilityA', 'facilityB'] as $key) {
        $tenant = $key === 'facilityA' ? 'tenantA' : 'tenantB';
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$t[$key], $t[$tenant], 'Facility '.$key, 'code-'.strtolower($key), 'active', 'UTC', '{}', '{}']
        );
    }

    return $t;
}
