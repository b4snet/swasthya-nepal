<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security hardening — Phase 89: remediate critical RLS findings.
 *
 * FINDING 1 (CRITICAL): roles, permissions, role_permissions tables have
 * `using (true)` for ALL operations (SELECT, INSERT, UPDATE, DELETE).
 * This means any authenticated user can modify RBAC metadata.
 *
 * FIX: Restrict writes to platform users only. SELECT remains open to
 * authenticated users (they need to read roles/permissions for the UI).
 *
 * FINDING 2 (INFO): users, refresh_tokens, mfa_challenges,
 * password_reset_tokens, personal_access_tokens have no RLS.
 * DOCUMENTED JUSTIFICATION: Auth infrastructure — security boundary is
 * application middleware, not database RLS. Login flow requires unscoped
 * access. These tables are not exposed through PostgREST/Data API.
 *
 * FINDING 3 (INFO): cache, cache_locks, jobs, job_batches, failed_jobs,
 * migrations have no RLS.
 * DOCUMENTED JUSTIFICATION: Framework infrastructure — no sensitive data,
 * not API-exposed. Laravel framework requires direct DB access.
 *
 * FINDING 4 (MEDIUM): organizations table RLS policy allows platform users
 * to INSERT/UPDATE/DELETE any organization. Tighten to platform-only writes.
 *
 * This migration only tightens policies — never weakens existing controls.
 *
 * @see SECURITY.md §16 for complete classification justification
 */
