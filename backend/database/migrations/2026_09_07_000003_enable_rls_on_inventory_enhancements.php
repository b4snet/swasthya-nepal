<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RLS enablement for Step 6 Inventory enhancements (all parts).
 */
return new class extends Migration
{
    private function rls(string $table): void
    {
        DB::statement("alter table {$table} enable row level security");
        DB::statement("alter table {$table} force row level security");

        DB::statement("create policy {$table}_select on {$table} for select to swasthya_app using (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))");
        DB::statement("create policy {$table}_insert on {$table} for insert to swasthya_app with check (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))");
        DB::statement("create policy {$table}_update on {$table} for update to swasthya_app using (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null)) with check (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))");
        DB::statement("create policy {$table}_delete on {$table} for delete to swasthya_app using (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))");
    }

    public function up(): void
    {
        // Part 1 tables
        $this->rls('inventory_bin_stocks');
        $this->rls('stock_counts');
        $this->rls('stock_count_lines');

        // Part 2 tables
        $this->rls('vendor_quotations');
        $this->rls('vendor_quotation_lines');
        $this->rls('goods_receipt_inspections');
        $this->rls('goods_receipt_inspection_lines');
        $this->rls('inventory_reservations');

        // Part 3 tables
        $this->rls('recalls');
        $this->rls('recall_batches');
        $this->rls('expiry_alerts');
    }

    public function down(): void
    {
        foreach (['expiry_alerts', 'recall_batches', 'recalls', 'inventory_reservations', 'goods_receipt_inspection_lines', 'goods_receipt_inspections', 'vendor_quotation_lines', 'vendor_quotations', 'stock_count_lines', 'stock_counts', 'inventory_bin_stocks'] as $table) {
            DB::statement("drop policy if exists {$table}_select on {$table}");
            DB::statement("drop policy if exists {$table}_insert on {$table}");
            DB::statement("drop policy if exists {$table}_update on {$table}");
            DB::statement("drop policy if exists {$table}_delete on {$table}");

            DB::statement("alter table {$table} no force row level security");
            DB::statement("alter table {$table} disable row level security");
        }
    }
};