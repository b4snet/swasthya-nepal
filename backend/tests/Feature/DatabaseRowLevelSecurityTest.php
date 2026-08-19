<?php

use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Database-layer tenancy enforcement (TENANCY.md V2 §10, SECURITY.md §14).
 *
 * These tests connect as the APPLICATION role (swasthya_app — no ownership,
 * no BYPASSRLS) on a dedicated connection and set the RLS GUCs directly,
 * proving PostgreSQL itself isolates rows — independent of the application
 * authorization layer. The feature tests run as the schema owner (RLS is
 * bypassed for the owner), so this suite is the ONLY place the policies are
 * actually exercised end-to-end.
 *
 * Every test runs inside its own transaction on the app-role connection and
 * rolls back in all paths: no fixtures leak into the shared test database.
 */

/**
 * @return array{tenantA: string, tenantB: string, facilityA: string, facilityB: string}
 */
function rlsTenants(ConnectionInterface $c): array
{
    $tenants = [
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
            [$tenants[$tenant], 'Tenant '.$tenant, 'code-'.$suffix.'-'.strtolower($tenant), 'active']
        );
    }
    // Clear platform claim — subsequent inserts use normal tenant context
    claimsSet($c, ['app_tenant_id' => $tenants['tenantA'], 'app_facility_id' => $tenants['facilityA']]);

    foreach (['facilityA', 'facilityB'] as $key) {
        $tenant = $key === 'facilityA' ? 'tenantA' : 'tenantB';
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenants[$key], $tenants[$tenant], 'Facility '.$key, 'code-'.strtolower($key), 'active', 'UTC', '{}', '{}']
        );
    }

    return $tenants;
}

it('orders tenant-context middleware before route model binding', function () {
    // Regression (Tenancy V2): Laravel's implicit route binding
    // (SubstituteBindings lives in the framework 'api' group) used to run
    // BEFORE ResolveTenantContext, so every route-bound tenant-scoped model
    // (departments, staff, patients, …) was queried with empty RLS GUCs and
    // 404'd under the application role. bootstrap/app.php raises
    // ResolveTenantContext above SubstituteBindings in the middleware
    // priority; the feature suite (schema owner, RLS bypassed) cannot see
    // the failure, so this asserts the ordering structurally.
    $router = app(Router::class);

    $resolveIndex = array_search(ResolveTenantContext::class, $router->middlewarePriority, true);
    $bindingsIndex = array_search(SubstituteBindings::class, $router->middlewarePriority, true);

    expect($resolveIndex)->not->toBeFalse()
        ->and($bindingsIndex)->not->toBeFalse()
        ->and($resolveIndex)->toBeLessThan($bindingsIndex);
});

it('the application role has no RLS bypass and no superuser privileges', function () {
    $row = DB::connection('pgsql')->selectOne(
        'select rolbypassrls::text as bypass, rolsuper::text as superuser from pg_roles where rolname = ?',
        ['swasthya_app']
    );

    expect($row)->not->toBeNull()
        ->and($row->bypass)->toBe('false')
        ->and($row->superuser)->toBe('false');
});

it('denies cross-tenant reads, updates, and deletes at the database level', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = rlsTenants($c);
        $patientA = (string) Str::uuid();

        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);

        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-A', 'Patient A', '1990-01-01', 'female', 'active']
        );

        // Tenant A sees its own patient.
        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);
        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->not->toBeNull();

        // Tenant B: read → invisible.
        rlsSet($c, 'tenant_id', $t['tenantB']);
        rlsSet($c, 'facility_id', $t['facilityB']);
        expect($c->selectOne('select id from patients where id = ?', [$patientA]))->toBeNull();

        // Tenant B: update → 0 rows affected.
        expect($c->update('update patients set status = ? where id = ?', ['merged', $patientA]))->toBe(0);

        // Tenant B: delete → 0 rows affected.
        expect($c->delete('delete from patients where id = ?', [$patientA]))->toBe(0);

        // The row is untouched.
        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);
        expect($c->selectOne('select status from patients where id = ?', [$patientA])->status)->toBe('active');
    });
});

