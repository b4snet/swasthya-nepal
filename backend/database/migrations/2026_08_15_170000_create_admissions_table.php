<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 6 — IPD admission/discharge with bed release
 * (PRODUCT_REQUIREMENTS §6.5, DATABASE.md §3.23).
 *
 * The inpatient stay: admission from an open encounter with a live bed
 * assignment, and discharge that releases the bed. The bed occupancy
 * invariant is enforced at the database layer:
 *
 *  - one open admission per patient (partial unique on the open statuses);
 *  - one admission per encounter (partial unique on the open statuses);
 *  - one bed per current admission and one admission per occupied bed —
 *    `beds.current_admission_id` receives its tenant-safe composite FK here
 *    (the column has existed since Phase 4 exactly for this), and the
 *    partial unique `uq_beds_tenant_current_admission` (created with beds)
 *    is the DB backstop against double-booking.
 *
 * Status lifecycle: admitted → (transfers, later phase) → discharged /
 * cancelled. Discharge carries discharge_type + a structured discharge
 * summary (a signed clinical note of type 'discharge' — clinical_notes'
 * note_type CHECK already admits it), referenced by discharge_summary_id.
 * `lock_version` is the optimistic-locking counter for CAS transitions.
 *
 * Money/audit note: bed-day charging is a later-phase billing concern; this
 * slice proves admission + discharge + release.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id');
            $table->string('admission_number', 50);
            $table->text('admission_type'); // emergency, planned, transfer_in
            $table->text('admitting_diagnosis');
            $table->timestampTz('admitted_at');
            $table->text('status')->default('admitted'); // admitted, in_ward, transferred, discharged, cancelled
            $table->timestampTz('discharged_at')->nullable();
            $table->text('discharge_type')->nullable(); // home, referral, transfer_out, against_advice
            $table->uuid('discharge_summary_id')->nullable();
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

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign('discharge_summary_id')
                ->references('id')
                ->on('clinical_notes')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table admissions add constraint chk_admissions_type check (admission_type in ('emergency', 'planned', 'transfer_in'))"
        );
        DB::statement(
            "alter table admissions add constraint chk_admissions_status check (status in ('admitted', 'in_ward', 'transferred', 'discharged', 'cancelled'))"
        );
        DB::statement(
            "alter table admissions add constraint chk_admissions_discharge_type check (discharge_type is null or discharge_type in ('home', 'referral', 'transfer_out', 'against_advice'))"
        );

        // One admission per encounter and one open admission per patient —
        // partial uniques on the open statuses (DATABASE.md §3.23).
        DB::statement(
            "create unique index uq_admissions_tenant_encounter_open on admissions (tenant_id, encounter_id) where status in ('admitted', 'in_ward', 'transferred')"
        );
        DB::statement(
            "create unique index uq_admissions_tenant_patient_open on admissions (tenant_id, patient_id) where status in ('admitted', 'in_ward', 'transferred')"
        );

        DB::statement('create unique index uq_admissions_tenant_number on admissions (tenant_id, admission_number)');
        // Composite-FK support: beds.current_admission_id references
        // admissions via (tenant_id, id).
        DB::statement('create unique index uq_admissions_tenant_id on admissions (tenant_id, id)');
        DB::statement('create index idx_admissions_tenant_patient_admitted on admissions (tenant_id, patient_id, admitted_at)');
        DB::statement('create index idx_admissions_tenant_status on admissions (tenant_id, status)');

        // The tenant-safe composite FK for bed occupancy — the column existed
        // since Phase 4; this migration adds the constraint (DATABASE.md
        // §3.26). The partial unique uq_beds_tenant_current_admission (with
        // the beds table) enforces one admission per occupied bed.
        DB::statement(
            'alter table beds add constraint fk_beds_tenant_current_admission foreign key (tenant_id, current_admission_id) references admissions (tenant_id, id) on delete restrict'
        );
    }

    public function down(): void
    {
        DB::statement('alter table beds drop constraint if exists fk_beds_tenant_current_admission');
        Schema::dropIfExists('admissions');
    }
};
