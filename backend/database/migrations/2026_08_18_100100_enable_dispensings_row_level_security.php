<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — RLS for dispensings (the standalone dispensing record).
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
 * Policy count added: 1 table × 4 policies = 4 (508 → 512).
 * RLS matrix: 128 → 129 tables on (still 15 off).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createPolicies();
        DB::statement('alter table dispensings enable row level security');
        DB::statement('alter table dispensings force row level security');
    }

    public function down(): void
    {
        DB::statement('alter table dispensings no force row level security');
        DB::statement('alter table dispensings disable row level security');
        foreach (['select', 'insert', 'update', 'delete'] as $op) {
            DB::statement("drop policy if exists p_rls_dispensings_{$op} on dispensings");
        }
    }

    private function createPolicies(): void
    {
        $using = 'tenant_id = public.swasthya_rls_tenant_id()'
            .' AND (facility_id = public.swasthya_rls_facility_id() OR public.swasthya_rls_facility_id() IS NULL)';

        DB::statement("create policy p_rls_dispensings_select on dispensings for select using ({$using})");
        DB::statement('create policy p_rls_dispensings_insert on dispensings for insert with check (true)');
        DB::statement(
            "create policy p_rls_dispensings_update on dispensings for update using ({$using}) with check ({$using})"
        );
        DB::statement("create policy p_rls_dispensings_delete on dispensings for delete using ({$using})");
    }
};
