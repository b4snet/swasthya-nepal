<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule exceptions (DATABASE.md §3.16): leave, holiday, or a blocked
 * date for a provider — availability is derived from templates minus these
 * exceptions. One exception per (staff, date).
 *
 * Tenant-scoped with tenant-safe composite FKs to facilities and staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('staff_id');
            $table->uuid('template_id')->nullable();
            $table->date('exception_date');
            $table->text('reason'); // leave, holiday, block
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table schedule_exceptions add constraint chk_schedule_exceptions_reason check (reason in ('leave', 'holiday', 'block'))"
        );
        DB::statement(
            "alter table schedule_exceptions add constraint chk_schedule_exceptions_status check (status in ('active', 'cancelled'))"
        );

        DB::statement(
            'create unique index uq_schedule_exceptions_tenant_staff_date on schedule_exceptions (tenant_id, staff_id, exception_date)'
        );
        DB::statement('create index idx_schedule_exceptions_tenant_facility on schedule_exceptions (tenant_id, facility_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
    }
};
