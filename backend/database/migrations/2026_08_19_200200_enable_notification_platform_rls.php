<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enable RLS on the Phase 12 notification platform tables.
 *
 * All tables are tenant-scoped via the standard TENANT-tier policy pattern:
 * SELECT/UPDATE/DELETE restricted by tenant_id, INSERT permissive.
 */
return new class extends Migration
{
    private const TABLES = [
        'notification_templates',
        'audience_segments',
        'broadcast_campaigns',
        'delivery_attempts',
        'notification_recipients',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE public.\"{$table}\" FORCE ROW LEVEL SECURITY");

            // SELECT: tenant-bound
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON public.\"{$table}\"");
            DB::statement(
                "CREATE POLICY p_rls_{$table}_select ON public.\"{$table}\"
                 FOR SELECT USING (tenant_id = swasthya_rls_tenant_id())"
            );

            // INSERT: permissive (app middleware validates)
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON public.\"{$table}\"");
            DB::statement(
                "CREATE POLICY p_rls_{$table}_insert ON public.\"{$table}\"
                 FOR INSERT WITH CHECK (true)"
            );

            // UPDATE: tenant-bound
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON public.\"{$table}\"");
            DB::statement(
                "CREATE POLICY p_rls_{$table}_update ON public.\"{$table}\"
                 FOR UPDATE USING (tenant_id = swasthya_rls_tenant_id())
                 WITH CHECK (tenant_id = swasthya_rls_tenant_id())"
            );

            // DELETE: tenant-bound
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON public.\"{$table}\"");
            DB::statement(
                "CREATE POLICY p_rls_{$table}_delete ON public.\"{$table}\"
                 FOR DELETE USING (tenant_id = swasthya_rls_tenant_id())"
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON public.\"{$table}\"");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON public.\"{$table}\"");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON public.\"{$table}\"");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON public.\"{$table}\"");
            DB::statement("ALTER TABLE public.\"{$table}\" DISABLE ROW LEVEL SECURITY");
        }
    }
};
