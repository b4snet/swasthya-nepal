<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable + FORCE RLS on the 4 new HR/Asset gap tables.
 * Policy pattern matches the existing 13-table set: TENANT_FACILITY.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'calibration_records',
            'equipment_incidents',
            'asset_disposals',
            'staff_transfers',
        ];

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            $using = 'tenant_id = public.swasthya_rls_tenant_id()'
                .' AND (facility_id = public.swasthya_rls_facility_id() OR public.swasthya_rls_facility_id() IS NULL)';

            DB::statement("CREATE POLICY p_rls_{$table}_select ON {$table} FOR SELECT USING ({$using})");
            DB::statement("CREATE POLICY p_rls_{$table}_insert ON {$table} FOR INSERT WITH CHECK (true)");
            DB::statement("CREATE POLICY p_rls_{$table}_update ON {$table} FOR UPDATE USING ({$using}) WITH CHECK ({$using})");
            DB::statement("CREATE POLICY p_rls_{$table}_delete ON {$table} FOR DELETE USING ({$using})");
        }
    }

    public function down(): void
    {
        $tables = [
            'calibration_records',
            'equipment_incidents',
            'asset_disposals',
            'staff_transfers',
        ];

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            foreach (['select', 'insert', 'update', 'delete'] as $op) {
                DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_{$op} ON {$table}");
            }
        }
    }
};
