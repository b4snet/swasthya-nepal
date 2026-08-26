<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable RLS on Nepal Financial Architecture tables (tax_rules, benefit_rules).
 *
 * Follows the exact pattern from 2026_08_19_500100_enable_budget_expense_rls.php.
 * These tables are TENANT-tier (tenant_id only, no facility_id on benefit_rules;
 * tax_rules may have nullable facility_id for facility-specific rules).
 */
return new class extends Migration
{
    /** Tables with only tenant_id (no facility_id column) */
    private const TENANT_ONLY = ['benefit_rules'];

    /** Tables with both tenant_id and facility_id */
    private const TENANT_FACILITY = ['tax_rules'];

    private const ALL_TABLES = ['tax_rules', 'benefit_rules'];

    public function up(): void
    {
        // Functions return uuid (or NULL when no JWT context). No NULLIF wrappers needed.
        $tenantOnly = 'tenant_id = swasthya_rls_tenant_id()';
        $tenantFacility = $tenantOnly . ' AND (facility_id = swasthya_rls_facility_id() OR swasthya_rls_facility_id() IS NULL)';

        foreach (self::ALL_TABLES as $table) {
            DB::statement("ALTER TABLE public.{$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE public.{$table} FORCE ROW LEVEL SECURITY");

            $using = in_array($table, self::TENANT_FACILITY) ? $tenantFacility : $tenantOnly;

            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON public.{$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_select ON public.{$table} FOR SELECT USING ({$using})");

            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON public.{$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_insert ON public.{$table} FOR INSERT WITH CHECK (true)");

            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON public.{$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_update ON public.{$table} FOR UPDATE USING ({$using}) WITH CHECK ({$using})");

            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON public.{$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_delete ON public.{$table} FOR DELETE USING ({$using})");
        }
    }

    public function down(): void
    {
        foreach (self::ALL_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON public.{$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON public.{$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON public.{$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON public.{$table}");
            DB::statement("ALTER TABLE public.{$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
