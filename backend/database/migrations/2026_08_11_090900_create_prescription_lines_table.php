<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prescription lines (DATABASE.md §3.21): one medication with dose, route,
 * frequency — the source of dispensing and interaction checks.
 *
 * Tenant-scoped with tenant-safe composite FKs to prescriptions and
 * medications. `line_no` is unique per prescription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('prescription_id');
            $table->uuid('medication_id');
            $table->text('dose');
            $table->text('route');
            $table->text('frequency');
            $table->text('duration')->nullable();
            $table->bigInteger('quantity_minor')->nullable();
            $table->text('instructions')->nullable();
            $table->text('status')->default('ordered'); // ordered, dispensed, cancelled
            $table->integer('line_no');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'prescription_id'])
                ->references(['tenant_id', 'id'])
                ->on('prescriptions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'medication_id'])
                ->references(['tenant_id', 'id'])
                ->on('medications')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table prescription_lines add constraint chk_prescription_lines_status check (status in ('ordered', 'dispensed', 'cancelled'))"
        );

        DB::statement(
            'create unique index uq_prescription_lines_tenant_rx_line on prescription_lines (tenant_id, prescription_id, line_no)'
        );
        DB::statement('create index idx_prescription_lines_tenant_rx on prescription_lines (tenant_id, prescription_id)');
        DB::statement('create index idx_prescription_lines_tenant_med on prescription_lines (tenant_id, medication_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_lines');
    }
};