it('blocks tenant escape and facility escape through UPDATE (WITH CHECK)', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = rlsTenants($c);
        // A second facility inside tenant A (the facility-escape target).
        $facilityA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facilityA2, $t['tenantA'], 'Facility A-2', 'fac-a2-'.substr((string) Str::uuid(), 0, 6), 'active', 'UTC', '{}', '{}']
        );

        $patientA = (string) Str::uuid();

        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);

        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patientA, $t['tenantA'], $t['facilityA'], 'MRN-A', 'Patient A', '1990-01-01', 'female', 'active']
        );

        // An org-wide context (no facility GUC) may still update the row.
        rlsSet($c, 'facility_id', '');
        expect($c->update("update patients set status = 'archived' where id = ?", [$patientA]))->toBe(1);

        // Rewriting the row into another TENANT is structurally impossible:
        // the WITH CHECK policy rejects the new row outright. (These are the
        // last statements: a WITH CHECK violation aborts the transaction.)
        rlsSet($c, 'facility_id', $t['facilityA']);
        expect(fn () => $c->update('update patients set tenant_id = ? where id = ?', [$t['tenantB'], $patientA]))
            ->toThrow(QueryException::class);

        // Rewriting the row into another FACILITY of the same tenant is also
        // rejected in a facility-scoped context (cross-facility moves are
        // org-level decisions).
        expect(fn () => $c->update('update patients set facility_id = ? where id = ?', [$facilityA2, $patientA]))
            ->toThrow(QueryException::class);
    });
});

it('enforces facility isolation within a tenant', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = rlsTenants($c);
        // A second facility inside tenant A.
        $facilityA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facilityA2, $t['tenantA'], 'Facility A-2', 'fac-a2', 'active', 'UTC', '{}', '{}']
        );

        $patient = (string) Str::uuid();
        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$patient, $t['tenantA'], $t['facilityA'], 'MRN-A1', 'Facility A Patient', '1985-05-05', 'male', 'active']
        );

        // Facility A context sees it.
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->not->toBeNull();

        // Facility A-2 context (same tenant!) does not.
        rlsSet($c, 'facility_id', $facilityA2);
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->toBeNull();

        // Org-wide context (no facility) sees every facility of the tenant.
        rlsSet($c, 'facility_id', '');
        expect($c->selectOne('select id from patients where id = ?', [$patient]))->not->toBeNull();
    });
});

it('enforces branch isolation on branch-scoped catalog tables', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = rlsTenants($c);
        $branch1 = (string) Str::uuid();
        $branch2 = (string) Str::uuid();
        $department = (string) Str::uuid();

        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);

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

        // Branch 1 context sees the department.
        rlsSet($c, 'branch_id', $branch1);
        expect($c->selectOne('select id from departments where id = ?', [$department]))->not->toBeNull();

        // Branch 2 context does not.
        rlsSet($c, 'branch_id', $branch2);
        expect($c->selectOne('select id from departments where id = ?', [$department]))->toBeNull();

        // No branch context sees all branches.
        rlsSet($c, 'branch_id', '');
        expect($c->selectOne('select id from departments where id = ?', [$department]))->not->toBeNull();
    });
});

it('fails safe with no tenant context', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = rlsTenants($c);

        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);
        $c->insert(
            'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid(), $t['tenantA'], $t['facilityA'], 'MRN-NC', 'No Context', '2000-01-01', 'female', 'active']
        );

        // No GUCs at all: the row is invisible — a safe failure, never a leak.
        rlsSet($c, 'tenant_id', '');
        rlsSet($c, 'facility_id', '');
        $count = $c->selectOne('select count(*) as total from patients')?->total;

        expect((int) $count)->toBe(0);
    });
});

