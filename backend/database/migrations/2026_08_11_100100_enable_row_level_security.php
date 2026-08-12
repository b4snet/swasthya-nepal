<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-layer tenancy enforcement (TENANCY.md V2 §5–6, SECURITY.md §14,
 * DATABASE.md §1.5).
 *
 * Row-level security is the DATABASE-LEVEL defense-in-depth layer on top of
 * the application authorization layer — it does not replace RBAC. Every
 * request runs inside ONE transaction whose LOCAL GUCs (app.tenant_id,
 * app.facility_id, app.branch_id, app.user_id, app.is_platform) are set by
 * the tenant-context middleware from the authenticated principal's context —
 * never from client input. Because the settings are transaction-local, a
 * reused connection can never leak one request's context into another
 * (TENANCY.md V2 §7).
 *
 * Policy design:
 *  - INSERT is deliberately permissive (WITH CHECK true): the application
 *    role sets tenant_id server-side from context, and provisioning / test
 *    fixtures must create rows before a tenant GUC exists. The isolation
 *    guarantee is READ/UPDATE/DELETE: a row outside the current context is
 *    invisible and untouchable.
 *  - UPDATE's WITH CHECK pins the row to the current tenant AND facility:
 *    a context can neither move a row into another tenant nor into another
 *    facility; cross-facility moves are org-level decisions (transfers,
 *    reassignment) made in an org-wide context. Branch reassignment is
 *    application-layer (branch is a grouping, not a hard boundary).     *  - audit_events and role_assignments carry dual policies (platform rows
 *    vs tenant rows) so platform administration and tenant audit remain
 *    separated; support_sessions are visible only to their owner or a
 *    platform context.
 *  - facilities carry an authorization join: a principal can resolve the
 *    facilities it has an active assignment to (the login payload and
 *    facility picker must work BEFORE any tenant GUC exists — the
 *    client proposes X-Swasthya-Facility, it never asserts it).
 *  - Tables with NO tenant identity (users, roles, permissions,
 *    role_permissions, personal_access_tokens, refresh_tokens) and the
 *    tenant root (organizations) are intentionally NOT RLS-scoped:
 *    identity, the role catalog, and the tenant boundary itself must be
 *    resolvable BEFORE a tenant context exists (auth, login, context
 *    resolution); their authorization is enforced by the application layer
 *    (DATABASE.md §1.5 matrix).
 *
 * Prerequisite: the swasthya_app role MUST exist (database/security/roles.sql
 * — CREATE ROLE is non-transactional and cannot run inside a migration).
 */
