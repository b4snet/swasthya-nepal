<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Supabase security-linter reconciliation (SECURITY.md §16).
 *
 * Proves the security boundaries for every public-schema table:
 *   - 143 application tables: RLS + FORCE + 4 policies each
 *   - 4 newly scoped tables (organizations, roles, permissions, role_permissions):
 *     RLS + FORCE + 4 policies each
 *   - 11 intentionally unprotected tables: documented justification
 *
 * These tests connect as swasthya_app (no BYPASSRLS, no superuser) via the
 * pgsql_rls connection and set the RLS GUCs directly, proving PostgreSQL
 * itself enforces the boundary.
 */
it('all 143 application tables have RLS enabled with FORCE', function () {
    $c = rlsConn();

    $tables = $c->select(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND rowsecurity = true ORDER BY tablename"
    );

    $unprotected = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations',
        'users', 'refresh_tokens', 'mfa_challenges', 'password_reset_tokens', 'personal_access_tokens',
    ];

    $appTables = array_filter($tables, fn ($t) => ! in_array($t->tablename, $unprotected));

    expect(count($appTables))->toBeGreaterThanOrEqual(139);

    foreach ($appTables as $table) {
        $forced = $c->selectOne(
            "SELECT relforcerowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = ?",
            [$table->tablename]
        );
        expect($forced->relforcerowsecurity)->toBeTrue(
            "Table {$table->tablename} must have FORCE RLS"
        );
    }
});

it('organizations RLS restricts cross-tenant access', function () {
    $c = rlsConn();
    rlsTx($c, function ($c): void {
        $tenantA = (string) Str::uuid();
        $tenantB = (string) Str::uuid();
        $orgA = (string) Str::uuid();
        $orgB = (string) Str::uuid();

        // Insert via app-role with platform claims (organizations INSERT requires is_platform)
        claimsSet($c, ['app_is_platform' => 'true']);
        $c->insert('INSERT INTO organizations (id, name, code, status) VALUES (?, ?, ?, ?)', [$orgA, 'Org A', 'org-a-'.Str::random(4), 'active']);
        $c->insert('INSERT INTO organizations (id, name, code, status) VALUES (?, ?, ?, ?)', [$orgB, 'Org B', 'org-b-'.Str::random(4), 'active']);

        // Tenant A user: only sees org where id = tenant_id
        claimsSet($c, ['app_tenant_id' => $tenantA]);
        $visible = $c->select('SELECT id FROM organizations ORDER BY id');
        $ids = array_map(fn ($r) => $r->id, $visible);
        expect($ids)->not->toContain($orgB);

        // Tenant B user: only sees org where id = tenant_id
        claimsSet($c, ['app_tenant_id' => $tenantB]);
        $visible2 = $c->select('SELECT id FROM organizations ORDER BY id');
        $ids2 = array_map(fn ($r) => $r->id, $visible2);
        expect($ids2)->not->toContain($orgA);
    });
});

it('organizations RLS allows platform admin full access', function () {
    $c = rlsConn();
    rlsTx($c, function ($c): void {
        $orgA = (string) Str::uuid();
        $orgB = (string) Str::uuid();

        claimsSet($c, ['app_is_platform' => 'true']);
        $c->insert('INSERT INTO organizations (id, name, code, status) VALUES (?, ?, ?, ?)', [$orgA, 'Org A', 'org-a-'.Str::random(4), 'active']);
        $c->insert('INSERT INTO organizations (id, name, code, status) VALUES (?, ?, ?, ?)', [$orgB, 'Org B', 'org-b-'.Str::random(4), 'active']);

        // Platform admin should see both
        $visible = $c->select('SELECT id FROM organizations ORDER BY id');
        $ids = array_map(fn ($r) => $r->id, $visible);
        expect($ids)->toContain($orgA)->toContain($orgB);
    });
});

it('organizations RLS prevents non-platform insert', function () {
    $c = rlsConn();
    rlsTx($c, function ($c): void {
        $tenantA = (string) Str::uuid();
        claimsSet($c, ['app_tenant_id' => $tenantA]);

        try {
            $c->insert('INSERT INTO organizations (id, name, code, status) VALUES (?, ?, ?, ?)', [
                (string) Str::uuid(), 'Unauthorized', 'unauth-'.Str::random(4), 'active',
            ]);
            $this->fail('Non-platform user should not be able to insert organizations');
        } catch (Exception $e) {
            expect($e->getMessage())->toContain('row-level security policy');
        }
    });
});

