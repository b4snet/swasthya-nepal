<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appointments (DATABASE.md §3.15): patient × provider × slot — the booking
 * that backs queues, tokens, check-in, cancellation, and rescheduling.
 *
 * Tenant-scoped with tenant-safe composite FKs. The slot is guarded against
 * double-booking by a partial unique index on live statuses (the row-lock
 * booking guard — parallel requests for the same slot, one winner).
 * `token_no` is issued at check-in by the queue (TokenIssuer, row-locked
 * counter in `token_counters`).
 *
 * Status is a state machine (booked → checked_in → in_consultation →
 * completed; cancelled / no_show) — the application validates transitions
 * and audits every change. Never soft-deleted: a cancelled appointment is
 * history, not a deleted row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('provider_staff_id');
            $table->uuid('service_id')->nullable();
            $table->text('appointment_type')->default('opd');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('status')->default('booked');
            $table->text('cancel_reason')->nullable();
            $table->integer('token_no')->nullable();
            $table->text('source')->default('counter');
            $table->uuid('checked_in_by')->nullable();
            $table->timestampTz('checked_in_at')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'provider_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'service_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('services')
                ->restrictOnDelete();

            $table->foreign('checked_in_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table appointments add constraint chk_appointments_type check (appointment_type in ('opd', 'follow_up', 'procedure', 'teleconsult'))"
        );
        DB::statement(
            "alter table appointments add constraint chk_appointments_status check (status in ('booked', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show'))"
        );
        DB::statement(
            "alter table appointments add constraint chk_appointments_source check (source in ('counter', 'portal', 'walk_in'))"
        );
        DB::statement('alter table appointments add constraint chk_appointments_window check (starts_at < ends_at)');

        // The slot double-booking guard: one LIVE booking per
        // (tenant, provider, start) — parallel requests race on this index.
        DB::statement(
            "create unique index uq_appointments_tenant_provider_start on appointments (tenant_id, provider_staff_id, starts_at) where status in ('booked', 'checked_in', 'in_consultation')"
        );
        // Composite-FK support: encounters reference appointments via
        // (tenant_id, facility_id, id) — required by PostgreSQL.
        DB::statement('create unique index uq_appointments_tenant_facility_id on appointments (tenant_id, facility_id, id)');
        DB::statement('create index idx_appointments_tenant_provider_start on appointments (tenant_id, provider_staff_id, starts_at)');
        DB::statement('create index idx_appointments_tenant_patient_start on appointments (tenant_id, patient_id, starts_at)');
        DB::statement('create index idx_appointments_tenant_facility_status on appointments (tenant_id, facility_id, status, starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