it('keeps the audit trail append-only and splits platform vs tenant rows', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = rlsTenants($c);
        $tenantEvent = (string) Str::uuid();
        $platformEvent = (string) Str::uuid();
        $correlation = (string) Str::uuid();

        $insertEvent = fn (string $id, ?string $tenantId, ?string $facilityId): int => $c->insert(
            'insert into audit_events (id, tenant_id, occurred_at, actor_type, actor_id, actor_email, action, resource_type, resource_id, facility_id, payload, ip_address, correlation_id, prev_hash, event_hash) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $tenantId, '2026-08-11 00:00:00+00', 'user', null, null, 'test.action', 'test', null, $facilityId, '{}', null, $correlation, null, hash('sha256', $id)]
        );

        // Tenant context writes its own event.
        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);
        $insertEvent($tenantEvent, $t['tenantA'], $t['facilityA']);

        // Platform context writes a platform event (tenant_id NULL).
        rlsSet($c, 'tenant_id', '');
        rlsSet($c, 'facility_id', '');
        rlsSet($c, 'is_platform', 'true');
        $insertEvent($platformEvent, null, null);

        // Tenant A sees its own event, not the platform event.
        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);
        rlsSet($c, 'is_platform', 'false');
        expect($c->selectOne('select id from audit_events where id = ?', [$tenantEvent]))->not->toBeNull()
            ->and($c->selectOne('select id from audit_events where id = ?', [$platformEvent]))->toBeNull();

        // Platform context sees platform events only — never tenant audit.
        rlsSet($c, 'tenant_id', '');
        rlsSet($c, 'facility_id', '');
        rlsSet($c, 'is_platform', 'true');
        expect($c->selectOne('select id from audit_events where id = ?', [$platformEvent]))->not->toBeNull()
            ->and($c->selectOne('select id from audit_events where id = ?', [$tenantEvent]))->toBeNull();

        // Append-only: the application role cannot update or delete audit rows.
        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'facility_id', $t['facilityA']);
        rlsSet($c, 'is_platform', 'false');
        expect($c->update("update audit_events set payload = '{}' where id = ?", [$tenantEvent]))->toBe(0)
            ->and($c->delete('delete from audit_events where id = ?', [$tenantEvent]))->toBe(0);
    });
});

it('separates platform and tenant rows in role_assignments', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = rlsTenants($c);
        $user = (string) Str::uuid();
        $platformRole = (string) Str::uuid();
        $tenantRole = (string) Str::uuid();
        $platformAssignment = (string) Str::uuid();
        $tenantAssignment = (string) Str::uuid();

        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$user, 'rls@two.test', 'hash', 'active']
        );
        $c->insert(
            'insert into roles (id, code, name, scope_type, is_system) values (?, ?, ?, ?, ?)',
            [$platformRole, 'superadmin', 'Superadmin', 'platform', true]
        );
        $c->insert(
            'insert into roles (id, code, name, scope_type, is_system) values (?, ?, ?, ?, ?)',
            [$tenantRole, 'org_admin', 'Org Admin', 'organization', true]
        );

        // Platform assignment (tenant NULL), inserted from platform context.
        rlsSet($c, 'tenant_id', '');
        rlsSet($c, 'is_platform', 'true');
        $c->insert(
            'insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$platformAssignment, $user, $platformRole, null, null, null, 'platform', 'active', '2026-08-11 00:00:00+00']
        );

        // Tenant assignment, from tenant context.
        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'is_platform', 'false');
        $c->insert(
            'insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantAssignment, $user, $tenantRole, $t['tenantA'], null, null, 'organization', 'active', '2026-08-11 00:00:00+00']
        );

        // Tenant context: sees its own assignment, not the platform one.
        expect($c->selectOne('select id from role_assignments where id = ?', [$tenantAssignment]))->not->toBeNull()
            ->and($c->selectOne('select id from role_assignments where id = ?', [$platformAssignment]))->toBeNull();

        // Platform context: sees platform rows, not tenant rows.
        rlsSet($c, 'tenant_id', '');
        rlsSet($c, 'is_platform', 'true');
        expect($c->selectOne('select id from role_assignments where id = ?', [$platformAssignment]))->not->toBeNull()
            ->and($c->selectOne('select id from role_assignments where id = ?', [$tenantAssignment]))->toBeNull();
    });
});