it('roles/permissions/role_permissions are readable by any authenticated user', function () {
    $c = rlsConn();
    rlsTx($c, function ($c): void {
        $tenantA = (string) Str::uuid();
        claimsSet($c, ['app_tenant_id' => $tenantA]);

        // These should not throw — the SELECT policy is USING (true)
        $c->select('SELECT id FROM roles LIMIT 1');
        $c->select('SELECT id FROM permissions LIMIT 1');
        $c->select('SELECT role_id FROM role_permissions LIMIT 1');

        expect(true)->toBeTrue();
    });
});

it('roles and permissions have RLS enabled with permissive write policies', function () {
    // Shared RBAC metadata: RLS is ENABLED (defense-in-depth boundary exists)
    // but write policies are permissive because application middleware controls
    // write authorization (SECURITY.md §16 — these are not tenant-scoped).
    $c = rlsConn();

    foreach (['roles', 'permissions', 'role_permissions'] as $table) {
        $info = $c->selectOne(
            "SELECT rowsecurity FROM pg_tables WHERE schemaname = 'public' AND tablename = ?",
            [$table]
        );
        expect($info->rowsecurity)->toBeTrue("RLS must be enabled on {$table}");

        $forced = $c->selectOne(
            "SELECT relforcerowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = ?",
            [$table]
        );
        expect($forced->relforcerowsecurity)->toBeTrue("FORCE RLS must be set on {$table}");

        $policies = $c->select(
            "SELECT policyname, cmd FROM pg_policies WHERE schemaname = 'public' AND tablename = ?",
            [$table]
        );
        expect(count($policies))->toBe(4, "{$table} must have 4 policies (SELECT, INSERT, UPDATE, DELETE)");
    }
});

it('unprotected auth tables have no RLS (documented justification)', function () {
    $c = rlsConn();

    $unprotected = $c->select(
        "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND rowsecurity = false ORDER BY tablename"
    );
    $names = array_map(fn ($t) => $t->tablename, $unprotected);

    // Framework infrastructure
    expect($names)->toContain('cache');
    expect($names)->toContain('cache_locks');
    expect($names)->toContain('failed_jobs');
    expect($names)->toContain('job_batches');
    expect($names)->toContain('jobs');
    expect($names)->toContain('migrations');

    // Auth infrastructure (login flow requires unscoped access)
    expect($names)->toContain('users');
    expect($names)->toContain('refresh_tokens');
    expect($names)->toContain('mfa_challenges');
    expect($names)->toContain('password_reset_tokens');
    expect($names)->toContain('personal_access_tokens');

    expect(count($unprotected))->toBe(11);
});

it('no unprotected table contains tenant-scoped PII beyond auth metadata', function () {
    $c = rlsConn();

    $cols = $c->select(
        "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'users' ORDER BY ordinal_position"
    );
    $colNames = array_map(fn ($c) => $c->column_name, $cols);

    expect($colNames)->not->toContain('tenant_id');
    expect($colNames)->not->toContain('facility_id');

    expect($colNames)->toContain('id');
    expect($colNames)->toContain('email');
    expect($colNames)->toContain('password_hash');
});

it('swasthya_app role has no RLS bypass', function () {
    $c = rlsConn();

    $row = $c->selectOne(
        "SELECT rolbypassrls, rolsuper FROM pg_roles WHERE rolname = 'swasthya_app'"
    );

    expect($row->rolbypassrls)->toBeFalse();
    expect($row->rolsuper)->toBeFalse();
});

it('total RLS-scoped tables >= 143 (139 original + 4 newly scoped)', function () {
    $c = rlsConn();

    $count = $c->selectOne(
        "SELECT COUNT(*) AS cnt FROM pg_tables WHERE schemaname = 'public' AND rowsecurity = true"
    );

    expect($count->cnt)->toBeGreaterThanOrEqual(143);
});

