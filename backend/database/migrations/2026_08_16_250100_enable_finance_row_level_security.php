<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 18 — RLS for the Billing and Finance surface (deposits,
 * deposit_allocations, settlements, claims, claim_lines).
 *
 * Two tiers (DATABASE.md §3.33–3.35, SECURITY.md §8, TENANCY.md §6.2):
 *
 *   TENANT_FACILITY (deposits, deposit_allocations, settlements):
 *     SELECT/UPDATE/DELETE using  tenant_id = <tenant> AND
 *                                (facility_id = <facility> OR <facility> IS NULL)
 *   TENANT (claims, claim_lines — no facility_id, per §3.35):
 *     SELECT/UPDATE/DELETE using  tenant_id = <tenant>
 *   INSERT with check (true)      — the established, documented boundary
 *
 * The expressions read ONLY the Supabase-compatible `request.jwt.claims` GUC
 * through the stable helpers (2026_08_13_100200). The runtime role
 * (swasthya_app, NOBYPASSRLS) is bound; FORCE binds the table owner as well
 * (Phase 1 hardening).
 *
 * Policy count added: 5 tables × 4 policies = 20 (248 → 268).
 * Scoped matrix: 63 → 68 tables (still 15 off).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_FACILITY_TABLES = ['deposits', 'deposit_allocations', 'settlements'];

    /** @var list<string> */
    private const TENANT_TABLES = ['claims', 'claim_lines'];

    public function up(): void
    {
        foreach (self::TENANT_FACILITY_TABLES as $table) {
            $using = 'tenant_id = public.swasthya_rls_tenant_id()'
                .' AND (facility_id = public.swasthya_rls_facility_id() OR public.swasthya_rls_facility_id() IS NULL)';
            $this->createPolicies($table, $using);
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");
        }

        foreach (self::TENANT_TABLES as $table) {
            $this->createPolicies($table, 'tenant_id = public.swasthya_rls_tenant_id()');
            DB::statement("alter table {$table} enable row level security");
            DB::statement("alter table {$table} force row level security");
        }
    }

    public function down(): void
    {
        foreach ([...self::TENANT_FACILITY_TABLES, ...self::TENANT_TABLES] as $table) {
            DB::statement("alter table {$table} no force row level security");
            DB::statement("alter table {$table} disable row level security");
            foreach (['select', 'insert', 'update', 'delete'] as $op) {
                DB::statement("drop policy if exists p_rls_{$table}_{$op} on {$table}");
            }
        }
    }

    private function createPolicies(string $table, string $using): void
    {
        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");
        DB::statement(
            "create policy p_rls_{$table}_update on {$table} for update using ({$using}) with check ({$using})"
        );
        DB::statement("create policy p_rls_{$table}_delete on {$table} for delete using ({$using})");
    }
};
