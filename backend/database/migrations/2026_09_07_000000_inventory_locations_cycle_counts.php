<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 6 Part 1: Inventory Locations/Bins, Bin Stock, Cycle Counts
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Inventory Locations / Bins ────────────────────────────────────
        Schema::table('locations', function (Blueprint $table): void {
            $table->text('location_type')->nullable()->after('ward_id');
            $table->text('aisle')->nullable()->after('location_type');
            $table->text('rack')->nullable()->after('aisle');
            $table->text('shelf')->nullable()->after('rack');
            $table->text('bin')->nullable()->after('shelf');
            $table->bigInteger('capacity_minor')->nullable()->after('bin');
            $table->text('temperature_zone')->nullable()->after('capacity_minor');
            $table->boolean('is_pickable')->default(true)->after('temperature_zone');
            $table->boolean('is_receivable')->default(true)->after('is_pickable');
        });

        DB::statement(
            'create unique index uq_locations_tenant_facility_aisle_rack_shelf_bin on locations (tenant_id, facility_id, aisle, rack, shelf, bin) where deleted_at is null and aisle is not null and rack is not null and shelf is not null and bin is not null'
        );

        // ── Inventory Bin Stock ───────────────────────────────────────────
        Schema::create('inventory_bin_stocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('inventory_item_id');
            $table->uuid('location_id');
            $table->bigInteger('quantity_on_hand')->default(0);
            $table->bigInteger('quantity_reserved')->default(0);
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'inventory_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('inventory_items')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'location_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('locations')
                ->restrictOnDelete();

            $table->unique(['tenant_id', 'inventory_item_id', 'location_id'], 'uq_inventory_bin_stock_item_location');
            $table->index(['tenant_id', 'facility_id', 'location_id'], 'idx_inventory_bin_stock_location');
        });

        // ── Cycle Counts / Physical Inventory ─────────────────────────────
        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->text('count_number')->nullable();
            $table->text('status');
            $table->text('count_type');
            $table->uuid('initiated_by');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->date('count_date');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'initiated_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'approved_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'status'], 'idx_stock_counts_tenant_status');
        });

        DB::statement('create unique index uq_stock_counts_tenant_number on stock_counts (tenant_id, count_number) where deleted_at is null and count_number is not null');
        DB::statement('create unique index uq_stock_counts_tenant_id on stock_counts (tenant_id, id)');

        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('stock_count_id');
            $table->uuid('inventory_item_id');
            $table->uuid('location_id')->nullable();
            $table->uuid('stock_batch_id')->nullable();
            $table->bigInteger('system_quantity')->default(0);
            $table->bigInteger('counted_quantity')->nullable();
            $table->bigInteger('variance')->nullable();
            $table->text('variance_reason')->nullable();
            $table->text('status');
            $table->uuid('counted_by')->nullable();
            $table->timestampTz('counted_at')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'stock_count_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_counts')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'inventory_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('inventory_items')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'location_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('locations')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'stock_batch_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_batches')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'stock_count_id'], 'idx_stock_count_lines_count');
            $table->index(['tenant_id', 'inventory_item_id'], 'idx_stock_count_lines_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('inventory_bin_stocks');

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn(['location_type', 'aisle', 'rack', 'shelf', 'bin', 'capacity_minor', 'temperature_zone', 'is_pickable', 'is_receivable']);
        });
    }
};