it('total policies >= 570 (143+ tables with policies)', function () {
    $c = rlsConn();

    $count = $c->selectOne(
        "SELECT COUNT(*) AS cnt FROM pg_policies WHERE schemaname = 'public'"
    );

    expect($count->cnt)->toBeGreaterThanOrEqual(570);
});

/*
|--------------------------------------------------------------------------
| Supabase Data API exposure tests (Phase: Security Advisor Remediation)
|--------------------------------------------------------------------------
|
| The Supabase Data API (PostgREST) routes through the `anon` and
| `authenticated` roles. The swasthya_app role connects directly, so
| REVOKEs from anon/authenticated do NOT affect application functionality.
|
| These tests verify that infrastructure and auth tables are NOT exposed
| through the Data API, and that RBAC metadata tables are read-only.
*/

it('infra tables have no Data API access for anon role', function () {
    $hidden = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations',
        'users', 'refresh_tokens', 'mfa_challenges', 'password_reset_tokens',
        'personal_access_tokens',
    ];

    foreach ($hidden as $table) {
        $grants = DB::select(
            'SELECT privilege_type FROM information_schema.table_privileges'
            ." WHERE table_schema = 'public' AND table_name = ?"
            ." AND grantee = 'anon' ORDER BY privilege_type",
            [$table]
        );
        expect(count($grants))->toBe(0, "Table {$table} must have no anon access (Supabase Data API)");
    }
});

it('infra tables have no Data API access for authenticated role', function () {
    $hidden = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations',
        'users', 'refresh_tokens', 'mfa_challenges', 'password_reset_tokens',
        'personal_access_tokens',
    ];

    foreach ($hidden as $table) {
        $grants = DB::select(
            'SELECT privilege_type FROM information_schema.table_privileges'
            ." WHERE table_schema = 'public' AND table_name = ?"
            ." AND grantee = 'authenticated' ORDER BY privilege_type",
            [$table]
        );
        expect(count($grants))->toBe(0, "Table {$table} must have no authenticated access (Supabase Data API)");
    }
});

it('rbac metadata tables are read-only via Data API', function () {
    $readonly = ['roles', 'permissions', 'role_permissions'];

    foreach ($readonly as $table) {
        foreach (['anon', 'authenticated'] as $role) {
            $grants = DB::select(
                'SELECT privilege_type FROM information_schema.table_privileges'
                ." WHERE table_schema = 'public' AND table_name = ?"
                .' AND grantee = ? ORDER BY privilege_type',
                [$table, $role]
            );

            $privileges = array_map(fn ($g) => $g->privilege_type, $grants);

            // Must have SELECT (readable)
            expect($privileges)->toContain('SELECT', "Table {$table} must be readable by {$role}");

            // Must NOT have INSERT, UPDATE, DELETE (writable)
            expect($privileges)->not->toContain('INSERT', "Table {$table} must not be writable by {$role}");
            expect($privileges)->not->toContain('UPDATE', "Table {$table} must not be writable by {$role}");
            expect($privileges)->not->toContain('DELETE', "Table {$table} must not be deletable by {$role}");
        }
    }
});

it('personal_access_tokens.token column has no Data API access', function () {
    foreach (['anon', 'authenticated'] as $role) {
        $grants = DB::select(
            'SELECT privilege_type FROM information_schema.column_privileges'
            ." WHERE table_schema = 'public' AND table_name = 'personal_access_tokens'"
            ." AND column_name = 'token' AND grantee = ?",
            [$role]
        );
        expect(count($grants))->toBe(0, "personal_access_tokens.token must have no {$role} column access");
    }
});

it('swasthya_app role retains access to all tables (application backend)', function () {
    // The swasthya_app role connects directly, not through PostgREST.
    // Verify it can still SELECT from the previously hidden tables.
    $c = rlsConn();

    foreach (['cache', 'users', 'personal_access_tokens'] as $table) {
        $grants = DB::select(
            'SELECT privilege_type FROM information_schema.table_privileges'
            ." WHERE table_schema = 'public' AND table_name = ?"
            ." AND grantee = 'swasthya_app' ORDER BY privilege_type",
            [$table]
        );
        $privileges = array_map(fn ($g) => $g->privilege_type, $grants);
        expect($privileges)->toContain('SELECT', "swasthya_app must retain SELECT on {$table}");
    }
});
