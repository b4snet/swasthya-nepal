<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology / RIS operational layer enhancements (STEP 5) - Part 2:
 * New tables: modality_schedule_exceptions, study_events
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Modality Schedule Exceptions (new table) ──────────────────────
        Schema::create('modality_schedule_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('modality_id');
            $table->date('exception_date');
            $table->time('start_time')->nullable(); // null = all day
            $table->time('end_time')->nullable(); // null = all day
            $table->text('reason')->nullable(); // maintenance, holiday, etc.
            $table->boolean('is_blocked')->default(true); // true = blocked, false = extra hours
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'modality_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('modalities')
                ->restrictOnDelete();

            $table->unique(['tenant_id', 'facility_id', 'modality_id', 'exception_date', 'start_time', 'end_time'], 'uq_modality_schedule_exception');
            $table->index(['tenant_id', 'facility_id', 'modality_id'], 'idx_modality_schedule_exceptions_tenant_facility');
        });

        // ── Study Events / Timeline (new table for explicit audit trail) ──
        Schema::create('study_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('study_id');
            $table->text('event_type'); // ordered, scheduled, rescheduled, arrived, in_progress, acquired, performed, report_drafted, report_verified, report_amended, cancelled, rejected, released_to_clinician
            $table->text('event_description')->nullable();
            $table->uuid('actor_staff_id')->nullable();
            $table->json('metadata')->nullable(); // additional context
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'study_id'])
                ->references(['tenant_id', 'id'])
                ->on('studies')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'actor_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'study_id', 'created_at'], 'idx_study_events_tenant_study');
            $table->index(['tenant_id', 'facility_id', 'event_type'], 'idx_study_events_tenant_facility');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_events');
        Schema::dropIfExists('modality_schedule_exceptions');
    }
};