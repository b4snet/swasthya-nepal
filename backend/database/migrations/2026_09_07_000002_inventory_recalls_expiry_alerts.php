<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 6 Part 3: Recalls, Expiry Alerts
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Recall Management ─────────────────────────────────────────────
        Schema::create('recalls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->text('recall_number');
            $table->uuid('medication_id');
            $table->uuid('vendor_id')->nullable();
            $table->text('recall_class');
            $table->text('status');
            $table->text('reason');
            $table->text('action_required');
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
            $table->uuid('initiated_by');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
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

            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])
                ->on('medications')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'vendor_id'])
                ->references(['tenant_id', 'id'])
                ->on('vendors')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'initiated_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'status', 'medication_id'], 'idx_recalls_tenant_medication_status');
        });

        DB::statement('create unique index uq_recalls_tenant_number on recalls (tenant_id, recall_number) where deleted_at is null');
        DB::statement('create unique index uq_recalls_tenant_id on recalls (tenant_id, id)');

        Schema::create('recall_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('recall_id');
            $table->uuid('stock_batch_id');
            $table->bigInteger('quantity_affected');
            $table->text('status');
            $table->uuid('actioned_by')->nullable();
            $table->timestampTz('actioned_at')->nullable();
            $table->text('action_notes')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'recall_id'])
                ->references(['tenant_id', 'id'])
                ->on('recalls')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'stock_batch_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_batches')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'recall_id'], 'idx_recall_batches_recall');
            $table->index(['tenant_id', 'stock_batch_id'], 'idx_recall_batches_batch');
        });

        // ── Expiry Alerts / Notifications ─────────────────────────────────
        Schema::create('expiry_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('stock_batch_id');
            $table->text('alert_type');
            $table->text('status');
            $table->integer('days_to_expiry')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
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

            $table->foreign(['tenant_id', 'stock_batch_id'])
                ->references(['tenant_id', 'id'])
                ->on('stock_batches')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'acknowledged_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'status', 'alert_type'], 'idx_expiry_alerts_active');
        });

        DB::statement('create unique index uq_expiry_alerts_tenant_id on expiry_alerts (tenant_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expiry_alerts');
        Schema::dropIfExists('recall_batches');
        Schema::dropIfExists('recalls');
    }
};