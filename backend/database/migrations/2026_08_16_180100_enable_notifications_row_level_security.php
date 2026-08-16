<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 10 — RLS for the follow-up reminder surface (notifications).
 *
 * TENANT tier — per DATABASE.md §3.37 the table is tenant-scoped with no
 * facility_id, so the policy shape is the tenant-only form (the same shape
 * used by the TENANT_ONLY tables — SECURITY.md §8, TENANCY.md §6.2):
 *
 *   SELECT/UPDATE/DELETE using  tenant_id = <tenant>
 *   INSERT with check (true)    — the established, documented boundary
 *
 * The expressions read ONLY the Supabase-compatible `request.jwt.claims` GUC
 * through the stable helpers (2026_08_13_100200). The runtime role
 * (swasthya_app, NOBYPASSRLS) is bound; FORCE binds the table owner as well
 * (Phase 1 hardening).
 *
 * Policy count added: 1 table × 4 policies = 4 (184 → 188).
 * Scoped matrix: 47 → 48 tables (still 15 off).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['notifications'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->createPolicies($table);
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("alter table {$table} no force row level security");
            DB::statement("alter table {$table} disable row level security");
            foreach (['select', 'insert', 'update', 'delete'] as $op) {
                DB::statement("drop policy if exists p_rls_{$table}_{$op} on {$table}");
            }
        }
    }

    private function createPolicies(string $table): void
    {
        $using = 'tenant_id = public.swasthya_rls_tenant_id()';

        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");
        DB::statement(
            "create policy p_rls_{$table}_update on {$table} for update using ({$using}) with check ({$using})"
        );
        DB::statement("create policy p_rls_{$table}_delete on {$table} for delete using ({$using})");
    }
};
