<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 4 — RLS for follow_ups.
 *
 * TENANT_FACILITY tier — the exact same policy shape as medications,
 * encounters, lab, and inventory (SECURITY.md §8, TENANCY.md §6.2):
 *
 *   SELECT/UPDATE/DELETE using  tenant_id = <tenant> AND
 *                              (facility_id = <facility> OR <facility> IS NULL)
 *   INSERT with check (true)   — the established, documented boundary
 *                                (INSERT isolation is the application layer,
 *                                backstopped by composite FKs)
 *
 * The expressions read ONLY the Supabase-compatible `request.jwt.claims` GUC
 * through the stable helpers (2026_08_13_100200). Missing/empty claims →
 * NULL → zero access. The runtime role (swasthya_app, NOBYPASSRLS) is bound;
 * FORCE binds the table owner as well (Phase 1 hardening).
 *
 * Policy count added: 1 table × 4 policies = 4 (164 → 168).
 * Scoped matrix: 42 → 43 tables on (still 15 off).
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = 'follow_ups';
        $using = 'tenant_id = public.swasthya_rls_tenant_id()'
            .' AND (facility_id = public.swasthya_rls_facility_id() OR public.swasthya_rls_facility_id() IS NULL)';

        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");
        DB::statement(
            "create policy p_rls_{$table}_update on {$table} for update using ({$using}) with check ({$using})"
        );
        DB::statement("create policy p_rls_{$table}_delete on {$table} for delete using ({$using})");

        DB::statement("alter table {$table} enable row level security");
        DB::statement("alter table {$table} force row level security");
    }

    public function down(): void
    {
        $table = 'follow_ups';
        DB::statement("alter table {$table} no force row level security");
        DB::statement("alter table {$table} disable row level security");
        foreach (['select', 'insert', 'update', 'delete'] as $op) {
            DB::statement("drop policy if exists p_rls_{$table}_{$op} on {$table}");
        }
    }
};