it('keeps two concurrent connections with different tenants isolated', function () {
    // Two genuinely independent PDO connections (raw PDOs — the Laravel
    // connection singleton cannot hold two simultaneously), each with its own
    // transaction and its own tenant context, like real pooled workers.
    $config = config('database.connections.pgsql_rls');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

    $newPdo = function () use ($dsn, $config): PDO {
        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    };

    $pdo1 = $newPdo();
    $pdo2 = $newPdo();

    $run = function (PDO $pdo, string $sql, array $params = []): void {
        $pdo->prepare($sql)->execute($params);
    };
    $one = function (PDO $pdo, string $sql, array $params = []): ?object {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_OBJ);

        return $row === false ? null : $row;
    };
    $set = function (PDO $pdo, string $name, ?string $value) use ($run, $one): void {
        $run($pdo, 'select set_config(?, ?, true)', ['app.'.$name, $value ?? '']);

        $claims = [];
        foreach (['user_id', 'tenant_id', 'facility_id', 'branch_id', 'is_platform'] as $key) {
            $row = $one($pdo, 'select current_setting(?, true) as value', ['app.'.$key]);
            $claims['app_'.$key] = (string) ($row->value ?? '');
        }
        $run($pdo, 'select set_config(?, ?, true)', ['request.jwt.claims', json_encode($claims)]);
    };
    $tenant = function (PDO $pdo, string $label) use ($run, $set): array {
        $org = (string) Str::uuid();
        $facility = (string) Str::uuid();
        // organizations INSERT requires is_platform (SECURITY.md §16 RLS reconciliation)
        $set($pdo, 'is_platform', 'true');
        $run($pdo, 'insert into organizations (id, name, code, status) values (?, ?, ?, ?)', [$org, 'Org '.$label, 'code-'.$label.'-'.substr((string) Str::uuid(), 0, 8), 'active']);
        $set($pdo, 'tenant_id', $org);
        $set($pdo, 'facility_id', null);
        $run($pdo, 'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)', [$facility, $org, 'Fac '.$label, 'fac-'.$label, 'active', 'UTC', '{}', '{}']);

        return ['org' => $org, 'facility' => $facility];
    };

    $pdo1->beginTransaction();
    $pdo2->beginTransaction();

    try {
        $t1 = $tenant($pdo1, 'one');
        $t2 = $tenant($pdo2, 'two');
        $patient1 = (string) Str::uuid();
        $patient2 = (string) Str::uuid();

        $set($pdo1, 'tenant_id', $t1['org']);
        $set($pdo1, 'facility_id', $t1['facility']);
        $run($pdo1, 'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient1, $t1['org'], $t1['facility'], 'MRN-C1', 'Conn 1 Patient', '1990-01-01', 'female', 'active']);

        $set($pdo2, 'tenant_id', $t2['org']);
        $set($pdo2, 'facility_id', $t2['facility']);
        $run($pdo2, 'insert into patients (id, tenant_id, facility_id, mrn, full_name, date_of_birth, sex, status) values (?, ?, ?, ?, ?, ?, ?, ?)', [$patient2, $t2['org'], $t2['facility'], 'MRN-C2', 'Conn 2 Patient', '1991-02-02', 'male', 'active']);

        // Each connection sees exactly its own tenant — no context leakage
        // between pooled/reused connections.
        expect($one($pdo1, 'select id from patients where id = ?', [$patient1]))->not->toBeNull()
            ->and($one($pdo1, 'select id from patients where id = ?', [$patient2]))->toBeNull();

        expect($one($pdo2, 'select id from patients where id = ?', [$patient2]))->not->toBeNull()
            ->and($one($pdo2, 'select id from patients where id = ?', [$patient1]))->toBeNull();
    } finally {
        $pdo1->rollBack();
        $pdo2->rollBack();
    }
});

it('lets a principal resolve its own role assignments with only app.user_id set (login)', function () {
    // Regression: login is a public route — no tenant-context middleware, no
    // tenant GUC. The RLS policy must still let the principal read ITS OWN
    // role_assignments via app.user_id, or the login payload (facility
    // picker, audit tenant resolution) silently degrades to empty.
    rlsTx(rlsConn(), function ($c): void {
        $t = rlsTenants($c);
        $userA = (string) Str::uuid();
        $userB = (string) Str::uuid();
        $role = (string) Str::uuid();
        $assignmentA = (string) Str::uuid();
        $assignmentB = (string) Str::uuid();

        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$userA, 'login-a@two.test', 'hash', 'active']
        );
        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$userB, 'login-b@two.test', 'hash', 'active']
        );
        $c->insert(
            'insert into roles (id, code, name, scope_type, is_system) values (?, ?, ?, ?, ?)',
            [$role, 'hospital_admin', 'Hospital Admin', 'facility', true]
        );

        $c->insert(
            'insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$assignmentA, $userA, $role, $t['tenantA'], $t['facilityA'], null, 'facility', 'active', '2026-08-11 00:00:00+00']
        );
        $c->insert(
            'insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$assignmentB, $userB, $role, $t['tenantA'], $t['facilityA'], null, 'facility', 'active', '2026-08-11 00:00:00+00']
        );

        // User A, with ONLY app.user_id set (no tenant/facility GUC — login):
        // sees its own assignment, never user B's.
        rlsSet($c, 'user_id', $userA);
        rlsSet($c, 'tenant_id', '');
        rlsSet($c, 'facility_id', '');
        rlsSet($c, 'is_platform', 'false');

        expect($c->selectOne('select id from role_assignments where id = ?', [$assignmentA]))->not->toBeNull()
            ->and($c->selectOne('select id from role_assignments where id = ?', [$assignmentB]))->toBeNull();

        // Without ANY user context, no assignments are visible (safe fail).
        rlsSet($c, 'user_id', '');
        expect($c->selectOne('select id from role_assignments where id = ?', [$assignmentA]))->toBeNull();
    });
});

