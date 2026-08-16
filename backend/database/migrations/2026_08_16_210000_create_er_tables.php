<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 14 — Emergency (ROADMAP Phase 9, PRODUCT_REQUIREMENTS
 * §6.6): minimal-data ER registration, configurable triage, time-stamped
 * ER events, and audited admit/transfer/discharge disposition.
 *
 * The ER reuses the existing clinical spine (patients, encounters type 'er',
 * admissions with admission_type 'emergency', vitals with nullable
 * admission_id for future ER use) — this migration adds the four ER-specific
 * surfaces the contract names:
 *
 *  - er_registrations   — minimal-data registration (name/age-sex/complaint)
 *                         with a FULL patient record created (unidentified
 *                         patients get a documented placeholder + estimated
 *                         age; identity is later resolved via the existing
 *                         patient merge — the controlled link).
 *  - triage_scales      — the configurable acuity catalog (tenant+facility
 *                         scoped, e.g. the 5-level scale; reassessment
 *                         interval drives the follow-up schedule).
 *  - triage_assignments — one ACTIVE assessment per ER encounter (partial
 *                         unique); reassessment supersedes the old row via
 *                         CAS; a clinical-authority OVERRIDE is marked and
 *                         audited separately.
 *  - er_events          — the append-only, time-stamped ER event log
 *                         (medico-legal: triage times, reassessments, every
 *                         event timestamp, disposition, transfers out).
 *
 * Every table is TENANT_FACILITY scoped (RLS on + FORCED in the companion
 * migration) with tenant-safe composite FKs. er_events is immutable —
 * no UPDATE/DELETE path exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- er_registrations: minimal-data registration ----
        Schema::create('er_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id');
            $table->uuid('registered_by');
            $table->timestampTz('registered_at');
            $table->text('presenting_complaint')->nullable();
            $table->integer('estimated_age')->nullable(); // source fact when age was estimated
            $table->boolean('is_unidentified')->default(false); // no name captured at registration
            $table->timestampTz('completed_at')->nullable(); // demographics completed later
            $table->uuid('completed_by')->nullable();
            $table->uuid('created_by')->nullable();
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

            $table->foreign(['tenant_id', 'facility_id', 'registered_by'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table er_registrations add constraint chk_er_registrations_complaint check (presenting_complaint is null or length(presenting_complaint) between 1 and 2000)'
        );
        DB::statement(
            'alter table er_registrations add constraint chk_er_registrations_age check (estimated_age is null or estimated_age between 0 and 150)'
        );
        DB::statement(
            'create index idx_er_registrations_tenant_registered on er_registrations (tenant_id, registered_at)'
        );
        DB::statement('create unique index uq_er_registrations_tenant_encounter on er_registrations (tenant_id, encounter_id)');

        // ---- triage_scales: the configurable acuity catalog ----
        Schema::create('triage_scales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('level'); // 1 = most urgent … n = least urgent
            $table->string('color', 20)->nullable();
            $table->integer('reassessment_minutes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->text('status')->default('active'); // active, inactive
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table triage_scales add constraint chk_triage_scales_level check (level between 1 and 10)'
        );
        DB::statement(
            "alter table triage_scales add constraint chk_triage_scales_status check (status in ('active', 'inactive'))"
        );
        DB::statement(
            'alter table triage_scales add constraint chk_triage_scales_reassessment check (reassessment_minutes is null or reassessment_minutes between 5 and 1440)'
        );
        DB::statement(
            'create unique index uq_triage_scales_tenant_facility_code on triage_scales (tenant_id, facility_id, code)'
        );
        // Backs the triage_assignments composite FK.
        DB::statement('create unique index uq_triage_scales_tenant_id on triage_scales (tenant_id, id)');

        // ---- triage_assignments: one ACTIVE assessment per ER encounter ----
        Schema::create('triage_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('encounter_id');
            $table->uuid('patient_id');
            $table->uuid('triage_scale_id');
            $table->integer('level'); // snapshot of the scale level at assessment
            $table->string('color', 20)->nullable(); // snapshot
            $table->uuid('assessed_by_staff_id');
            $table->timestampTz('assessed_at');
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable(); // clinical context — never audit payloads
            $table->text('status')->default('active'); // active, superseded
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'triage_scale_id'])
                ->references(['tenant_id', 'id'])
                ->on('triage_scales')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'assessed_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table triage_assignments add constraint chk_triage_assignments_status check (status in ('active', 'superseded'))"
        );
        DB::statement(
            'alter table triage_assignments add constraint chk_triage_assignments_level check (level between 1 and 10)'
        );
        DB::statement(
            'alter table triage_assignments add constraint chk_triage_assignments_override check (is_override = false or override_reason is not null)'
        );
        // One ACTIVE triage per ER encounter — the DB backstop against
        // concurrent reassessments leaving two actives.
        DB::statement(
            "create unique index uq_triage_assignments_tenant_encounter_active on triage_assignments (tenant_id, encounter_id) where status = 'active'"
        );
        DB::statement(
            'create index idx_triage_assignments_tenant_encounter on triage_assignments (tenant_id, encounter_id, assessed_at)'
        );

        // ---- er_events: the append-only time-stamped ER event log ----
        Schema::create('er_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('encounter_id');
            $table->uuid('patient_id');
            $table->text('event_type');
            $table->text('notes')->nullable();
            $table->timestampTz('occurred_at');
            $table->uuid('actor_staff_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'actor_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table er_events add constraint chk_er_events_type check (event_type in ('arrived', 'registered', 'triaged', 'reassessed', 'seen_by_doctor', 'treatment_started', 'lab_ordered', 'medication_administered', 'procedure', 'observation_started', 'disposition', 'transferred_out', 'discharged', 'other'))"
        );
        DB::statement(
            'alter table er_events add constraint chk_er_events_notes check (notes is null or length(notes) between 1 and 2000)'
        );
        DB::statement(
            'create index idx_er_events_tenant_encounter on er_events (tenant_id, encounter_id, occurred_at)'
        );
        DB::statement(
            'create index idx_er_events_tenant_patient on er_events (tenant_id, patient_id, occurred_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('er_events');
        Schema::dropIfExists('triage_assignments');
        Schema::dropIfExists('triage_scales');
        Schema::dropIfExists('er_registrations');
    }
};
