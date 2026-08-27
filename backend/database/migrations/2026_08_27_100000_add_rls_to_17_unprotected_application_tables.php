<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 100.7 — Add RLS to 17 application tables that were created by
 * later migrations without row-level security policies.
 *
 * These tables have tenant_id and/or facility_id columns but lacked
 * database-level RLS, relying solely on application-layer scoping.
 * This migration closes that defense-in-depth gap.
 *
 * Affected tables and their ownership model:
 *
 *  TENANT + FACILITY scoped (13 tables — uuid):
 *    accounts, accounts_payable, corrective_actions, disclosure_logs,
 *    hospital_documents, hospital_incidents, hospital_policies,
 *    journal_entries, journal_lines, patient_complaints, queue_entries,
 *    resource_bookings, staff_credentials
 *
 *  TENANT + FACILITY scoped (1 table — varchar):
 *    domain_events
 *
 *  TENANT scoped only (3 tables — no facility_id column):
 *    document_acknowledgements, document_versions, drug_interactions
 *
 * NOTE: domain_events stores tenant_id/facility_id as varchar, not uuid.
 *       Policies use ::text casting for that table.
 */
return new class extends Migration
{
    /**
     * Tables with both tenant_id (uuid) and facility_id (uuid).
     */
    private const TENANT_FACILITY_TABLES = [
        'accounts',
        'accounts_payable',
        'corrective_actions',
        'disclosure_logs',
        'hospital_documents',
        'hospital_incidents',
        'hospital_policies',
        'journal_entries',
        'journal_lines',
        'patient_complaints',
        'queue_entries',
        'resource_bookings',
        'staff_credentials',
    ];

    /**
     * Tables with tenant_id (uuid) but NO facility_id column.
     */
    private const TENANT_ONLY_TABLES = [
        'document_acknowledgements',
        'document_versions',
        'drug_interactions',
    ];

    /**
     * Tables where tenant_id/facility_id are varchar, not uuid.
     */
    private const VARCHAR_SCOPE_TABLES = [
        'domain_events',
    ];

    public function up(): void
    {
        // --- tenant_id (uuid) + facility_id (uuid) tables ---
        foreach (self::TENANT_FACILITY_TABLES as $table) {
            $this->enableRls($table);
            $this->addTenantFacilityPolicies($table);
        }

        // --- tenant_id (uuid) only tables ---
        foreach (self::TENANT_ONLY_TABLES as $table) {
            $this->enableRls($table);
            $this->addTenantOnlyPolicies($table);
        }

        // --- varchar-scope tables (domain_events) ---
        foreach (self::VARCHAR_SCOPE_TABLES as $table) {
            $this->enableRls($table);
            $this->addVarcharScopePolicies($table);
        }
    }

    public function down(): void
    {
        $all = array_merge(
            self::TENANT_FACILITY_TABLES,
            self::TENANT_ONLY_TABLES,
            self::VARCHAR_SCOPE_TABLES,
        );

        foreach ($all as $table) {
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON {$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON {$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON {$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON {$table}");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }

    private function enableRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Standard policy set for tables with uuid tenant_id + uuid facility_id.
     * Matches the pattern used by appointments, encounters, patients, etc.
     */
    private function addTenantFacilityPolicies(string $table): void
    {
        // SELECT: tenant match + facility match (or facility unspecified)
        DB::statement("
            CREATE POLICY p_rls_{$table}_select ON {$table} FOR SELECT
            USING (
                tenant_id = swasthya_rls_tenant_id()
                AND (facility_id = swasthya_rls_facility_id() OR swasthya_rls_facility_id() IS NULL)
            )
        ");

        // INSERT: application sets tenant_id/facility_id; CHECK true (majority pattern)
        DB::statement("
            CREATE POLICY p_rls_{$table}_insert ON {$table} FOR INSERT
            WITH CHECK (true)
        ");

        // UPDATE: existing row must be in-scope
        DB::statement("
            CREATE POLICY p_rls_{$table}_update ON {$table} FOR UPDATE
            USING (
                tenant_id = swasthya_rls_tenant_id()
                AND (facility_id = swasthya_rls_facility_id() OR swasthya_rls_facility_id() IS NULL)
            )
            WITH CHECK (
                tenant_id = swasthya_rls_tenant_id()
                AND (facility_id = swasthya_rls_facility_id() OR swasthya_rls_facility_id() IS NULL)
            )
        ");

        // DELETE: existing row must be in-scope
        DB::statement("
            CREATE POLICY p_rls_{$table}_delete ON {$table} FOR DELETE
            USING (
                tenant_id = swasthya_rls_tenant_id()
                AND (facility_id = swasthya_rls_facility_id() OR swasthya_rls_facility_id() IS NULL)
            )
        ");
    }

    /**
     * Policy set for tables with uuid tenant_id but no facility_id.
     */
    private function addTenantOnlyPolicies(string $table): void
    {
        DB::statement("
            CREATE POLICY p_rls_{$table}_select ON {$table} FOR SELECT
            USING (tenant_id = swasthya_rls_tenant_id())
        ");

        DB::statement("
            CREATE POLICY p_rls_{$table}_insert ON {$table} FOR INSERT
            WITH CHECK (true)
        ");

        DB::statement("
            CREATE POLICY p_rls_{$table}_update ON {$table} FOR UPDATE
            USING (tenant_id = swasthya_rls_tenant_id())
            WITH CHECK (tenant_id = swasthya_rls_tenant_id())
        ");

        DB::statement("
            CREATE POLICY p_rls_{$table}_delete ON {$table} FOR DELETE
            USING (tenant_id = swasthya_rls_tenant_id())
        ");
    }

    /**
     * Policy set for domain_events where tenant_id/facility_id are varchar.
     */
    private function addVarcharScopePolicies(string $table): void
    {
        DB::statement("
            CREATE POLICY p_rls_{$table}_select ON {$table} FOR SELECT
            USING (
                tenant_id::text = swasthya_rls_tenant_id()::text
                AND (facility_id::text = swasthya_rls_facility_id()::text OR swasthya_rls_facility_id() IS NULL)
            )
        ");

        DB::statement("
            CREATE POLICY p_rls_{$table}_insert ON {$table} FOR INSERT
            WITH CHECK (true)
        ");

        DB::statement("
            CREATE POLICY p_rls_{$table}_update ON {$table} FOR UPDATE
            USING (
                tenant_id::text = swasthya_rls_tenant_id()::text
                AND (facility_id::text = swasthya_rls_facility_id()::text OR swasthya_rls_facility_id() IS NULL)
            )
            WITH CHECK (
                tenant_id::text = swasthya_rls_tenant_id()::text
                AND (facility_id::text = swasthya_rls_facility_id()::text OR swasthya_rls_facility_id() IS NULL)
            )
        ");

        DB::statement("
            CREATE POLICY p_rls_{$table}_delete ON {$table} FOR DELETE
            USING (
                tenant_id::text = swasthya_rls_tenant_id()::text
                AND (facility_id::text = swasthya_rls_facility_id()::text OR swasthya_rls_facility_id() IS NULL)
            )
        ");
    }
};
