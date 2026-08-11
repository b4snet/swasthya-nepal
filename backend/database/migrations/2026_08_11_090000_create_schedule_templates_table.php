<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doctor availability templates (DATABASE.md §3.16): recurring weekly
 * availability for a provider + service. Availability *slots* are derived
 * from templates minus exceptions minus existing bookings — never stored.
 *
 * Tenant-scoped with tenant-safe composite FKs to facilities and staff.
 * One template per (staff, weekday, start, validity window); a new template
 * supersedes by validity window, never by editing history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('staff_id');
            $table->uuid('service_id')->nullable();
            $table->smallInteger('day_of_week'); // 0 (Sun) .. 6 (Sat) — ISO 8601
            $table->time('starts_at');
            $table->time('ends_at');
            $table->smallInteger('slot_minutes');
            $table->smallInteger('capacity')->default(1);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'service_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table schedule_templates add constraint chk_schedule_templates_dow check (day_of_week between 0 and 6)'
        );
        DB::statement(
            'alter table schedule_templates add constraint chk_schedule_templates_time check (starts_at < ends_at)'
        );
        DB::statement(
            'alter table schedule_templates add constraint chk_schedule_templates_slot check (slot_minutes between 5 and 240)'
        );
        DB::statement(
            "alter table schedule_templates add constraint chk_schedule_templates_status check (status in ('active', 'inactive'))"
        );

        DB::statement(
            'create unique index uq_schedule_templates_tenant_staff_dow_start on schedule_templates (tenant_id, staff_id, day_of_week, starts_at, valid_from) where deleted_at is null'
        );
        DB::statement('create index idx_schedule_templates_tenant_facility on schedule_templates (tenant_id, facility_id)');
        DB::statement('create index idx_schedule_templates_tenant_staff_dow on schedule_templates (tenant_id, staff_id, day_of_week)');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_templates');
    }
};
