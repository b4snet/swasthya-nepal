<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 2 — Supabase-native RLS verification.
 *
 * These tests connect as the APPLICATION role (swasthya_app — no ownership,
 * no BYPASSRLS) on the dedicated least-privilege connection and set ONLY the
 * Supabase-compatible `request.jwt.claims` GUC — never the legacy `app.*`
 * GUCs — proving the re-keyed policies (2026_08_13_100200) enforce the same
 * isolation guarantees purely from the claims payload.
 *
 * The feature suite runs as the schema owner (RLS bypassed), so this file and
 * DatabaseRowLevelSecurityTest are the only places the policies are actually
 * exercised end-to-end. Every test runs in a transaction on the app-role
 * connection and rolls back in all paths: no fixtures leak.
 */
it('re-keys every RLS policy to the claims helpers (144 policies, zero GUC references)', function () {
    $policies = DB::connection('pgsql')->select(
        <<<'SQL'
        select count(*) as total,
               count(*) filter (where not (qual = 'true' or with_check = 'true')
                                  and (coalesce(qual, '') || coalesce(with_check, '')) not like '%swasthya_rls_%') as not_claims,
               count(*) filter (where (coalesce(qual, '') || coalesce(with_check, '')) like '%current_setting(''app.%') as still_guc
        from pg_policies
        where schemaname = 'public'
        SQL
    )[0];

    expect((int) $policies->total)->toBe(144)
        ->and((int) $policies->not_claims)->toBe(0)
        ->and((int) $policies->still_guc)->toBe(0);

    // The six claim helper functions exist (and are executable by the
    // application role — proven by every test below running as swasthya_app).
    $functions = DB::connection('pgsql')->select(
        "select proname from pg_proc where pronamespace = 'public'::regnamespace and proname like 'swasthya_rls_%' order by 1"
    );

    expect(array_column($functions, 'proname'))->toBe([
        'swasthya_rls_branch_id', 'swasthya_rls_claim', 'swasthya_rls_facility_id',
        'swasthya_rls_is_platform', 'swasthya_rls_tenant_id', 'swasthya_rls_user_id',
    ]);
});

it('keeps the RLS matrix intact: 37 scoped on, 13 off, none on-without-policies', function () {
    $matrix = DB::connection('pgsql')->selectOne(
        <<<'SQL'
        select count(*) filter (where relrowsecurity) as rls_on,
               count(*) filter (where not relrowsecurity) as rls_off,
               count(*) filter (where relrowsecurity and not exists (
                   select 1 from pg_policies p where p.schemaname = 'public' and p.tablename = c.relname
               )) as on_without_policies
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind = 'r'
        SQL
    );

    expect((int) $matrix->rls_on)->toBe(37)
        ->and((int) $matrix->rls_off)->toBe(13)
        ->and((int) $matrix->on_without_policies)->toBe(0);
});

it('grants zero access when claims are absent or empty', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], 'MRN-EMPTY', 'Empty Claims', '1990-01-01', 'female', 'active']
        );

        // All-empty claims JSON.
        claimsSet($c, []);
        expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(0);

        // Completely absent claims GUC (never set in this transaction).
        $c->statement("select set_config('request.jwt.claims', '', true)");
        expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(0);
    });
});

it('denies cross-tenant access purely from claims (read, update, delete)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $patientA = (string) Str::uuid();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-CA', 'Claims Tenant A', '1990-01-01', 'female', 'active']
        );

        // Tenant A claims → visible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->not->toBeNull();

        // Tenant B claims → invisible; update and delete affect zero rows.
        claimsSet($c, ['app_tenant_id' => $t['tenantB'], 'app_facility_id' => $t['facilityB']]);
        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->toBeNull()
            ->and($c->update('update patients set status = ? where id = ?', ['merged', $patientA]))->toBe(0)
            ->and($c->delete('delete from patients where id = ?', [$patientA]))->toBe(0);

        // Row untouched.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select status from patients where id = ?', [$patientA])->status)->toBe('active');
    });
});

it('enforces facility isolation from claims within a tenant', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $facilityA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facilityA2, $t['tenantA'], 'Facility A-2', 'fac-a2-claims', 'active', 'UTC', '{}', '{}']
        );

        $patient = (string) Str::uuid();
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-FA', 'Facility A', '1985-05-05', 'male', 'active']
        );

        // Facility A claims → visible.
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->not->toBeNull();

        // Facility A-2 claims (same tenant) → invisible.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $facilityA2]);
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->toBeNull();

        // Org-wide claims (no facility) → sees all facilities of the tenant.
        claimsSet($c, ['app_tenant_id' => $t['tenantA']]);
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->not->toBeNull();
    });
});

