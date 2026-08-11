<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Beds (DATABASE.md §3.26): the allocatable unit of inpatient capacity — live
 * state plus assignment history. Never soft-deleted: `out_of_service` is a
 * status, not a deletion.
 *
 * Tenant-scoped with a tenant-safe composite FK to rooms (DATABASE.md §0.9).
 * `current_admission_id` will be the tenant-safe composite FK to admissions
 * when the IPD phase ships (DATABASE.md §3.26); the column exists now so the
 * admission migration only adds the constraint. `lock_version` is the
 * optimistic-locking counter for concurrent state changes (DATABASE.md §0.7).
 *
 * Status is a state machine (DATABASE.md §0.5) — the only writer is the
 * application layer, which validates transitions and audits every change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('room_id');
            $table->string('bed_code', 20);
            $table->text('status')->default('available');
            $table->uuid('current_admission_id')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'room_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('rooms')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table beds add constraint chk_beds_status check (status in ('available', 'occupied', 'reserved', 'cleaning', 'out_of_service'))"
        );

        DB::statement('create unique index uq_beds_tenant_room_code on beds (tenant_id, room_id, bed_code)');
        DB::statement('create index idx_beds_tenant_status on beds (tenant_id, status)');
        // One bed per current admission (Phase 8 fills the FK + this guard).
        DB::statement(
            'create unique index uq_beds_tenant_current_admission on beds (tenant_id, current_admission_id) where current_admission_id is not null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('beds');
    }
};
