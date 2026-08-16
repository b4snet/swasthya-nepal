<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 13 — the remaining documented IPD workflow
 * (PRODUCT_REQUIREMENTS §6.5, ROADMAP Phase 8, DATABASE.md §3.23/§3.27):
 *
 *   admission → wards/rooms/beds → nursing → transfer → discharge summary
 *
 * Wards/rooms/beds (Phase 4), admissions, and the signed discharge-summary
 * clinical note (Phase 3 slice 6) already exist. This migration adds the
 * four nursing surfaces the design specifies:
 *
 *  - transfer_events    — the audited bed/ward transfer timeline ("transfers
 *                         audited with reasons"; historical bed timeline
 *                         preserved). Every move records from-bed → to-bed,
 *                         the reason, and who authorized it.
 *  - nursing_notes      — structured ward documentation (draft → signed;
 *                         signed is immutable — amendments are later-phase).
 *  - mar_entries        — the medication administration record from
 *                         prescription lines: a scheduled dose (one per
 *                         line + scheduled time, DB-enforced) transitions
 *                         scheduled → given | refused | missed | held with
 *                         the administering nurse and refusal/miss reason.
 *  - vital_observations — vital signs (bp/pulse/temp/spo2/weight/score)
 *                         recorded against the admission; is_abnormal is the
 *                         later-phase CDSS-derived flag (nullable now).
 *
 * Every table is TENANT_FACILITY scoped (RLS on + FORCED in the companion
 * migration) with tenant-safe composite FKs, so cross-tenant children are
 * structurally impossible. Support indexes uq_beds_tenant_id and
 * uq_prescription_lines_tenant_id are added (additive) so the composite FKs
 * to beds and prescription_lines can exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Composite-FK support index FIRST (additive) — transfer_events
        // references beds via (tenant_id, id), which PostgreSQL requires to
        // be unique BEFORE the FK can be created; the (tenant_id, room_id,
        // bed_code) unique does not cover that set. prescription_lines
        // already carries uq_prescription_lines_tenant_id (pharmacy slice).
        DB::statement('create unique index uq_beds_tenant_id on beds (tenant_id, id)');

        // ---- transfer_events: the audited bed/ward transfer timeline ----
        Schema::create('transfer_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('admission_id');
            $table->uuid('from_bed_id');
            $table->uuid('to_bed_id');
            $table->text('reason'); // transfers are audited WITH reasons
            $table->uuid('transferred_by'); // the doctor/staff who authorized
            $table->timestampTz('transferred_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'admission_id'])
                ->references(['tenant_id', 'id'])
                ->on('admissions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'from_bed_id'])
                ->references(['tenant_id', 'id'])
                ->on('beds')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'to_bed_id'])
                ->references(['tenant_id', 'id'])
                ->on('beds')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'transferred_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table transfer_events add constraint chk_transfer_events_reason check (length(reason) between 1 and 2000)'
        );
        DB::statement(
            'create index idx_transfer_events_tenant_admission on transfer_events (tenant_id, admission_id, transferred_at)'
        );

        // ---- nursing_notes: structured ward documentation ----
        Schema::create('nursing_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('admission_id');
            $table->uuid('author_staff_id');
            $table->jsonb('content'); // structured sections — clinical PHI, never audit payloads
            $table->text('status')->default('draft'); // draft, signed
            $table->timestampTz('signed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'admission_id'])
                ->references(['tenant_id', 'id'])
                ->on('admissions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'author_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table nursing_notes add constraint chk_nursing_notes_status check (status in ('draft', 'signed'))"
        );
        DB::statement(
            'create index idx_nursing_notes_tenant_admission on nursing_notes (tenant_id, admission_id, created_at)'
        );

        // ---- mar_entries: the medication administration record ----
        Schema::create('mar_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('admission_id');
            $table->uuid('prescription_line_id');
            $table->timestampTz('scheduled_at');
            $table->text('status')->default('scheduled'); // scheduled, given, refused, missed, held
            $table->uuid('administered_by')->nullable();
            $table->timestampTz('administered_at')->nullable();
            $table->text('reason')->nullable(); // refusal/miss reason (CLINICAL_SAFETY §190)
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'admission_id'])
                ->references(['tenant_id', 'id'])
                ->on('admissions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'prescription_line_id'])
                ->references(['tenant_id', 'id'])
                ->on('prescription_lines')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'administered_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table mar_entries add constraint chk_mar_entries_status check (status in ('scheduled', 'given', 'refused', 'missed', 'held'))"
        );
        DB::statement(
            'alter table mar_entries add constraint chk_mar_entries_reason check (reason is null or length(reason) between 1 and 1000)'
        );
        // One administration per scheduled dose — the DB backstop against
        // double-administration of the same dose (DATABASE.md §3.27).
        DB::statement(
            'create unique index uq_mar_entries_tenant_line_scheduled on mar_entries (tenant_id, prescription_line_id, scheduled_at)'
        );
        DB::statement(
            'create index idx_mar_entries_tenant_admission on mar_entries (tenant_id, admission_id, scheduled_at)'
        );

        // ---- vital_observations: vital signs recorded against the stay ----
        Schema::create('vital_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('admission_id')->nullable(); // null for future OPD/ER vitals
            $table->uuid('encounter_id')->nullable();
            $table->uuid('patient_id');
            $table->text('type'); // bp, pulse, temp, spo2, weight, score
            $table->jsonb('value');
            $table->timestampTz('measured_at');
            $table->uuid('measured_by')->nullable();
            $table->boolean('is_abnormal')->nullable(); // CDSS-derived flag (later phase)
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'admission_id'])
                ->references(['tenant_id', 'id'])
                ->on('admissions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'measured_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table vital_observations add constraint chk_vital_observations_type check (type in ('bp', 'pulse', 'temp', 'spo2', 'weight', 'score'))"
        );
        // High-volume time-range scans (DATABASE.md §3.27): BRIN on measured_at.
        DB::statement(
            'create index idx_vital_observations_tenant_patient on vital_observations (tenant_id, patient_id, measured_at)'
        );
        DB::statement('create index idx_vital_observations_measured_at_brin on vital_observations using brin (measured_at)');
        DB::statement(
            'create index idx_vital_observations_tenant_admission on vital_observations (tenant_id, admission_id, measured_at)'
        );

    }

    public function down(): void
    {
        DB::statement('drop index if exists uq_beds_tenant_id');
        Schema::dropIfExists('vital_observations');
        Schema::dropIfExists('mar_entries');
        Schema::dropIfExists('nursing_notes');
        Schema::dropIfExists('transfer_events');
    }
};
