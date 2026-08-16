<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 8 — RLS for pharmacy_returns.
 *
 * TENANT_FACILITY tier — the exact same policy shape as the other pharmacy
 * and billing tables (SECURITY.md §8, TENANCY.md §6.2):
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
 * Policy count added: 1 table × 4 policies = 4 (180 → 184).
 * Scoped matrix: 46 → 47 tables on (still 15 off).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createPolicies();
        DB::statement('alter table pharmacy_returns enable row level security');
        DB::statement('alter table pharmacy_returns force row level security');
    }

    public function down(): void
    {
        DB::statement('alter table pharmacy_returns no force row level security');
        DB::statement('alter table pharmacy_returns disable row level security');
        foreach (['select', 'insert', 'update', 'delete'] as $op) {
            DB::statement("drop policy if exists p_rls_pharmacy_returns_{$op} on pharmacy_returns");
        }
    }

    private function createPolicies(): void
    {
        $using = 'tenant_id = public.swasthya_rls_tenant_id()'
            .' AND (facility_id = public.swasthya_rls_facility_id() OR public.swasthya_rls_facility_id() IS NULL)';

        DB::statement("create policy p_rls_pharmacy_returns_select on pharmacy_returns for select using ({$using})");
        DB::statement('create policy p_rls_pharmacy_returns_insert on pharmacy_returns for insert with check (true)');
        DB::statement(
            "create policy p_rls_pharmacy_returns_update on pharmacy_returns for update using ({$using}) with check ({$using})"
        );
        DB::statement("create policy p_rls_pharmacy_returns_delete on pharmacy_returns for delete using ({$using})");
    }
};