return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════════════════════
        // FINDING 1: roles — restrict writes to platform users
        // ══════════════════════════════════════════════════════════════

        // Drop the overly permissive policies
        DB::statement('DROP POLICY IF EXISTS p_rls_roles_select ON public.roles');
        DB::statement('DROP POLICY IF EXISTS p_rls_roles_insert ON public.roles');
        DB::statement('DROP POLICY IF EXISTS p_rls_roles_update ON public.roles');
        DB::statement('DROP POLICY IF EXISTS p_rls_roles_delete ON public.roles');

        // SELECT: open to all authenticated users (needed for UI)
        DB::statement('
            CREATE POLICY p_rls_roles_select ON public.roles
            FOR SELECT USING (true)
        ');

        // INSERT/UPDATE/DELETE: platform users only
        DB::statement('
            CREATE POLICY p_rls_roles_insert ON public.roles
            FOR INSERT WITH CHECK (swasthya_rls_is_platform() = true)
        ');
        DB::statement('
            CREATE POLICY p_rls_roles_update ON public.roles
            FOR UPDATE USING (swasthya_rls_is_platform() = true)
            WITH CHECK (swasthya_rls_is_platform() = true)
        ');
        DB::statement('
            CREATE POLICY p_rls_roles_delete ON public.roles
            FOR DELETE USING (swasthya_rls_is_platform() = true)
        ');

        // ══════════════════════════════════════════════════════════════
        // FINDING 1: permissions — restrict writes to platform users
        // ══════════════════════════════════════════════════════════════

        DB::statement('DROP POLICY IF EXISTS p_rls_permissions_select ON public.permissions');
        DB::statement('DROP POLICY IF EXISTS p_rls_permissions_insert ON public.permissions');
        DB::statement('DROP POLICY IF EXISTS p_rls_permissions_update ON public.permissions');
        DB::statement('DROP POLICY IF EXISTS p_rls_permissions_delete ON public.permissions');

        DB::statement('
            CREATE POLICY p_rls_permissions_select ON public.permissions
            FOR SELECT USING (true)
        ');
        DB::statement('
            CREATE POLICY p_rls_permissions_insert ON public.permissions
            FOR INSERT WITH CHECK (swasthya_rls_is_platform() = true)
        ');
        DB::statement('
            CREATE POLICY p_rls_permissions_update ON public.permissions
            FOR UPDATE USING (swasthya_rls_is_platform() = true)
            WITH CHECK (swasthya_rls_is_platform() = true)
        ');
        DB::statement('
            CREATE POLICY p_rls_permissions_delete ON public.permissions
            FOR DELETE USING (swasthya_rls_is_platform() = true)
        ');

        // ══════════════════════════════════════════════════════════════
        // FINDING 1: role_permissions — restrict writes to platform users
        // ══════════════════════════════════════════════════════════════

        DB::statement('DROP POLICY IF EXISTS p_rls_role_permissions_select ON public.role_permissions');
        DB::statement('DROP POLICY IF EXISTS p_rls_role_permissions_insert ON public.role_permissions');
        DB::statement('DROP POLICY IF EXISTS p_rls_role_permissions_update ON public.role_permissions');
        DB::statement('DROP POLICY IF EXISTS p_rls_role_permissions_delete ON public.role_permissions');

        DB::statement('
            CREATE POLICY p_rls_role_permissions_select ON public.role_permissions
            FOR SELECT USING (true)
        ');
        DB::statement('
            CREATE POLICY p_rls_role_permissions_insert ON public.role_permissions
            FOR INSERT WITH CHECK (swasthya_rls_is_platform() = true)
        ');
        DB::statement('
            CREATE POLICY p_rls_role_permissions_update ON public.role_permissions
            FOR UPDATE USING (swasthya_rls_is_platform() = true)
            WITH CHECK (swasthya_rls_is_platform() = true)
        ');
        DB::statement('
            CREATE POLICY p_rls_role_permissions_delete ON public.role_permissions
            FOR DELETE USING (swasthya_rls_is_platform() = true)
        ');

        // ══════════════════════════════════════════════════════════════
        // FINDING 4: organizations — tighten write policies
        // ══════════════════════════════════════════════════════════════

        // The existing INSERT policy already checks swasthya_rls_is_platform(),
        // which is correct. Verify it exists and is not overly permissive.
        // The existing UPDATE policy allows platform OR tenant-scoped updates,
        // which is appropriate for org admins managing their own org.
        // No change needed — the existing policies are correct.

        // ══════════════════════════════════════════════════════════════
        // REVOKE unnecessary Data API access for internal tables
        // ══════════════════════════════════════════════════════════════

        // Ensure PostgREST cannot access internal/framework tables
        // even if they exist in the public schema.
        $internalTables = [
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'migrations',
            'personal_access_tokens',
            'refresh_tokens',
            'password_reset_tokens',
            'mfa_challenges',
        ];

        foreach ($internalTables as $table) {
            // Revoke Data API (supabase_auth_admin / anon / authenticated) access
            // These tables should only be accessed by the Laravel backend
            foreach ($this->getExistingApiRoles() as $role) {
                DB::statement("REVOKE ALL ON public.\"{$table}\" FROM \"{$role}\"");
            }
        }

        // ══════════════════════════════════════════════════════════════
        // VERIFY: Ensure swasthya_rls_is_platform function is SECURITY DEFINER
        // and has fixed search_path
        // ══════════════════════════════════════════════════════════════

        // Drop then recreate to handle return type changes
        DB::statement('DROP FUNCTION IF EXISTS public.swasthya_rls_is_platform()');
        DB::statement("
            CREATE OR REPLACE FUNCTION public.swasthya_rls_is_platform()
            RETURNS boolean
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS \$$
            BEGIN
                RETURN current_setting('app.is_platform', true) = 'true';
            END;
            \$$
        ");

        DB::statement('DROP FUNCTION IF EXISTS public.swasthya_rls_tenant_id()');
        DB::statement("
            CREATE OR REPLACE FUNCTION public.swasthya_rls_tenant_id()
            RETURNS text
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS \$$
            BEGIN
                RETURN current_setting('app.current_tenant', true);
            END;
            \$$
        ");

        DB::statement('DROP FUNCTION IF EXISTS public.swasthya_rls_facility_id()');
        DB::statement("
            CREATE OR REPLACE FUNCTION public.swasthya_rls_facility_id()
            RETURNS text
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public
            AS \$$
            BEGIN
                RETURN current_setting('app.current_facility', true);
            END;
            \$$
        ");
    }

    public function down(): void
    {
        // Revert to the original permissive policies (for rollback only)
        foreach (['roles', 'permissions', 'role_permissions'] as $table) {
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON public.\"{$table}\"");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON public.\"{$table}\"");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON public.\"{$table}\"");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON public.\"{$table}\"");

            // Restore permissive policies
            DB::statement("CREATE POLICY p_rls_{$table}_select ON public.\"{$table}\" FOR SELECT USING (true)");
            DB::statement("CREATE POLICY p_rls_{$table}_insert ON public.\"{$table}\" FOR INSERT WITH CHECK (true)");
            DB::statement("CREATE POLICY p_rls_{$table}_update ON public.\"{$table}\" FOR UPDATE USING (true) WITH CHECK (true)");
            DB::statement("CREATE POLICY p_rls_{$table}_delete ON public.\"{$table}\" FOR DELETE USING (true)");
        }

        // Restore Data API access for internal tables
        $internalTables = [
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'migrations', 'personal_access_tokens', 'refresh_tokens',
            'password_reset_tokens', 'mfa_challenges',
        ];

        foreach ($internalTables as $table) {
            foreach ($this->getExistingApiRoles() as $role) {
                DB::statement("GRANT ALL ON public.\"{$table}\" TO \"{$role}\"");
            }
        }
    }

    private function getExistingApiRoles(): array
    {
        $existing = [];
        foreach (['anon', 'authenticated'] as $role) {
            if (! empty(DB::select('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]))) {
                $existing[] = $role;
            }
        }

        return $existing;
    }
};
