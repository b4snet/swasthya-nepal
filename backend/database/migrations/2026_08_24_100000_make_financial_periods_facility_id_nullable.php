<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL 17+ refuses to alter a column referenced in a policy.
        // Drop policies, alter, then recreate.
        $table = 'financial_periods';
        foreach (['select', 'insert', 'update', 'delete'] as $op) {
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_{$op} ON public.{$table}");
        }

        Schema::table($table, function ($t) {
            $t->uuid('facility_id')->nullable()->change();
        });

        $tenantFacility = 'tenant_id = swasthya_rls_tenant_id() AND (facility_id = swasthya_rls_facility_id() OR swasthya_rls_facility_id() IS NULL)';
        DB::statement("CREATE POLICY p_rls_{$table}_select ON public.{$table} FOR SELECT USING ({$tenantFacility})");
        DB::statement("CREATE POLICY p_rls_{$table}_insert ON public.{$table} FOR INSERT WITH CHECK (true)");
        DB::statement("CREATE POLICY p_rls_{$table}_update ON public.{$table} FOR UPDATE USING ({$tenantFacility}) WITH CHECK ({$tenantFacility})");
        DB::statement("CREATE POLICY p_rls_{$table}_delete ON public.{$table} FOR DELETE USING ({$tenantFacility})");
    }

    public function down(): void
    {
        $table = 'financial_periods';
        foreach (['select', 'insert', 'update', 'delete'] as $op) {
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_{$op} ON public.{$table}");
        }

        Schema::table($table, function ($t) {
            $t->uuid('facility_id')->nullable(false)->change();
        });

        $tenantFacility = 'tenant_id = swasthya_rls_tenant_id() AND (facility_id = swasthya_rls_facility_id() OR swasthya_rls_facility_id() IS NULL)';
        DB::statement("CREATE POLICY p_rls_{$table}_select ON public.{$table} FOR SELECT USING ({$tenantFacility})");
        DB::statement("CREATE POLICY p_rls_{$table}_insert ON public.{$table} FOR INSERT WITH CHECK (true)");
        DB::statement("CREATE POLICY p_rls_{$table}_update ON public.{$table} FOR UPDATE USING ({$tenantFacility}) WITH CHECK ({$tenantFacility})");
        DB::statement("CREATE POLICY p_rls_{$table}_delete ON public.{$table} FOR DELETE USING ({$tenantFacility})");
    }
};
