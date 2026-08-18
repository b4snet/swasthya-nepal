<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 14 — RLS for the inventory/procurement tables (2026_08_18_230000).
 *
 * TENANT_FACILITY tier — the exact same policy shape as every other
 * pharmacy/billing table (SECURITY.md §8, TENANCY.md §6.2):
 *
 *   SELECT/UPDATE/DELETE using  tenant_id = <tenant> AND
 *                              (facility_id = <facility> OR <facility> IS NULL)
 *   INSERT with check (true)   — the established, documented boundary
 *
 * The expressions read ONLY the Supabase-compatible `request.jwt.claims` GUC
 * through the stable helpers (2026_08_13_100200). Missing/empty claims →
 * NULL → zero access. The runtime role (swasthya_app, NOBYPASSRLS) is bound;
 * FORCE binds the table owner as well (Phase 1 hardening).
 *
 * Policy count added: 11 tables × 4 policies = 44 (512 → 556).
 * RLS matrix: 129 → 140 tables on (still 15 off).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'inventory_transfers',
        'inventory_adjustment_requests',
        'vendors',
        'purchase_requests',
        'purchase_request_lines',
        'purchase_request_approvals',
        'purchase_orders',
        'purchase_order_lines',
        'goods_receipts',
        'goods_receipt_lines',
        'vendor_contracts',
    ];

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
        // purchase_request_approvals has no facility_id column — tenant-only.
        $hasFacility = $table !== 'purchase_request_approvals';

        $using = 'tenant_id = public.swasthya_rls_tenant_id()'
            .($hasFacility
                ? ' AND (facility_id = public.swasthya_rls_facility_id() OR public.swasthya_rls_facility_id() IS NULL)'
                : '');

        DB::statement("create policy p_rls_{$table}_select on {$table} for select using ({$using})");
        DB::statement("create policy p_rls_{$table}_insert on {$table} for insert with check (true)");
        DB::statement(
            "create policy p_rls_{$table}_update on {$table} for update using ({$using}) with check ({$using})"
        );
        DB::statement("create policy p_rls_{$table}_delete on {$table} for delete using ({$using})");
    }
};
