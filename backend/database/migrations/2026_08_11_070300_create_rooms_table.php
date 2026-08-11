<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms (DATABASE.md §3.25): a room within a ward (or standalone), containing
 * beds; carries room-type and charge-rate configuration.
 *
 * Tenant-scoped with tenant-safe composite FKs to facilities and wards
 * (DATABASE.md §0.9) — a room's ward can never belong to another tenant or
 * facility. Rates are financial truth for bed charges: every rate change is
 * audited (DATABASE.md §3.25). Money is integer minor units, never floats
 * (DATABASE.md §0.4).
 *
 * Soft-deletable, but RESTRICT while beds exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('ward_id');
            $table->string('name');
            $table->string('code', 50);
            $table->text('room_type')->default('general');
            $table->bigInteger('daily_rate_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        // Unique (tenant_id, facility_id, id) backs the beds composite FK.
        DB::statement('create unique index uq_rooms_tenant_facility_id on rooms (tenant_id, facility_id, id)');

        Schema::table('rooms', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'ward_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('wards')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table rooms add constraint chk_rooms_type check (room_type in ('general', 'private', 'semi_private', 'icu', 'other'))"
        );
        DB::statement(
            "alter table rooms add constraint chk_rooms_status check (status in ('active', 'inactive'))"
        );
        DB::statement(
            'alter table rooms add constraint chk_rooms_currency check (currency is null or char_length(currency) = 3)'
        );

        DB::statement(
            'create unique index uq_rooms_tenant_facility_code on rooms (tenant_id, facility_id, code) where deleted_at is null'
        );
        DB::statement('create index idx_rooms_tenant_ward on rooms (tenant_id, ward_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
