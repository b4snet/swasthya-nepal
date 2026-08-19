<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'expense_categories', 'budgets', 'budget_lines', 'expenses', 'financial_periods',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE public.{$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE public.{$table} FORCE ROW LEVEL SECURITY");

            $tenantFacility = 'tenant_id = swasthya_rls_tenant_id() AND (facility_id = swasthya_rls_facility_id() OR swasthya_rls_facility_id() IS NULL)';

            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON public.{$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_select ON public.{$table} FOR SELECT USING ({$tenantFacility})");

            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON public.{$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_insert ON public.{$table} FOR INSERT WITH CHECK (true)");

            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON public.{$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_update ON public.{$table} FOR UPDATE USING ({$tenantFacility}) WITH CHECK ({$tenantFacility})");

            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON public.{$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_delete ON public.{$table} FOR DELETE USING ({$tenantFacility})");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON public.{$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON public.{$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON public.{$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON public.{$table}");
            DB::statement("ALTER TABLE public.{$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
