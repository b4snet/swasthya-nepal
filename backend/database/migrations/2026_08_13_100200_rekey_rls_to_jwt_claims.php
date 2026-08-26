<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 — Supabase-native RLS re-key.
 *
 * Re-keys the 144 RLS policies from the Laravel-set `app.*` GUCs
 * (app.user_id / app.tenant_id / app.facility_id / app.branch_id /
 * app.is_platform) to the Supabase-compatible `request.jwt.claims` GUC — the
 * exact mechanism Supabase uses to surface the signed JWT payload to
 * PostgreSQL on every request.
 *
 * The re-key is a pure SOURCE swap: every policy expression keeps its exact
 * shape (`tenant_id = X`, `(facility_id = Y OR Y IS NULL)`,
 * `(branch_id IS NULL OR branch_id = Z OR Z IS NULL)`, the facilities
 * authorization join, the audit/role-assignment/support-session dual rows),
 * with the five context values now read from claim keys `app_*` inside the
 * claims JSON via immutable, stable SQL helper functions
 * (`public.swasthya_rls_*`). Nothing is enabled/disabled, nothing is
 * weakened: the scoped-table matrix (37 on / 13 off), the INSERT-with-CHECK
 * policy, and the RLS-on state are untouched.
 *
 * Trust model (identical to the GUC era, SECURITY.md §14): `request.jwt.claims`
 * is server-issued per request — by Laravel's tenant-context middleware while
 * the bridge is in place (DatabaseTenantContext mirrors the context), and by
 * the Supabase edge-function/GoTrue signer in the native architecture. A
 * client can never set it. Missing or empty claims resolve to NULL → zero
 * access (safe failure, never a leak).
 *
 * `down()` restores the original GUC-based policy bodies and drops the helper
 * functions. Policy names are unchanged (`p_rls_<table>_<op>`), so up/down
 * and the original migration's down() stay mutually consistent.
 */
