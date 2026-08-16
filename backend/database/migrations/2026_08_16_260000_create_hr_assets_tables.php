<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 19 — HR and Assets (ROADMAP Phase 15, PRODUCT_REQUIREMENTS
 * §6.17–6.18, DATABASE.md §3.45–3.47).
 *
 * HR (Phase 2): positions (department-linked position catalog), shift
 * templates (day/night/rotating), rosters (staff × shift × date with
 * conflict detection), attendance records (clock-in/out or schedule-based,
 * corrections WITH approval), leave types (entitlements) + leave requests
 * (request → approval → balance), and audited payroll-ready exports (who
 * exported what — the acceptance criterion "payroll export is accurate and
 * audited").
 *
 * Assets (Phase 3): asset categories + asset register with an explicit
 * lifecycle (procured → deployed → under_repair → retired), append-only
 * asset transfers (location history), maintenance schedules + work orders
 * with honest downtime tracking (a machine listed as available while down
 * is a planning hazard) and certification records, and iot_readings (the
 * RFID/IoT-ready data model — tag/location/condition/usage feeds are
 * DESIGNED now; no device integration is faked).
 *
 * Every tenant-owned table carries the tenant-safe composite FKs
 * (DATABASE.md §0.9): parents' (tenant, facility, id) / (tenant, id)
 * backers are declared BEFORE the child FKs that reference them. Staff
 * personal data is protected to the same standard as patient data
 * (SECURITY.md §12, MASTER_RULES.md §10): names/licenses are never in
 * audit payloads and HR rows are RLS-scoped like clinical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────── HR (Phase 2) ───────────────────────────

        Schema::create('positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('department_id');
            $table->string('code', 50);
            $table->string('name');
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id', 'department_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('departments')
                ->restrictOnDelete();
        });

        DB::statement("alter table positions add constraint chk_positions_status check (status in ('active', 'inactive'))");
        DB::statement('create unique index uq_positions_tenant_facility_code on positions (tenant_id, facility_id, code) where deleted_at is null');
        DB::statement('create index idx_positions_tenant_facility on positions (tenant_id, facility_id)');

        Schema::create('shift_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('department_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('shift_type')->default('day'); // day | night | rotating
            $table->time('starts_at');
            $table->time('ends_at');
            $table->integer('working_minutes');
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id', 'department_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('departments')
                ->restrictOnDelete();
        });

        DB::statement("alter table shift_templates add constraint chk_shift_templates_type check (shift_type in ('day', 'night', 'rotating'))");
        DB::statement("alter table shift_templates add constraint chk_shift_templates_status check (status in ('active', 'inactive'))");
        DB::statement('alter table shift_templates add constraint chk_shift_templates_minutes check (working_minutes between 1 and 1440)');
        DB::statement('create unique index uq_shift_templates_tenant_facility_code on shift_templates (tenant_id, facility_id, code) where deleted_at is null');
        DB::statement('create index idx_shift_templates_tenant_facility on shift_templates (tenant_id, facility_id)');
        // Backer for the rosters composite FK.
        DB::statement('create unique index uq_shift_templates_tenant_facility_id on shift_templates (tenant_id, facility_id, id)');

        Schema::create('rosters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('staff_id');
            $table->uuid('shift_template_id');
            $table->date('roster_date');
            $table->text('status')->default('scheduled'); // scheduled | confirmed | cancelled
            $table->text('notes')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'shift_template_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('shift_templates')
                ->restrictOnDelete();
        });

        DB::statement("alter table rosters add constraint chk_rosters_status check (status in ('scheduled', 'confirmed', 'cancelled'))");
        // One row per (staff, shift, date); overlap/rest-rule conflicts are
        // application-enforced (the established schedule pattern).
        DB::statement('create unique index uq_rosters_tenant_staff_shift_date on rosters (tenant_id, facility_id, staff_id, shift_template_id, roster_date)');
        DB::statement('create index idx_rosters_tenant_facility_date on rosters (tenant_id, facility_id, roster_date)');
        DB::statement('create index idx_rosters_tenant_staff_date on rosters (tenant_id, staff_id, roster_date)');

        Schema::create('attendance_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('staff_id');
            $table->date('attendance_date');
            $table->timestampTz('clock_in_at')->nullable();
            $table->timestampTz('clock_out_at')->nullable();
            $table->text('status')->default('present'); // present | absent | late | leave
            $table->text('source')->default('clock'); // clock | schedule | manual
            $table->text('correction_status')->default('none'); // none | pending | approved | rejected
            $table->text('correction_reason')->nullable();
            $table->timestampTz('correction_proposed_clock_in_at')->nullable();
            $table->timestampTz('correction_proposed_clock_out_at')->nullable();
            $table->uuid('correction_requested_by')->nullable();
            $table->uuid('correction_approved_by')->nullable();
            $table->timestampTz('correction_approved_at')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table attendance_records add constraint chk_attendance_status check (status in ('present', 'absent', 'late', 'leave'))");
        DB::statement("alter table attendance_records add constraint chk_attendance_source check (source in ('clock', 'schedule', 'manual'))");
        DB::statement("alter table attendance_records add constraint chk_attendance_correction check (correction_status in ('none', 'pending', 'approved', 'rejected'))");
        DB::statement('create unique index uq_attendance_tenant_staff_date on attendance_records (tenant_id, facility_id, staff_id, attendance_date)');
        DB::statement('create index idx_attendance_tenant_facility_date on attendance_records (tenant_id, facility_id, attendance_date)');

        Schema::create('leave_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('paid_days_per_year')->default(0);
            $table->integer('carryover_days')->default(0);
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement("alter table leave_types add constraint chk_leave_types_status check (status in ('active', 'inactive'))");
        DB::statement('alter table leave_types add constraint chk_leave_types_days check (paid_days_per_year >= 0 and carryover_days >= 0)');
        DB::statement('create unique index uq_leave_types_tenant_facility_code on leave_types (tenant_id, facility_id, code) where deleted_at is null');
        DB::statement('create index idx_leave_types_tenant_facility on leave_types (tenant_id, facility_id)');
        // Backer for the leave_requests composite FK.
        DB::statement('create unique index uq_leave_types_tenant_facility_id on leave_types (tenant_id, facility_id, id)');

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('staff_id');
            $table->uuid('leave_type_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->integer('days_requested');
            $table->text('reason')->nullable();
            $table->text('status')->default('pending'); // pending | approved | rejected | cancelled
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'leave_type_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('leave_types')
                ->restrictOnDelete();
        });

        DB::statement("alter table leave_requests add constraint chk_leave_requests_status check (status in ('pending', 'approved', 'rejected', 'cancelled'))");
        DB::statement('alter table leave_requests add constraint chk_leave_requests_days check (days_requested > 0)');
        DB::statement('alter table leave_requests add constraint chk_leave_requests_range check (ends_on >= starts_on)');
        DB::statement('create index idx_leave_requests_tenant_staff on leave_requests (tenant_id, staff_id, starts_on)');
        DB::statement('create index idx_leave_requests_tenant_facility_status on leave_requests (tenant_id, facility_id, status)');

        Schema::create('payroll_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->uuid('exported_by_staff_id')->nullable();
            $table->integer('row_count')->default(0);
            $table->text('format')->default('payroll_ready'); // payroll_ready | csv
            $table->text('payload_hash')->nullable();
            $table->timestampTz('exported_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'exported_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table payroll_exports add constraint chk_payroll_exports_format check (format in ('payroll_ready', 'csv'))");
        DB::statement('alter table payroll_exports add constraint chk_payroll_exports_period check (period_end >= period_start)');
        DB::statement('create index idx_payroll_exports_tenant_facility_period on payroll_exports (tenant_id, facility_id, period_start, period_end)');

        // ───────────────────────── Assets (Phase 3) ─────────────────────────

        // Locations composite-FK backer (asset transfers / asset location
        // reference (tenant, facility, id)).
        DB::statement('create unique index uq_locations_tenant_facility_id on locations (tenant_id, facility_id, id)');

        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->string('name');
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement("alter table asset_categories add constraint chk_asset_categories_status check (status in ('active', 'inactive'))");
        DB::statement('create unique index uq_asset_categories_tenant_facility_code on asset_categories (tenant_id, facility_id, code) where deleted_at is null');
        DB::statement('create index idx_asset_categories_tenant_facility on asset_categories (tenant_id, facility_id)');
        // Backer for the assets composite FK.
        DB::statement('create unique index uq_asset_categories_tenant_facility_id on asset_categories (tenant_id, facility_id, id)');

        Schema::create('assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('category_id');
            $table->string('name');
            $table->string('serial_number', 100)->nullable();
            $table->string('rfid_tag', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->uuid('current_location_id')->nullable();
            $table->integer('purchase_value_minor')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('warranty_until')->nullable();
            $table->text('lifecycle_status')->default('procured'); // procured | deployed | under_repair | retired
            $table->text('status')->default('active'); // active | inactive
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'category_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('asset_categories')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'current_location_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('locations')
                ->restrictOnDelete();
        });

        DB::statement("alter table assets add constraint chk_assets_lifecycle check (lifecycle_status in ('procured', 'deployed', 'under_repair', 'retired'))");
        DB::statement("alter table assets add constraint chk_assets_status check (status in ('active', 'inactive'))");
        DB::statement('alter table assets add constraint chk_assets_value check (purchase_value_minor is null or purchase_value_minor >= 0)');
        DB::statement('create unique index uq_assets_tenant_serial on assets (tenant_id, facility_id, serial_number) where serial_number is not null');
        DB::statement('create unique index uq_assets_tenant_rfid on assets (tenant_id, facility_id, rfid_tag) where rfid_tag is not null');
        DB::statement('create unique index uq_assets_tenant_barcode on assets (tenant_id, facility_id, barcode) where barcode is not null');
        DB::statement('create index idx_assets_tenant_facility_lifecycle on assets (tenant_id, facility_id, lifecycle_status)');
        // Backer for the asset_transfers / maintenance_schedules / work_orders
        // / iot_readings composite FKs.
        DB::statement('create unique index uq_assets_tenant_facility_id on assets (tenant_id, facility_id, id)');

        Schema::create('asset_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('asset_id');
            $table->uuid('from_location_id')->nullable();
            $table->uuid('to_location_id');
            $table->timestampTz('transferred_at');
            $table->uuid('transferred_by_staff_id')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'asset_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('assets')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'to_location_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('locations')
                ->restrictOnDelete();
        });

        DB::statement('create index idx_asset_transfers_tenant_asset on asset_transfers (tenant_id, asset_id, transferred_at)');

        Schema::create('maintenance_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('asset_id');
            $table->text('schedule_type')->default('preventive'); // preventive | contract | certification
            $table->integer('frequency_days');
            $table->date('next_due_date');
            $table->date('last_completed_at')->nullable();
            $table->text('contract_ref')->nullable();
            $table->text('status')->default('active');
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'asset_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('assets')
                ->restrictOnDelete();
        });

        DB::statement("alter table maintenance_schedules add constraint chk_maint_schedule_type check (schedule_type in ('preventive', 'contract', 'certification'))");
        DB::statement("alter table maintenance_schedules add constraint chk_maint_schedule_status check (status in ('active', 'inactive'))");
        DB::statement('alter table maintenance_schedules add constraint chk_maint_schedule_frequency check (frequency_days > 0)');
        DB::statement('create index idx_maint_schedules_tenant_asset_due on maintenance_schedules (tenant_id, asset_id, next_due_date)');
        // Backer for the work_orders composite FK.
        DB::statement('create unique index uq_maint_schedules_tenant_facility_id on maintenance_schedules (tenant_id, facility_id, id)');

        Schema::create('work_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('asset_id');
            $table->uuid('maintenance_schedule_id')->nullable();
            $table->string('work_order_number', 50);
            $table->text('status')->default('open'); // open | in_progress | completed | cancelled
            $table->timestampTz('opened_at');
            $table->uuid('opened_by_staff_id')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by_staff_id')->nullable();
            $table->timestampTz('downtime_started_at')->nullable();
            $table->timestampTz('downtime_ended_at')->nullable();
            $table->text('description')->nullable();
            $table->text('certification_ref')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'asset_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('assets')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'maintenance_schedule_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('maintenance_schedules')
                ->restrictOnDelete();
        });

        DB::statement("alter table work_orders add constraint chk_work_orders_status check (status in ('open', 'in_progress', 'completed', 'cancelled'))");
        DB::statement('alter table work_orders add constraint chk_work_orders_downtime check (downtime_ended_at is null or downtime_started_at is null or downtime_ended_at > downtime_started_at)');
        DB::statement('create unique index uq_work_orders_tenant_number on work_orders (tenant_id, work_order_number)');
        DB::statement('create index idx_work_orders_tenant_asset_status on work_orders (tenant_id, asset_id, status)');

        Schema::create('iot_readings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('asset_id');
            $table->text('reading_type')->default('location'); // location | condition | usage
            $table->jsonb('reading_value')->default('{}');
            $table->string('tag_id', 100)->nullable();
            $table->timestampTz('read_at');
            $table->text('source')->default('manual'); // rfid | device | manual
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'asset_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('assets')
                ->restrictOnDelete();
        });

        DB::statement("alter table iot_readings add constraint chk_iot_readings_type check (reading_type in ('location', 'condition', 'usage'))");
        DB::statement("alter table iot_readings add constraint chk_iot_readings_source check (source in ('rfid', 'device', 'manual'))");
        DB::statement('create index idx_iot_readings_tenant_asset_time on iot_readings (tenant_id, asset_id, read_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('iot_readings');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('maintenance_schedules');
        Schema::dropIfExists('asset_transfers');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_categories');
        DB::statement('drop index if exists uq_locations_tenant_facility_id');
        Schema::dropIfExists('payroll_exports');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('rosters');
        Schema::dropIfExists('shift_templates');
        Schema::dropIfExists('positions');
    }
};
