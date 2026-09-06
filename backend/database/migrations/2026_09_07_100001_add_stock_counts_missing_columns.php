<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_counts', 'medication_id')) {
                $table->uuid('medication_id')->nullable()->after('facility_id');
            }
            if (!Schema::hasColumn('stock_counts', 'inventory_item_id')) {
                $table->uuid('inventory_item_id')->nullable()->after('medication_id');
            }
            if (!Schema::hasColumn('stock_counts', 'expected_quantity')) {
                $table->integer('expected_quantity')->nullable()->after('inventory_item_id');
            }
            if (!Schema::hasColumn('stock_counts', 'counted_quantity')) {
                $table->integer('counted_quantity')->nullable()->after('expected_quantity');
            }
            if (!Schema::hasColumn('stock_counts', 'variance')) {
                $table->integer('variance')->nullable()->after('counted_quantity');
            }
            if (!Schema::hasColumn('stock_counts', 'reason')) {
                $table->text('reason')->nullable()->after('variance');
            }
            if (!Schema::hasColumn('stock_counts', 'counted_by_staff_id')) {
                $table->uuid('counted_by_staff_id')->nullable()->after('reason');
            }
            if (!Schema::hasColumn('stock_counts', 'reviewed_by_staff_id')) {
                $table->uuid('reviewed_by_staff_id')->nullable()->after('counted_by_staff_id');
            }
            if (!Schema::hasColumn('stock_counts', 'counted_at')) {
                $table->timestampTz('counted_at')->nullable()->after('reviewed_by_staff_id');
            }
            if (!Schema::hasColumn('stock_counts', 'reviewed_at')) {
                $table->timestampTz('reviewed_at')->nullable()->after('counted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->dropColumn([
                'medication_id', 'inventory_item_id', 'expected_quantity',
                'counted_quantity', 'variance', 'reason',
                'counted_by_staff_id', 'reviewed_by_staff_id',
                'counted_at', 'reviewed_at',
            ]);
        });
    }
};
