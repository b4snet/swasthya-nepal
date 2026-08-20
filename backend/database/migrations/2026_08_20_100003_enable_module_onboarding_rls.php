<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // modules — platform catalog, visible to all authenticated users
        DB::statement('ALTER TABLE public.modules ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.modules FORCE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS p_rls_modules_select ON public.modules');
        DB::statement(
            'CREATE POLICY p_rls_modules_select ON public.modules'
            .' FOR SELECT USING (swasthya_rls_is_platform() = true OR swasthya_rls_tenant_id() IS NOT NULL)'
        );
        DB::statement('DROP POLICY IF EXISTS p_rls_modules_insert ON public.modules');
        DB::statement(
            'CREATE POLICY p_rls_modules_insert ON public.modules'
            .' FOR INSERT WITH CHECK (swasthya_rls_is_platform() = true)'
        );
        DB::statement('DROP POLICY IF EXISTS p_rls_modules_update ON public.modules');
        DB::statement(
            'CREATE POLICY p_rls_modules_update ON public.modules'
            .' FOR UPDATE USING (swasthya_rls_is_platform() = true)'
            .' WITH CHECK (swasthya_rls_is_platform() = true)'
        );
        DB::statement('DROP POLICY IF EXISTS p_rls_modules_delete ON public.modules');
        DB::statement(
            'CREATE POLICY p_rls_modules_delete ON public.modules'
            .' FOR DELETE USING (swasthya_rls_is_platform() = true)'
        );

        // module_entitlements — tenant scoped via organization_id
        DB::statement('ALTER TABLE public.module_entitlements ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.module_entitlements FORCE ROW LEVEL SECURITY');

        DB::statement('DROP POLICY IF EXISTS p_rls_module_entitlements_select ON public.module_entitlements');
        DB::statement(
            'CREATE POLICY p_rls_module_entitlements_select ON public.module_entitlements'
            .' FOR SELECT USING (organization_id = swasthya_rls_tenant_id())'
        );
        DB::statement('DROP POLICY IF EXISTS p_rls_module_entitlements_insert ON public.module_entitlements');
        DB::statement(
            'CREATE POLICY p_rls_module_entitlements_insert ON public.module_entitlements'
            .' FOR INSERT WITH CHECK (true)'
        );
        DB::statement('DROP POLICY IF EXISTS p_rls_module_entitlements_update ON public.module_entitlements');
        DB::statement(
            'CREATE POLICY p_rls_module_entitlements_update ON public.module_entitlements'
            .' FOR UPDATE USING (organization_id = swasthya_rls_tenant_id())'
            .' WITH CHECK (organization_id = swasthya_rls_tenant_id())'
        );
        DB::statement('DROP POLICY IF EXISTS p_rls_module_entitlements_delete ON public.module_entitlements');
        DB::statement(
            'CREATE POLICY p_rls_module_entitlements_delete ON public.module_entitlements'
            .' FOR DELETE USING (organization_id = swasthya_rls_tenant_id())'
        );

        // onboarding_sessions — user-scoped via created_by
        DB::statement('ALTER TABLE public.onboarding_sessions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.onboarding_sessions FORCE ROW LEVEL SECURITY');

        $uid = 'app.current_user_id';

        DB::statement('DROP POLICY IF EXISTS p_rls_onboarding_sessions_select ON public.onboarding_sessions');
        DB::statement(
            'CREATE POLICY p_rls_onboarding_sessions_select ON public.onboarding_sessions'
            .' FOR SELECT USING ('
            .'swasthya_rls_is_platform() = true'
            .' OR created_by::text = swasthya_rls_user_id()::text'
            .')'
        );
        DB::statement('DROP POLICY IF EXISTS p_rls_onboarding_sessions_insert ON public.onboarding_sessions');
        DB::statement(
            'CREATE POLICY p_rls_onboarding_sessions_insert ON public.onboarding_sessions'
            .' FOR INSERT WITH CHECK (true)'
        );
        DB::statement('DROP POLICY IF EXISTS p_rls_onboarding_sessions_update ON public.onboarding_sessions');
        DB::statement(
            'CREATE POLICY p_rls_onboarding_sessions_update ON public.onboarding_sessions'
            .' FOR UPDATE USING ('
            .'swasthya_rls_is_platform() = true'
            .' OR created_by::text = swasthya_rls_user_id()::text'
            .') WITH CHECK ('
            .'swasthya_rls_is_platform() = true'
            .' OR created_by::text = swasthya_rls_user_id()::text'
            .')'
        );
        DB::statement('DROP POLICY IF EXISTS p_rls_onboarding_sessions_delete ON public.onboarding_sessions');
        DB::statement(
            'CREATE POLICY p_rls_onboarding_sessions_delete ON public.onboarding_sessions'
            .' FOR DELETE USING (swasthya_rls_is_platform() = true)'
        );
    }

    public function down(): void
    {
        $tables = ['modules', 'module_entitlements', 'onboarding_sessions'];
        foreach ($tables as $table) {
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON public.{$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON public.{$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON public.{$table}");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON public.{$table}");
            DB::statement("ALTER TABLE public.{$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