return new class extends Migration
{
    private const TENANT = "NULLIF(current_setting('app.tenant_id', true), '')::uuid";

    private const FACILITY = "NULLIF(current_setting('app.facility_id', true), '')::uuid";

    private const BRANCH = "NULLIF(current_setting('app.branch_id', true), '')::uuid";

    private const USER = "NULLIF(current_setting('app.user_id', true), '')::uuid";

    private const PLATFORM = "current_setting('app.is_platform', true) = 'true'";

    /**
     * Tables whose only tenant column is tenant_id.
     *
     * @var list<string>
     */
    private const TENANT_ONLY_TABLES = [
        'payers',
        'mrn_counters',
        'patient_identifiers',
        'patient_contacts',
        'insurance_policies',
        'patient_documents',
        'consents',
        'patient_timeline_entries',
        'diagnoses',
        'clinical_notes',
        'prescriptions',
        'prescription_lines',
        'invoice_lines',
        'payment_allocations',
    ];

    /**
     * Tables carrying tenant_id + facility_id.
     *
     * @var list<string>
     */
    private const TENANT_FACILITY_TABLES = [
        'staff',
        'services',
        'facility_settings',
        'schedule_templates',
        'schedule_exceptions',
        'appointments',
        'token_counters',
        'encounters',
        'medications',
        'charges',
        'invoices',
        'payments',
        'branches',
        'patients',
    ];

    /**
     * Tables carrying tenant_id + facility_id + optional branch_id.
     *
     * @var list<string>
     */
    private const TENANT_FACILITY_BRANCH_TABLES = [
        'departments',
        'locations',
        'wards',
        'rooms',
        'beds',
    ];

    public function up(): void
    {
        $this->assertAppRoleExists();
        $this->grantApplicationPrivileges();

        // Enforce the documented RLS matrix (DATABASE.md §1.5) on ANY host.
        // Managed platforms (e.g. Supabase's "Enable RLS on new tables"
        // setting) enable RLS by default on every public table, which would
        // leave the identity/root/framework tables RLS-on with zero policies
        // and silently block the application role (login reads users,
        // Sanctum reads personal_access_tokens, queue/cache read framework
        // tables). Explicitly disable RLS outside the scoped set, then enable
        // exactly the scoped tables below.
        $this->disableRlsOutsideScopedSet();

        foreach (self::TENANT_ONLY_TABLES as $table) {
            $this->createPolicies($table, $this->tenantClause());
        }

        foreach (self::TENANT_FACILITY_TABLES as $table) {
            $this->createPolicies(
                $table,
                $this->tenantClause().' AND '.$this->facilityClause(),
                $this->tenantClause().' AND '.$this->facilityClause(),
            );
        }

        foreach (self::TENANT_FACILITY_BRANCH_TABLES as $table) {
            $this->createPolicies(
                $table,
                $this->tenantClause().' AND '.$this->facilityClause().' AND '.$this->branchClause(),
                $this->tenantClause().' AND '.$this->facilityClause(),
            );
        }

        $this->createFacilityPolicies();
        $this->createAuditPolicies();
        $this->createRoleAssignmentPolicies();
        $this->createSupportSessionPolicies();
    }

    public function down(): void
    {
        $tables = array_merge(
            self::TENANT_ONLY_TABLES,
            self::TENANT_FACILITY_TABLES,
            self::TENANT_FACILITY_BRANCH_TABLES,
            ['facilities', 'audit_events', 'role_assignments', 'support_sessions'],
        );

        foreach ($tables as $table) {
            foreach (['select', 'insert', 'update', 'delete'] as $operation) {
                DB::statement("drop policy if exists p_rls_{$table}_{$operation} on {$table}");
            }
            DB::statement("alter table {$table} disable row level security");
        }
    }

    private function assertAppRoleExists(): void
    {
        DB::statement(
            <<<'SQL'
            do $$
            begin
                if not exists (select from pg_roles where rolname = 'swasthya_app') then
                    raise exception 'swasthya_app role missing — run database/security/roles.sql first (CREATE ROLE cannot run inside a migration)';
                end if;
            end
            $$;
            SQL
        );
    }

    private function grantApplicationPrivileges(): void
    {
        $database = $this->quote(DB::connection()->getDatabaseName());
        // The authoritative server-side role. On managed poolers (e.g.
        // Supabase's `<role>.<project-ref>` aliases) the configured username
        // is a routing alias, NOT a role that exists server-side, so
        // ALTER DEFAULT PRIVILEGES FOR ROLE would fail with
        // "role ... does not exist". current_user is the real identity of
        // the migration session and works on every host.
        $owner = $this->quote((string) DB::selectOne('select current_user as role')->role);

        DB::statement("grant connect on database {$database} to swasthya_app");
        DB::statement('grant usage on schema public to swasthya_app');

        // DML on every current table and sequence in the public schema.
        DB::statement(
            <<<'SQL'
            do $$
            declare
                t text;
            begin
                for t in
                    select tablename from pg_tables
                    where schemaname = 'public'
                loop
                    execute format('grant select, insert, update, delete on table public.%I to swasthya_app', t);
                end loop;
            end
            $$;
            SQL
        );
        DB::statement(
            <<<'SQL'
            do $$
            declare
                s text;
            begin
                for s in
                    select sequence_name from information_schema.sequences
                    where sequence_schema = 'public'
                loop
                    execute format('grant usage, select on sequence public.%I to swasthya_app', s);
                end loop;
            end
            $$;
            SQL
        );

        // Tables created by the migration owner in the future (new phases)
        // automatically inherit the grants — the runtime role never needs a
        // per-migration grant bump.
        DB::statement(
            "alter default privileges for role {$owner} in schema public grant select, insert, update, delete on tables to swasthya_app"
        );
        DB::statement(
            "alter default privileges for role {$owner} in schema public grant usage, select on sequences to swasthya_app"
        );
    }

    /**
     * Standard tenant-scoped policy set: strict USING on read/update/delete;
     * relaxed INSERT (see class doc); UPDATE WITH CHECK pins the row to the
     * current tenant (and facility for facility-scoped tables).
     */
    private function createPolicies(string $table, string $using, ?string $updateCheck = null): void
    {
        DB::statement("alter table {$table} enable row level security");

        $this->dropPolicy($table, 'select');
        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");

        $this->dropPolicy($table, 'insert');
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");

        $this->dropPolicy($table, 'update');
        $check = $updateCheck ?? $this->tenantClause();
        DB::statement("create policy p_rls_{$table}_update on {$table} for update using ({$using}) with check ({$check})");

        $this->dropPolicy($table, 'delete');
        DB::statement("create policy p_rls_{$table}_delete on {$table} for delete using ({$using})");
    }

    /**
     * Facilities: tenant-scoped like any business table, PLUS an
     * authorization join so a principal can resolve the facilities it is
     * actively assigned to even before a tenant GUC exists (login payload,
     * facility picker — TENANCY.md V2 §7: the client proposes
     * X-Swasthya-Facility, never asserts it). Reads outside the tenant
     * context are still impossible: the join is restricted to rows the
     * principal is actually assigned to (subquery over role_assignments,
     * which is itself RLS-filtered to the principal's own rows).
     */
    private function createFacilityPolicies(): void
    {
        $table = 'facilities';
        DB::statement("alter table {$table} enable row level security");

        $using = $this->tenantClause()
            .' OR EXISTS ('
            .'select 1 from role_assignments where user_id = '.self::USER
            .' and role_assignments.facility_id = facilities.id'
            .' and status = \'active\')';

        $this->dropPolicy($table, 'select');
        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");

        $this->dropPolicy($table, 'insert');
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");

        $this->dropPolicy($table, 'update');
        DB::statement(
            "create policy p_rls_{$table}_update on {$table} for update using ({$using}) with check ({$this->tenantClause()})"
        );

        $this->dropPolicy($table, 'delete');
        DB::statement("create policy p_rls_{$table}_delete on {$table} for delete using ({$this->tenantClause()})");
    }

    /**
     * Append-only audit trail (Phase 13): platform rows visible to platform
     * context, tenant rows visible to their tenant; INSERT allowed from any
     * context; UPDATE/DELETE have NO policy, so the application role cannot
     * modify or erase history even with table-level grants.
     */
    private function createAuditPolicies(): void
    {
        $table = 'audit_events';
        DB::statement("alter table {$table} enable row level security");

        $using = '(tenant_id = '.self::TENANT.' AND ('.$this->facilityClause().'))'
            .' OR (tenant_id IS NULL AND '.self::TENANT.' IS NULL)';

        $this->dropPolicy($table, 'select');
        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");

        $this->dropPolicy($table, 'insert');
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");
    }

    /**
     * Authorization join: a principal can always resolve ITS OWN assignments
     * (login sets app.user_id in a user-scoped transaction; the middleware
     * sets it before resolving context), tenant admins manage their tenant's
     * rows, platform context sees platform rows.
     */
    private function createRoleAssignmentPolicies(): void
    {
        $table = 'role_assignments';
        DB::statement("alter table {$table} enable row level security");

        $using = '(user_id = '.self::USER.')'
            .' OR (tenant_id = '.self::TENANT.' AND ('.$this->facilityClause().'))'
            .' OR (tenant_id IS NULL AND '.self::TENANT.' IS NULL)';
        $updateCheck = '(tenant_id = '.self::TENANT.') OR (tenant_id IS NULL AND '.self::TENANT.' IS NULL)';

        $this->dropPolicy($table, 'select');
        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");

        $this->dropPolicy($table, 'insert');
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");

        $this->dropPolicy($table, 'update');
        DB::statement("create policy p_rls_{$table}_update on {$table} for update using ({$using}) with check ({$updateCheck})");
    }

    /**
     * Support sessions are platform artifacts: visible to their owner and to
     * platform context only.
     */
    private function createSupportSessionPolicies(): void
    {
        $table = 'support_sessions';
        DB::statement("alter table {$table} enable row level security");

        $using = '(user_id = '.self::USER.') OR '.self::PLATFORM;

        $this->dropPolicy($table, 'select');
        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");

        $this->dropPolicy($table, 'insert');
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check ({$using})");

        $this->dropPolicy($table, 'update');
        DB::statement("create policy p_rls_{$table}_update on {$table} for update using ({$using}) with check ({$using})");
    }

    private function dropPolicy(string $table, string $operation): void
    {
        DB::statement("drop policy if exists p_rls_{$table}_{$operation} on {$table}");
    }

    /**
     * Force the documented matrix on hosts whose platform default enables
     * RLS on every new table: RLS stays ON only on the scoped tenant-owned
     * tables; every other public table is explicitly DISABLED (idempotent).
     */
    private function disableRlsOutsideScopedSet(): void
    {
        $scoped = array_fill_keys(array_merge(
            self::TENANT_ONLY_TABLES,
            self::TENANT_FACILITY_TABLES,
            self::TENANT_FACILITY_BRANCH_TABLES,
            ['facilities', 'audit_events', 'role_assignments', 'support_sessions'],
        ), true);

        $tables = DB::select('select tablename from pg_tables where schemaname = ?', ['public']);

        foreach ($tables as $table) {
            if (! isset($scoped[$table->tablename])) {
                DB::statement("alter table public.{$this->quote($table->tablename)} disable row level security");
            }
        }
    }

    private function tenantClause(): string
    {
        return 'tenant_id = '.self::TENANT;
    }

    private function facilityClause(): string
    {
        return '(facility_id = '.self::FACILITY.' OR '.self::FACILITY.' IS NULL)';
    }

    private function branchClause(): string
    {
        return '(branch_id IS NULL OR branch_id = '.self::BRANCH.' OR '.self::BRANCH.' IS NULL)';
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