it('enforces branch isolation from claims on branch-scoped catalogs', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $branch1 = (string) Str::uuid();
        $branch2 = (string) Str::uuid();
        $department = (string) Str::uuid();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        foreach ([$branch1, $branch2] as $branch) {
            $c->insert(
                'insert into branches (id, tenant_id, facility_id, name, code, status) values (?, ?, ?, ?, ?, ?)',
                [$branch, $t['tenantA'], $t['facilityA'], 'Branch '.$branch, 'br-'.substr($branch, 0, 6), 'active']
            );
        }
        $c->insert(
            'insert into departments (id, tenant_id, facility_id, branch_id, name, code, status) values (?, ?, ?, ?, ?, ?, ?)',
            [$department, $t['tenantA'], $t['facilityA'], $branch1, 'Cardiology', 'cardiology', 'active']
        );

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA'], 'app_branch_id' => $branch1]);
        expect($c->selectOne('select id from departments where id = ?', [$department]))->not->toBeNull();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA'], 'app_branch_id' => $branch2]);
        expect($c->selectOne('select id from departments where id = ?', [$department]))->toBeNull();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from departments where id = ?', [$department]))->not->toBeNull();
    });
});

it('separates platform and tenant audit rows from claims', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $tenantEvent = (string) Str::uuid();
        $platformEvent = (string) Str::uuid();

        $insertEvent = fn (string $id, ?string $tenantId, ?string $facilityId): int => $c->insert(
            'insert into audit_events (id, tenant_id, occurred_at, actor_type, actor_id, actor_email, action, resource_type, resource_id, facility_id, payload, ip_address, correlation_id, prev_hash, event_hash) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, '2026-08-11 00:00:00+00', 'user', null, null, 'test.action', 'test', null, $facilityId, '{}', null, (string) Str::uuid(), null, hash('sha256', $id)]
        );

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA'], 'app_is_platform' => 'false']);
        $insertEvent($tenantEvent, $t['tenantA'], $t['facilityA']);

        claimsSet($c, ['app_is_platform' => 'true']);
        $insertEvent($platformEvent, null, null);

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA'], 'app_is_platform' => 'false']);
        expect($c->selectOne('select id from audit_events where id = ?', [$tenantEvent]))->not->toBeNull()
            ->and($c->selectOne('select id from audit_events where id = ?', [$platformEvent]))->toBeNull();

        claimsSet($c, ['app_is_platform' => 'true']);
        expect($c->selectOne('select id from audit_events where id = ?', [$platformEvent]))->not->toBeNull()
            ->and($c->selectOne('select id from audit_events where id = ?', [$tenantEvent]))->toBeNull();

        // Append-only holds from claims context.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA'], 'app_is_platform' => 'false']);
        expect($c->update("update audit_events set payload = '{}' where id = ?", [$tenantEvent]))->toBe(0)
            ->and($c->delete('delete from audit_events where id = ?', [$tenantEvent]))->toBe(0);
    });
});

it('lets a principal resolve its own assignments and assigned facilities with only the user claim', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $userA = (string) Str::uuid();
        $userB = (string) Str::uuid();
        $role = (string) Str::uuid();
        $assignmentA = (string) Str::uuid();

        // A second facility the user is NOT assigned to.
        $facilityA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facilityA2, $t['tenantA'], 'Facility A-2', 'fac-a2-user', 'active', 'UTC', '{}', '{}']
        );

        $c->insert('insert into users (id, email, password_hash, status) values (?, ?, ?, ?)', [$userA, 'claims-a@two.test', 'hash', 'active']);
        $c->insert('insert into users (id, email, password_hash, status) values (?, ?, ?, ?)', [$userB, 'claims-b@two.test', 'hash', 'active']);
        $c->insert('insert into roles (id, code, name, scope_type, is_system) values (?, ?, ?, ?, ?)', [$role, 'hospital_admin', 'Hospital Admin', 'facility', true]);
        $c->insert(
            'insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$assignmentA, $userA, $role, $t['tenantA'], $t['facilityA'], null, 'facility', 'active', '2026-08-11 00:00:00+00']
        );
        $c->insert(
            'insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid(), $userB, $role, $t['tenantA'], $t['facilityA'], null, 'facility', 'active', '2026-08-11 00:00:00+00']
        );

        // Only the user claim (login path — no tenant/facility claims).
        claimsSet($c, ['app_user_id' => $userA]);
        expect($c->selectOne('select id, user_id from role_assignments where id = ?', [$assignmentA]))->not->toBeNull()
            ->and($c->selectOne('select id, user_id from role_assignments where id = ?', [$assignmentA])->user_id)->toBe($userA);

        // The assigned facility resolves via the authorization join; the
        // unassigned facility in the same tenant does not.
        expect($c->selectOne('select id from facilities where id = ?', [$t['facilityA']]))->not->toBeNull()
            ->and($c->selectOne('select id from facilities where id = ?', [$facilityA2]))->toBeNull();

        // Without any user claim, nothing resolves (safe fail).
        claimsSet($c, []);
        expect($c->selectOne('select id from role_assignments where id = ?', [$assignmentA]))->toBeNull();
    });
});

