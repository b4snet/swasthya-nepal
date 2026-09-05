<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology / RIS operational layer enhancements (STEP 5) - Part 1:
 * Column additions and CHECK constraint updates to existing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Studies enhancements ──────────────────────────────────────────
        Schema::table('studies', function (Blueprint $table): void {
            // Accession number: human-readable unique identifier per tenant+facility
            $table->string('accession_number', 50)->nullable()->after('id');

            // Extended status support (CHECK constraint updated below)
            // New statuses: arrived, in_progress, acquired, pending_interpretation, verified, rejected

            // Technician/operator workflow
            $table->uuid('assigned_technician_id')->nullable()->after('performed_by_staff_id');
            $table->text('acquisition_notes')->nullable()->after('preparation_instructions');
            $table->text('repeat_acquisition_reason')->nullable()->after('acquisition_notes');

            // Result delivery timestamps
            $table->timestampTz('report_released_at')->nullable()->after('performed_at');
            $table->timestampTz('released_to_clinician_at')->nullable()->after('report_released_at');

            // Billing linkage
            $table->uuid('charge_id')->nullable()->after('lab_order_id');

            // Rescheduling support
            $table->timestampTz('rescheduled_at')->nullable()->after('scheduled_at');
            $table->text('reschedule_reason')->nullable()->after('preparation_instructions');

            // Composite-FK for assigned_technician
            $table->foreign(['tenant_id', 'facility_id', 'assigned_technician_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        // Update studies status CHECK constraint to include new statuses
        DB::statement(
            "alter table studies drop constraint if exists chk_studies_status"
        );
        DB::statement(
            "alter table studies add constraint chk_studies_status check (status in ('ordered', 'scheduled', 'arrived', 'in_progress', 'acquired', 'pending_interpretation', 'performed', 'reported', 'verified', 'cancelled', 'rejected'))"
        );

        // Add unique index for accession_number (per tenant+facility)
        DB::statement(
            'create unique index uq_studies_tenant_facility_accession on studies (tenant_id, facility_id, accession_number) where accession_number is not null'
        );

        // Index for technician worklist queries
        DB::statement('create index idx_studies_tenant_facility_technician on studies (tenant_id, facility_id, assigned_technician_id, status)');

        // Index for result delivery queries
        DB::statement('create index idx_studies_tenant_facility_released on studies (tenant_id, facility_id, report_released_at)');


        // ── Modalities enhancements ───────────────────────────────────────
        Schema::table('modalities', function (Blueprint $table): void {
            $table->text('location')->nullable()->after('status');
            $table->integer('slot_minutes')->default(30)->after('daily_capacity');
            $table->time('operating_hours_start')->nullable()->after('slot_minutes');
            $table->time('operating_hours_end')->nullable()->after('operating_hours_start');
            $table->json('unavailable_days')->nullable()->after('operating_hours_end'); // dates when modality is unavailable
        });

        // Update modality status CHECK (allow 'maintenance')
        DB::statement(
            "alter table modalities drop constraint if exists chk_modalities_status"
        );
        DB::statement(
            "alter table modalities add constraint chk_modalities_status check (status in ('active', 'inactive', 'down', 'maintenance'))"
        );


        // ── Radiology Reports enhancements ────────────────────────────────
        Schema::table('radiology_reports', function (Blueprint $table): void {
            // Structured findings support
            $table->json('findings')->nullable()->after('critical_findings'); // coded findings: [{code, name, value, unit, status}]
            $table->json('measurements')->nullable()->after('findings'); // measurements: [{name, value, unit, reference_range}]
        });


        // ── Image References enhancements ─────────────────────────────────
        Schema::table('image_references', function (Blueprint $table): void {
            $table->text('series_instance_uid')->nullable()->after('reference_value');
            $table->text('sop_instance_uid')->nullable()->after('series_instance_uid');
        });
    }

    public function down(): void
    {
        Schema::table('image_references', function (Blueprint $table): void {
            $table->dropColumn(['series_instance_uid', 'sop_instance_uid']);
        });

        Schema::table('radiology_reports', function (Blueprint $table): void {
            $table->dropColumn(['findings', 'measurements']);
        });

        Schema::table('modalities', function (Blueprint $table): void {
            $table->dropColumn(['location', 'slot_minutes', 'operating_hours_start', 'operating_hours_end', 'unavailable_days']);
        });

        Schema::table('studies', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'facility_id', 'assigned_technician_id']);
            $table->dropColumn([
                'accession_number',
                'assigned_technician_id',
                'acquisition_notes',
                'repeat_acquisition_reason',
                'report_released_at',
                'released_to_clinician_at',
                'charge_id',
                'rescheduled_at',
                'reschedule_reason',
            ]);
        });

        // Restore original CHECK constraints
        DB::statement("alter table studies drop constraint if exists chk_studies_status");
        DB::statement(
            "alter table studies add constraint chk_studies_status check (status in ('ordered', 'scheduled', 'performed', 'reported', 'cancelled'))"
        );
        DB::statement("alter table modalities drop constraint if exists chk_modalities_status");
        DB::statement(
            "alter table modalities add constraint chk_modalities_status check (status in ('active', 'inactive', 'down'))"
        );
    }
};