return new class extends Migration
{
    /**
     * The five context values as Supabase-native claim expressions. Policies
     * call these stable functions, never parse the JSON themselves.
     *
     * @var array<string, string>
     */
    private const CLAIMS = [
        'TENANT' => 'public.swasthya_rls_tenant_id()',
        'FACILITY' => 'public.swasthya_rls_facility_id()',
        'BRANCH' => 'public.swasthya_rls_branch_id()',
        'USER' => 'public.swasthya_rls_user_id()',
        'PLATFORM' => 'public.swasthya_rls_is_platform()',
    ];

    /**
     * The original Laravel GUC expressions (restored by down()).
     *
     * @var array<string, string>
     */
    private const GUC = [
        'TENANT' => "NULLIF(current_setting('app.tenant_id', true), '')::uuid",
        'FACILITY' => "NULLIF(current_setting('app.facility_id', true), '')::uuid",
        'BRANCH' => "NULLIF(current_setting('app.branch_id', true), '')::uuid",
        'USER' => "NULLIF(current_setting('app.user_id', true), '')::uuid",
        'PLATFORM' => "current_setting('app.is_platform', true) = 'true'",
    ];

    /** @var list<string> */
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

    /** @var list<string> */
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

    /** @var list<string> */
    private const TENANT_FACILITY_BRANCH_TABLES = [
        'departments',
        'locations',
        'wards',
        'rooms',
        'beds',
    ];

    /** @var array<string, string> the expression set currently in use */
    private array $c = self::CLAIMS;

    public function up(): void
    {
        $this->createClaimHelpers();
        $this->grantExecute();

        $this->c = self::CLAIMS;
        $this->recreatePolicies();
    }

    public function down(): void
    {
        $this->c = self::GUC;
        $this->recreatePolicies();
        $this->dropClaimHelpers();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Stable claim readers. `current_setting(..., true)` is NULL when the GUC
     * is absent; an empty string or absent claim resolves to '' and then to
     * NULL at the typed wrappers — zero access, never an error.
     */
    private function createClaimHelpers(): void
    {
        // PostgreSQL 14+ requires DROP before changing return types of existing functions
        $this->dropClaimHelpers();

        DB::statement(
            <<<'SQL'
            create or replace function public.swasthya_rls_claim(p_name text)
            returns text
            language sql
            stable
            as $$
                select coalesce(
                    nullif(current_setting('request.jwt.claims', true), '')::json ->> p_name,
                    ''
                )
            $$;
            SQL
        );

        foreach ([
            'user_id', 'tenant_id', 'facility_id', 'branch_id',
        ] as $claim) {
            // Use string interpolation so $$ dollar-quoting is passed literally
            DB::statement(
                "CREATE OR REPLACE FUNCTION public.swasthya_rls_{$claim}() "
                . 'RETURNS uuid LANGUAGE sql STABLE AS ' . '$$'
                . " SELECT nullif(public.swasthya_rls_claim('app_{$claim}'), '')::uuid "
                . '$$;'
            );
        }

        DB::statement(
            <<<'SQL'
            create or replace function public.swasthya_rls_is_platform()
            returns boolean
            language sql
            stable
            as $$
                select public.swasthya_rls_claim('app_is_platform') = 'true'
            $$;
            SQL
        );
    }

    private function grantExecute(): void
    {
        DB::statement('grant execute on all functions in schema public to swasthya_app');
    }

    private function dropClaimHelpers(): void
    {
        // CASCADE required because PostgreSQL 17 blocks dropping functions
        // that have dependent policies; recreatePolicies() rebuilds them after.
        DB::statement('drop function if exists public.swasthya_rls_claim(text) cascade');
        DB::statement('drop function if exists public.swasthya_rls_user_id() cascade');
        DB::statement('drop function if exists public.swasthya_rls_tenant_id() cascade');
        DB::statement('drop function if exists public.swasthya_rls_facility_id() cascade');
        DB::statement('drop function if exists public.swasthya_rls_branch_id() cascade');
        DB::statement('drop function if exists public.swasthya_rls_is_platform() cascade');
    }

    /* ------------------------------------------------------------------ */

    private function recreatePolicies(): void
    {
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

    private function createPolicies(string $table, string $using, ?string $updateCheck = null): void
    {
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

    private function createFacilityPolicies(): void
    {
        $table = 'facilities';
        $using = $this->tenantClause()
            .' OR EXISTS ('
            .'select 1 from role_assignments where user_id = '.$this->c['USER']
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

    private function createAuditPolicies(): void
    {
        $table = 'audit_events';
        $using = '(tenant_id = '.$this->c['TENANT'].' AND ('.$this->facilityClause().'))'
            .' OR (tenant_id IS NULL AND '.$this->c['TENANT'].' IS NULL)';

        $this->dropPolicy($table, 'select');
        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");

        $this->dropPolicy($table, 'insert');
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");
    }

    private function createRoleAssignmentPolicies(): void
    {
        $table = 'role_assignments';
        $using = '(user_id = '.$this->c['USER'].')'
            .' OR (tenant_id = '.$this->c['TENANT'].' AND ('.$this->facilityClause().'))'
            .' OR (tenant_id IS NULL AND '.$this->c['TENANT'].' IS NULL)';
        $updateCheck = '(tenant_id = '.$this->c['TENANT'].') OR (tenant_id IS NULL AND '.$this->c['TENANT'].' IS NULL)';

        $this->dropPolicy($table, 'select');
        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");

        $this->dropPolicy($table, 'insert');
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");

        $this->dropPolicy($table, 'update');
        DB::statement("create policy p_rls_{$table}_update on {$table} for update using ({$using}) with check ({$updateCheck})");
    }

    private function createSupportSessionPolicies(): void
    {
        $table = 'support_sessions';
        $using = '(user_id = '.$this->c['USER'].') OR '.$this->c['PLATFORM'];

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

    private function tenantClause(): string
    {
        return 'tenant_id = '.$this->c['TENANT'];
    }

    private function facilityClause(): string
    {
        return '(facility_id = '.$this->c['FACILITY'].' OR '.$this->c['FACILITY'].' IS NULL)';
    }

    private function branchClause(): string
    {
        return '(branch_id IS NULL OR branch_id = '.$this->c['BRANCH'].' OR '.$this->c['BRANCH'].' IS NULL)';
    }
};