it('keeps support sessions visible only to their owner or platform claims', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $user = (string) Str::uuid();
        $session = (string) Str::uuid();

        $c->insert('insert into users (id, email, password_hash, status) values (?, ?, ?, ?)', [$user, 'support-claims@two.test', 'hash', 'active']);

        claimsSet($c, ['app_user_id' => $user, 'app_is_platform' => 'true']);
        $c->insert(
            'insert into support_sessions (id, user_id, organization_id, facility_id, reason, status, opened_at, expires_at, correlation_id) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$session, $user, $t['tenantA'], $t['facilityA'], 'Test support session.', 'active', '2026-08-11 00:00:00+00', '2026-08-11 01:00:00+00', (string) Str::uuid()]
        );

        // A different user in tenant context cannot see it.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_user_id' => (string) Str::uuid(), 'app_is_platform' => 'false']);
        expect($c->selectOne('select id from support_sessions where id = ?', [$session]))->toBeNull();

        // Platform claims see it.
        claimsSet($c, ['app_is_platform' => 'true']);
        expect($c->selectOne('select id from support_sessions where id = ?', [$session]))->not->toBeNull();
    });
});

it('forged claims to an unknown tenant grant nothing (no broadening of visibility)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $patientA = (string) Str::uuid();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-FORGED', 'Forged', '1990-01-01', 'female', 'active']
        );

        // A forged claim naming a tenant that does not exist resolves to
        // nothing — RLS matches rows by tenant_id equality, so a fabricated
        // identifier can never widen access.
        claimsSet($c, ['app_tenant_id' => (string) Str::uuid(), 'app_facility_id' => $t['facilityA']]);
        expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(0);

        // The application role still has no RLS bypass — the claim mechanism
        // is the only way in, and it is server-issued (see class doc).
        $role = DB::connection('pgsql')->selectOne(
            'select rolbypassrls::text as bypass, rolsuper::text as superuser from pg_roles where rolname = ?',
            ['swasthya_app']
        );
        expect($role->bypass)->toBe('false')
            ->and($role->superuser)->toBe('false');
    });
});

it('keeps claims transaction-local — no context leaks across transactions', function () {
    $c = rlsConn();

    // Transaction 1: set claims and verify the row is visible. ROLLED BACK —
    // nothing may survive into the shared test database (fixtures are
    // inserted inside this same transaction, so they vanish with it).
    $c->beginTransaction();
    $t = claimsTenants($c);
    claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
    $c->insert(
        'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
        [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], 'MRN-TXL', 'Tx Local', '1990-01-01', 'female', 'active']
    );
    expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(1);
    $c->rollBack();

    // A NEW transaction on the SAME connection must start with zero context —
    // the LOCAL claims GUC died with the previous transaction. This is the
    // pooled-worker guarantee (TENANCY.md V2 §7) under the claims model.
    $c->beginTransaction();
    try {
        expect((int) $c->selectOne('select count(*) as total from patients')->total)->toBe(0);
    } finally {
        $c->rollBack();
    }
});

it('ignores legacy app.* GUCs after the re-key — claims are the only source', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = claimsTenants($c);
        $patientA = (string) Str::uuid();

        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-GUC', 'GUC Only', '1990-01-01', 'female', 'active']
        );

        // Set ONLY the legacy GUCs — no claims. The re-keyed policies must not
        // honor them: a regression guard proving the old source is dead.
        $c->statement('select set_config(?, ?, true)', ['app.tenant_id', $t['tenantA']]);
        $c->statement('select set_config(?, ?, true)', ['app.facility_id', $t['facilityA']]);
        $c->statement("select set_config('request.jwt.claims', '', true)");

        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->toBeNull();

        // Claims restore visibility.
        claimsSet($c, ['app_tenant_id' => $t['tenantA'], 'app_facility_id' => $t['facilityA']]);
        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->not->toBeNull();
    });
});