it('lets a principal resolve facilities it is assigned to with only app.user_id set (login picker)', function () {
    // Regression: the login payload eager-loads the facility relation for
    // each assignment. Facilities are tenant-scoped, but with no tenant GUC
    // (login) the authorization join must still expose exactly the
    // facilities the principal is assigned to — never others.
    rlsTx(rlsConn(), function ($c): void {
        $t = rlsTenants($c);
        // A second facility inside tenant A that the user is NOT assigned to.
        $facilityA2 = (string) Str::uuid();
        $c->insert(
            'insert into facilities (id, tenant_id, name, code, status, timezone, address, settings) values (?, ?, ?, ?, ?, ?, ?, ?)',
            [$facilityA2, $t['tenantA'], 'Facility A-2', 'fac-a2-login', 'active', 'UTC', '{}', '{}']
        );

        $userA = (string) Str::uuid();
        $role = (string) Str::uuid();

        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$userA, 'picker@two.test', 'hash', 'active']
        );
        $c->insert(
            'insert into roles (id, code, name, scope_type, is_system) values (?, ?, ?, ?, ?)',
            [$role, 'hospital_admin', 'Hospital Admin', 'facility', true]
        );
        $c->insert(
            'insert into role_assignments (id, user_id, role_id, tenant_id, facility_id, branch_id, scope_type, status, granted_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid(), $userA, $role, $t['tenantA'], $t['facilityA'], null, 'facility', 'active', '2026-08-11 00:00:00+00']
        );

        rlsSet($c, 'user_id', $userA);
        rlsSet($c, 'tenant_id', '');
        rlsSet($c, 'facility_id', '');
        rlsSet($c, 'is_platform', 'false');

        // Assigned facility is resolvable by name at login.
        $row = $c->selectOne('select id from facilities where id = ?', [$t['facilityA']]);
        expect($row)->not->toBeNull();

        // Unassigned facility in the same tenant stays invisible.
        expect($c->selectOne('select id from facilities where id = ?', [$facilityA2]))->toBeNull();

        // Cross-tenant facility stays invisible too.
        expect($c->selectOne('select id from facilities where id = ?', [$t['facilityB']]))->toBeNull();
    });
});

it('limits support session visibility to the owner or platform context', function () {
    rlsTx(rlsConn(), function ($c): void {
        $t = rlsTenants($c);
        $user = (string) Str::uuid();
        $session = (string) Str::uuid();
        $correlation = (string) Str::uuid();

        $c->insert(
            'insert into users (id, email, password_hash, status) values (?, ?, ?, ?)',
            [$user, 'support@two.test', 'hash', 'active']
        );

        rlsSet($c, 'tenant_id', '');
        rlsSet($c, 'user_id', $user);
        rlsSet($c, 'is_platform', 'true');
        $c->insert(
            'insert into support_sessions (id, user_id, organization_id, facility_id, reason, status, opened_at, expires_at, correlation_id) values (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$session, $user, $t['tenantA'], $t['facilityA'], 'Test support session.', 'active', '2026-08-11 00:00:00+00', '2026-08-11 01:00:00+00', $correlation]
        );

        // A different tenant context (different user GUC) cannot see it.
        rlsSet($c, 'tenant_id', $t['tenantA']);
        rlsSet($c, 'user_id', (string) Str::uuid());
        rlsSet($c, 'is_platform', 'false');
        expect($c->selectOne('select id from support_sessions where id = ?', [$session]))->toBeNull();

        // Platform context sees it.
        rlsSet($c, 'tenant_id', '');
        rlsSet($c, 'user_id', '');
        rlsSet($c, 'is_platform', 'true');
        expect($c->selectOne('select id from support_sessions where id = ?', [$session]))->not->toBeNull();
    });
});
