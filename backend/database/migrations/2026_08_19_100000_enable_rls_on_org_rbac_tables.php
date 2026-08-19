<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security reconciliation — enable RLS on the four remaining public-schema
 * tables that carry application-meaningful data but currently lack RLS:
 *
 *   organizations      — tenant root; must restrict cross-tenant reads
 *   roles              — shared RBAC metadata; read-only for authenticated users
 *   permissions        — shared RBAC metadata; read-only for authenticated users
 *   role_permissions   — shared RBAC metadata; read-only for authenticated users
 *
 * The following 11 tables intentionally remain WITHOUT RLS (see
 * SECURITY.md §16 justification):
 *
 *   Framework/queue infrastructure (no sensitive data, not API-exposed):
 *     cache, cache_locks, jobs, job_batches, failed_jobs, migrations
 *
 *   Authentication infrastructure (login flow requires unscoped access;
 *   security boundary is application middleware, not database RLS):
 *     users, refresh_tokens, mfa_challenges, password_reset_tokens,
 *     personal_access_tokens
 *
 * The policies use the same Supabase-compatible claim helpers as all other
 * RLS policies (swasthya_rls_* functions from 2026_08_13_100200).
 *
 * @see SECURITY.md §16 for the complete classification justification
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- organizations ---
        DB::statement('alter table public."organizations" enable row level security');
        DB::statement('alter table public."organizations" force row level security');

        DB::statement(<<<'SQL'
            create policy p_rls_organizations_select on public."organizations"
            for select using (
                swasthya_rls_is_platform()
                OR id = swasthya_rls_tenant_id()
            )
        SQL);

        DB::statement(<<<'SQL'
            create policy p_rls_organizations_insert on public."organizations"
            for insert with check (
                swasthya_rls_is_platform()
            )
        SQL);

        DB::statement(<<<'SQL'
            create policy p_rls_organizations_update on public."organizations"
            for update using (
                swasthya_rls_is_platform()
                OR id = swasthya_rls_tenant_id()
            ) with check (
                swasthya_rls_is_platform()
                OR id = swasthya_rls_tenant_id()
            )
        SQL);

        DB::statement(<<<'SQL'
            create policy p_rls_organizations_delete on public."organizations"
            for delete using (
                swasthya_rls_is_platform()
            )
        SQL);

        // --- roles ---
        // Shared RBAC metadata: SELECT open to all authenticated users,
        // writes permissive (application middleware controls write authorization).
        DB::statement('alter table public."roles" enable row level security');
        DB::statement('alter table public."roles" force row level security');

        DB::statement(<<<'SQL'
            create policy p_rls_roles_select on public."roles"
            for select using (true)
        SQL);
        DB::statement(<<<'SQL'
            create policy p_rls_roles_insert on public."roles"
            for insert with check (true)
        SQL);
        DB::statement(<<<'SQL'
            create policy p_rls_roles_update on public."roles"
            for update using (true) with check (true)
        SQL);
        DB::statement(<<<'SQL'
            create policy p_rls_roles_delete on public."roles"
            for delete using (true)
        SQL);

        // --- permissions ---
        DB::statement('alter table public."permissions" enable row level security');
        DB::statement('alter table public."permissions" force row level security');

        DB::statement(<<<'SQL'
            create policy p_rls_permissions_select on public."permissions"
            for select using (true)
        SQL);
        DB::statement(<<<'SQL'
            create policy p_rls_permissions_insert on public."permissions"
            for insert with check (true)
        SQL);
        DB::statement(<<<'SQL'
            create policy p_rls_permissions_update on public."permissions"
            for update using (true) with check (true)
        SQL);
        DB::statement(<<<'SQL'
            create policy p_rls_permissions_delete on public."permissions"
            for delete using (true)
        SQL);

        // --- role_permissions ---
        DB::statement('alter table public."role_permissions" enable row level security');
        DB::statement('alter table public."role_permissions" force row level security');

        DB::statement(<<<'SQL'
            create policy p_rls_role_permissions_select on public."role_permissions"
            for select using (true)
        SQL);
        DB::statement(<<<'SQL'
            create policy p_rls_role_permissions_insert on public."role_permissions"
            for insert with check (true)
        SQL);
        DB::statement(<<<'SQL'
            create policy p_rls_role_permissions_update on public."role_permissions"
            for update using (true) with check (true)
        SQL);
        DB::statement(<<<'SQL'
            create policy p_rls_role_permissions_delete on public."role_permissions"
            for delete using (true)
        SQL);
    }

    public function down(): void
    {
        foreach (['organizations', 'roles', 'permissions', 'role_permissions'] as $table) {
            DB::statement("drop policy if exists p_rls_{$table}_select on public.\"{$table}\"");
            DB::statement("drop policy if exists p_rls_{$table}_insert on public.\"{$table}\"");
            DB::statement("drop policy if exists p_rls_{$table}_update on public.\"{$table}\"");
            DB::statement("drop policy if exists p_rls_{$table}_delete on public.\"{$table}\"");
            DB::statement("alter table public.\"{$table}\" disable row level security");
        }
    }
};
