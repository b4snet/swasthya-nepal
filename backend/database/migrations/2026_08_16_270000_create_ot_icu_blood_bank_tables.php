<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 20 — OT, ICU, and Blood Bank (ROADMAP Phase 16, PRODUCT
 * REQUIREMENTS §6.10–6.12, DATABASE.md §3.48–3.50).
 *
 * OT (§6.10): theatre scheduling with conflict detection, procedure
 * records, surgical team log, anesthesia records, time-stamped surgical
 * events, structured safety checklists (time-out/sign-out) with recorded
 * per-step completion, and PACU recovery records. Case closure (ot:close)
 * requires checklist compliance — a case cannot close with an incomplete
 * surgical safety checklist.
 *
 * ICU (§6.11): ICU bed state management with acuity-based assignment, one
 * open ICU admission per patient (DB partial unique), high-frequency
 * observations, computed warning scores (NEWS-style, configurable), alerts
 * that MUST be acknowledged (score escalations and MISSED observations —
 * a missed ICU observation is a patient-safety event by design,
 * ROADMAP Phase 16), and critical-care documentation (daily goals,
 * sedation scales, weaning plans, procedures).
 *
 * Blood Bank (§6.12): donors (PHI-protected — names never in audit
 * payloads), donations → componentized blood units with unit numbers and
 * expiry, testing, compatibility + crossmatch, issue (expired or untested
 * units are never issuable), transfusion with DUAL verification (started
 * by one staff member, verified by a DIFFERENT staff member — both
 * recorded with timestamps), reaction reporting, and discard with reason.
 * Every unit is traceable to its donor and its recipient.
 *
 * All 22 tables are TENANT_FACILITY tier (OT/ICU/blood are facility-local
 * clinical operations). RLS is enabled + FORCED by the companion migration
 * (2026_08_16_270100). Parents' (tenant, facility, id) / (tenant, id)
 * backers are declared BEFORE the child FKs that reference them
 * (DATABASE.md §0.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────── Operating Theatre ───────────────────────

        Schema::create('theatres', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->string('name');
            $table->text('status')->default('active'); // active | inactive
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement("alter table theatres add constraint chk_theatres_status check (status in ('active', 'inactive'))");
        DB::statement('create unique index uq_theatres_tenant_facility_code on theatres (tenant_id, facility_id, code) where deleted_at is null');
        DB::statement('create index idx_theatres_tenant_facility on theatres (tenant_id, facility_id)');
        // Backer for procedure_requests / procedures composite FKs.
        DB::statement('create unique index uq_theatres_tenant_facility_id on theatres (tenant_id, facility_id, id)');

        Schema::create('procedure_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id')->nullable();
            $table->uuid('requested_by_staff_id');
            $table->text('procedure_name');
            $table->text('priority')->default('routine'); // routine | urgent | emergency
            $table->text('status')->default('requested'); // requested | scheduled | in_progress | completed | cancelled
            $table->uuid('theatre_id')->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->integer('scheduled_duration_minutes')->nullable();
            $table->jsonb('equipment_requirements')->default('[]');
            $table->jsonb('team_requirements')->default('[]');
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

            $table->foreign(['tenant_id', 'facility_id', 'requested_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'theatre_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('theatres')
                ->restrictOnDelete();
        });

        DB::statement("alter table procedure_requests add constraint chk_procedure_requests_priority check (priority in ('routine', 'urgent', 'emergency'))");
        DB::statement("alter table procedure_requests add constraint chk_procedure_requests_status check (status in ('requested', 'scheduled', 'in_progress', 'completed', 'cancelled'))");
        DB::statement('alter table procedure_requests add constraint chk_procedure_requests_duration check (scheduled_duration_minutes is null or scheduled_duration_minutes > 0)');
        DB::statement('create index idx_procedure_requests_tenant_facility_status on procedure_requests (tenant_id, facility_id, status)');
        DB::statement('create index idx_procedure_requests_tenant_patient on procedure_requests (tenant_id, patient_id)');
        // Backer for the procedures composite FK.
        DB::statement('create unique index uq_procedure_requests_tenant_facility_id on procedure_requests (tenant_id, facility_id, id)');

        Schema::create('procedures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('procedure_request_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id')->nullable();
            $table->uuid('theatre_id');
            $table->text('status')->default('scheduled'); // scheduled | in_progress | completed | cancelled
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->uuid('surgeon_staff_id')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'procedure_request_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('procedure_requests')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'theatre_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('theatres')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'surgeon_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table procedures add constraint chk_procedures_status check (status in ('scheduled', 'in_progress', 'completed', 'cancelled'))");
        DB::statement('create unique index uq_procedures_tenant_request on procedures (tenant_id, procedure_request_id)');
        DB::statement('create index idx_procedures_tenant_facility_status on procedures (tenant_id, facility_id, status)');
        DB::statement('create index idx_procedures_tenant_theatre on procedures (tenant_id, theatre_id, started_at)');
        // Backer for the surgical_team_members / anesthesia_records /
        // surgical_events / checklist_items / recovery_records composite FKs.
        DB::statement('create unique index uq_procedures_tenant_facility_id on procedures (tenant_id, facility_id, id)');

        Schema::create('surgical_team_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('procedure_id');
            $table->uuid('staff_id');
            $table->text('role'); // surgeon | assistant | anesthetist | nurse | perfusionist | other
            $table->timestampTz('time_in');
            $table->timestampTz('time_out')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'procedure_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('procedures')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table surgical_team_members add constraint chk_team_role check (role in ('surgeon', 'assistant', 'anesthetist', 'nurse', 'perfusionist', 'other'))");
        // One logged role per staff member per procedure.
        DB::statement('create unique index uq_team_members_tenant_procedure_staff on surgical_team_members (tenant_id, procedure_id, staff_id, role)');
        DB::statement('create index idx_team_members_tenant_procedure on surgical_team_members (tenant_id, procedure_id)');

        Schema::create('anesthesia_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('procedure_id');
            $table->uuid('anesthetist_staff_id');
            $table->text('anesthesia_type'); // general | regional | spinal | local | sedation | other
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->text('status')->default('active'); // active | completed
            $table->text('notes')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'procedure_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('procedures')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'anesthetist_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table anesthesia_records add constraint chk_anesthesia_type check (anesthesia_type in ('general', 'regional', 'spinal', 'local', 'sedation', 'other'))");
        DB::statement("alter table anesthesia_records add constraint chk_anesthesia_status check (status in ('active', 'completed'))");
        DB::statement('create index idx_anesthesia_tenant_procedure on anesthesia_records (tenant_id, procedure_id)');

        Schema::create('surgical_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('procedure_id');
            $table->text('event_type'); // time_out | incision | closure | sign_out | complication | other
            $table->timestampTz('occurred_at');
            $table->uuid('staff_id')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'procedure_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('procedures')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table surgical_events add constraint chk_surgical_events_type check (event_type in ('time_out', 'incision', 'closure', 'sign_out', 'complication', 'other'))");
        DB::statement('create index idx_surgical_events_tenant_procedure_time on surgical_events (tenant_id, procedure_id, occurred_at)');

        Schema::create('checklist_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->string('name');
            $table->text('category'); // pre_op | time_out | sign_out | post_op
            $table->jsonb('steps')->default('[]'); // [{"key": "id_verified", "label": "Patient identity confirmed"}, …]
            $table->text('status')->default('active'); // active | inactive
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement("alter table checklist_templates add constraint chk_checklist_templates_category check (category in ('pre_op', 'time_out', 'sign_out', 'post_op'))");
        DB::statement("alter table checklist_templates add constraint chk_checklist_templates_status check (status in ('active', 'inactive'))");
        DB::statement('create unique index uq_checklist_templates_tenant_facility_code on checklist_templates (tenant_id, facility_id, code) where deleted_at is null');
        DB::statement('create index idx_checklist_templates_tenant_facility on checklist_templates (tenant_id, facility_id)');
        // Backer for the checklist_items composite FK.
        DB::statement('create unique index uq_checklist_templates_tenant_facility_id on checklist_templates (tenant_id, facility_id, id)');

        Schema::create('checklist_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('procedure_id');
            $table->uuid('checklist_template_id');
            $table->string('step_key', 100);
            $table->text('step_label');
            $table->integer('sequence');
            $table->text('category'); // snapshot of the template category
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by_staff_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'procedure_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('procedures')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'checklist_template_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('checklist_templates')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'completed_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('alter table checklist_items add constraint chk_checklist_items_seq check (sequence > 0)');
        DB::statement('create index idx_checklist_items_tenant_procedure on checklist_items (tenant_id, procedure_id, sequence)');

        Schema::create('recovery_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('procedure_id');
            $table->timestampTz('admitted_at');
            $table->uuid('admitted_by_staff_id');
            $table->jsonb('observations')->default('{}'); // PACU vitals snapshot
            $table->text('status')->default('in_recovery'); // in_recovery | discharged
            $table->timestampTz('discharged_at')->nullable();
            $table->uuid('discharged_by_staff_id')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'procedure_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('procedures')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'admitted_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'discharged_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table recovery_records add constraint chk_recovery_status check (status in ('in_recovery', 'discharged'))");
        DB::statement('create unique index uq_recovery_tenant_procedure on recovery_records (tenant_id, procedure_id)');
        DB::statement('create index idx_recovery_tenant_facility_status on recovery_records (tenant_id, facility_id, status)');

        // ───────────────────────────── ICU ─────────────────────────────

        Schema::create('icu_beds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('bed_code', 20);
            $table->text('status')->default('available'); // available | occupied | reserved | out_of_service
            $table->text('acuity_supported')->default('level_3'); // level_1 | level_2 | level_3
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement("alter table icu_beds add constraint chk_icu_beds_status check (status in ('available', 'occupied', 'reserved', 'out_of_service'))");
        DB::statement("alter table icu_beds add constraint chk_icu_beds_acuity check (acuity_supported in ('level_1', 'level_2', 'level_3'))");
        DB::statement('create unique index uq_icu_beds_tenant_facility_code on icu_beds (tenant_id, facility_id, bed_code)');
        DB::statement('create index idx_icu_beds_tenant_status on icu_beds (tenant_id, status)');
        // Backer for the icu_admissions composite FK.
        DB::statement('create unique index uq_icu_beds_tenant_facility_id on icu_beds (tenant_id, facility_id, id)');

        Schema::create('icu_admissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable(); // IPD source
            $table->uuid('icu_bed_id');
            $table->text('source')->default('ipd'); // ipd | er | ot
            $table->text('acuity')->default('level_3'); // level_1 | level_2 | level_3
            $table->integer('observation_interval_minutes')->default(60);
            $table->timestampTz('next_observation_due_at');
            $table->text('status')->default('admitted'); // admitted | transferred | discharged | cancelled
            $table->timestampTz('admitted_at');
            $table->uuid('admitted_by_staff_id');
            $table->timestampTz('discharged_at')->nullable();
            $table->uuid('discharged_by_staff_id')->nullable();
            $table->text('transfer_handover_notes')->nullable();
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

            $table->foreign(['tenant_id', 'admission_id'])
                ->references(['tenant_id', 'id'])
                ->on('admissions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'icu_bed_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('icu_beds')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'admitted_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'discharged_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table icu_admissions add constraint chk_icu_admissions_source check (source in ('ipd', 'er', 'ot'))");
        DB::statement("alter table icu_admissions add constraint chk_icu_admissions_acuity check (acuity in ('level_1', 'level_2', 'level_3'))");
        DB::statement("alter table icu_admissions add constraint chk_icu_admissions_status check (status in ('admitted', 'transferred', 'discharged', 'cancelled'))");
        DB::statement('alter table icu_admissions add constraint chk_icu_admissions_interval check (observation_interval_minutes between 5 and 1440)');
        // One open ICU admission per patient and one admission per occupied
        // ICU bed — the DB backstops against double-booking.
        DB::statement("create unique index uq_icu_admissions_tenant_patient_open on icu_admissions (tenant_id, patient_id) where status in ('admitted', 'transferred')");
        DB::statement("create unique index uq_icu_admissions_tenant_bed_open on icu_admissions (tenant_id, icu_bed_id) where status in ('admitted', 'transferred')");
        DB::statement('create index idx_icu_admissions_tenant_status on icu_admissions (tenant_id, status)');
        DB::statement('create index idx_icu_admissions_tenant_due on icu_admissions (tenant_id, next_observation_due_at)');
        // Backer for the icu_observation_sets / warning_scores / icu_alerts
        // / critical_care_notes composite FKs.
        DB::statement('create unique index uq_icu_admissions_tenant_facility_id on icu_admissions (tenant_id, facility_id, id)');

        Schema::create('icu_observation_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('icu_admission_id');
            $table->timestampTz('observed_at');
            $table->uuid('observed_by_staff_id');
            $table->jsonb('values')->default('{}'); // temperature_c, heart_rate, respiratory_rate, sbp, dbp, spo2, gcs_eye, gcs_verbal, gcs_motor, urine_output_ml
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'icu_admission_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('icu_admissions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'observed_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement('create index idx_icu_observation_sets_tenant_admission_time on icu_observation_sets (tenant_id, icu_admission_id, observed_at)');
        // Backer for the warning_scores composite FK.
        DB::statement('create unique index uq_icu_observation_sets_tenant_facility_id on icu_observation_sets (tenant_id, facility_id, id)');

        Schema::create('warning_scores', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('icu_admission_id');
            $table->uuid('observation_set_id');
            $table->integer('score_total')->default(0);
            $table->text('severity')->default('low'); // low | medium | high | emergency
            $table->jsonb('breakdown')->default('{}'); // per-variable scores
            $table->text('scale_version')->default('news-1');
            $table->timestampTz('computed_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'icu_admission_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('icu_admissions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'observation_set_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('icu_observation_sets')
                ->restrictOnDelete();
        });

        DB::statement('alter table warning_scores add constraint chk_warning_scores_total check (score_total >= 0)');
        DB::statement("alter table warning_scores add constraint chk_warning_scores_severity check (severity in ('low', 'medium', 'high', 'emergency'))");
        DB::statement('create unique index uq_warning_scores_tenant_observation on warning_scores (tenant_id, observation_set_id)');
        DB::statement('create index idx_warning_scores_tenant_admission_time on warning_scores (tenant_id, icu_admission_id, computed_at)');
        // Backer for the icu_alerts composite FK.
        DB::statement('create unique index uq_warning_scores_tenant_facility_id on warning_scores (tenant_id, facility_id, id)');

        Schema::create('icu_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('icu_admission_id');
            $table->uuid('warning_score_id')->nullable();
            $table->text('alert_type'); // score_escalation | missed_observation | threshold_breach
            $table->text('severity')->default('medium'); // low | medium | high | emergency
            $table->text('message'); // facts only — no patient identifiers or PHI
            $table->text('status')->default('open'); // open | acknowledged | resolved
            $table->timestampTz('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by_staff_id')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('resolved_by_staff_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'icu_admission_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('icu_admissions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'warning_score_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('warning_scores')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'acknowledged_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'resolved_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table icu_alerts add constraint chk_icu_alerts_type check (alert_type in ('score_escalation', 'missed_observation', 'threshold_breach'))");
        DB::statement("alter table icu_alerts add constraint chk_icu_alerts_severity check (severity in ('low', 'medium', 'high', 'emergency'))");
        DB::statement("alter table icu_alerts add constraint chk_icu_alerts_status check (status in ('open', 'acknowledged', 'resolved'))");
        DB::statement('create index idx_icu_alerts_tenant_admission_status on icu_alerts (tenant_id, icu_admission_id, status)');

        Schema::create('critical_care_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('icu_admission_id');
            $table->text('note_type'); // daily_goal | sedation_scale | weaning_plan | procedure | other
            $table->text('content');
            $table->timestampTz('authored_at');
            $table->uuid('authored_by_staff_id');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'icu_admission_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('icu_admissions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'authored_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table critical_care_notes add constraint chk_cc_notes_type check (note_type in ('daily_goal', 'sedation_scale', 'weaning_plan', 'procedure', 'other'))");
        DB::statement('create index idx_cc_notes_tenant_admission_time on critical_care_notes (tenant_id, icu_admission_id, authored_at)');

        // ────────────────────────── Blood Bank ──────────────────────────

        Schema::create('donors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('donor_number', 50);
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->text('sex')->nullable();
            $table->text('blood_group')->nullable();
            $table->text('rh_factor')->nullable(); // positive | negative
            $table->string('phone', 30)->nullable();
            $table->text('status')->default('active'); // active | deferred | inactive
            $table->text('deferral_reason')->nullable();
            $table->date('deferral_until')->nullable();
            $table->jsonb('screening')->default('{}'); // questionnaire snapshot
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement("alter table donors add constraint chk_donors_status check (status in ('active', 'deferred', 'inactive'))");
        DB::statement("alter table donors add constraint chk_donors_rh check (rh_factor is null or rh_factor in ('positive', 'negative'))");
        DB::statement('create unique index uq_donors_tenant_facility_number on donors (tenant_id, facility_id, donor_number)');
        DB::statement('create index idx_donors_tenant_facility on donors (tenant_id, facility_id)');
        // Backer for the donations composite FK.
        DB::statement('create unique index uq_donors_tenant_facility_id on donors (tenant_id, facility_id, id)');

        Schema::create('donations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('donor_id');
            $table->timestampTz('donated_at');
            $table->uuid('phlebotomist_staff_id');
            $table->integer('volume_ml')->default(450);
            $table->text('screening_result')->default('eligible'); // eligible | deferred
            $table->text('status')->default('collected'); // collected | processed | discarded
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'donor_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('donors')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'phlebotomist_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table donations add constraint chk_donations_screening check (screening_result in ('eligible', 'deferred'))");
        DB::statement("alter table donations add constraint chk_donations_status check (status in ('collected', 'processed', 'discarded'))");
        DB::statement('alter table donations add constraint chk_donations_volume check (volume_ml > 0)');
        DB::statement('create index idx_donations_tenant_donor_time on donations (tenant_id, donor_id, donated_at)');
        // Backer for the blood_units composite FK.
        DB::statement('create unique index uq_donations_tenant_facility_id on donations (tenant_id, facility_id, id)');

        Schema::create('blood_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('donation_id');
            $table->string('unit_number', 50);
            $table->text('component_type'); // whole_blood | packed_cells | plasma | platelets | cryoprecipitate | other
            $table->text('blood_group');
            $table->text('rh_factor'); // positive | negative
            $table->timestampTz('collected_at');
            $table->timestampTz('expiry_at');
            $table->boolean('tested')->default(false);
            $table->jsonb('test_results')->default('{}'); // screening panel results
            $table->text('status')->default('quarantined'); // quarantined | available | crossmatched | issued | transfused | discarded
            $table->uuid('issued_to_patient_id')->nullable();
            $table->text('storage_location')->nullable();
            $table->text('discard_reason')->nullable();
            $table->timestampTz('discarded_at')->nullable();
            $table->uuid('discarded_by_staff_id')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'donation_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('donations')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'issued_to_patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'discarded_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table blood_units add constraint chk_blood_units_component check (component_type in ('whole_blood', 'packed_cells', 'plasma', 'platelets', 'cryoprecipitate', 'other'))");
        DB::statement("alter table blood_units add constraint chk_blood_units_rh check (rh_factor in ('positive', 'negative'))");
        DB::statement("alter table blood_units add constraint chk_blood_units_status check (status in ('quarantined', 'available', 'crossmatched', 'issued', 'transfused', 'discarded'))");
        DB::statement('alter table blood_units add constraint chk_blood_units_expiry check (expiry_at > collected_at)');
        DB::statement('create unique index uq_blood_units_tenant_number on blood_units (tenant_id, unit_number)');
        DB::statement('create index idx_blood_units_tenant_status on blood_units (tenant_id, status, expiry_at)');
        DB::statement('create index idx_blood_units_tenant_donation on blood_units (tenant_id, donation_id)');
        // Backer for the crossmatches / transfusions composite FKs.
        DB::statement('create unique index uq_blood_units_tenant_facility_id on blood_units (tenant_id, facility_id, id)');

        Schema::create('compatibility_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->text('patient_blood_group');
            $table->text('patient_rh_factor')->nullable();
            $table->boolean('abo_rh_compatible');
            $table->text('antibody_screen')->default('negative'); // negative | positive
            $table->text('result'); // compatible | incompatible
            $table->text('notes')->nullable();
            $table->timestampTz('checked_at');
            $table->uuid('checked_by_staff_id');
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

            $table->foreign(['tenant_id', 'facility_id', 'checked_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table compatibility_results add constraint chk_compat_antibody check (antibody_screen in ('negative', 'positive'))");
        DB::statement("alter table compatibility_results add constraint chk_compat_result check (result in ('compatible', 'incompatible'))");
        DB::statement('create index idx_compat_results_tenant_patient_time on compatibility_results (tenant_id, patient_id, checked_at)');
        // Backer for the crossmatches composite FK.
        DB::statement('create unique index uq_compatibility_results_tenant_facility_id on compatibility_results (tenant_id, facility_id, id)');

        Schema::create('crossmatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('blood_unit_id');
            $table->uuid('patient_id');
            $table->uuid('compatibility_result_id')->nullable();
            $table->text('status')->default('requested'); // requested | crossmatched | compatible | incompatible | released
            $table->timestampTz('requested_at');
            $table->uuid('requested_by_staff_id');
            $table->timestampTz('crossmatched_at')->nullable();
            $table->uuid('crossmatched_by_staff_id')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'blood_unit_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('blood_units')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'compatibility_result_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('compatibility_results')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'requested_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'crossmatched_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table crossmatches add constraint chk_crossmatches_status check (status in ('requested', 'crossmatched', 'compatible', 'incompatible', 'released'))");
        // One crossmatch per (unit, patient).
        DB::statement('create unique index uq_crossmatches_tenant_unit_patient on crossmatches (tenant_id, blood_unit_id, patient_id)');
        DB::statement('create index idx_crossmatches_tenant_patient on crossmatches (tenant_id, patient_id, requested_at)');
        // Backer for the transfusions composite FK.
        DB::statement('create unique index uq_crossmatches_tenant_facility_id on crossmatches (tenant_id, facility_id, id)');

        Schema::create('transfusions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('blood_unit_id');
            $table->uuid('patient_id');
            $table->uuid('crossmatch_id');
            $table->uuid('encounter_id')->nullable();
            $table->timestampTz('started_at');
            $table->uuid('started_by_staff_id');
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('verified_by_staff_id')->nullable();
            $table->timestampTz('stopped_at')->nullable();
            $table->uuid('stopped_by_staff_id')->nullable();
            $table->integer('volume_transfused_ml')->nullable();
            $table->text('status')->default('started'); // started | completed | stopped | aborted
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'blood_unit_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('blood_units')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'crossmatch_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('crossmatches')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'started_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'verified_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'stopped_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table transfusions add constraint chk_transfusions_status check (status in ('started', 'completed', 'stopped', 'aborted'))");
        DB::statement('create unique index uq_transfusions_tenant_crossmatch on transfusions (tenant_id, crossmatch_id)');
        DB::statement('create index idx_transfusions_tenant_patient on transfusions (tenant_id, patient_id, started_at)');
        DB::statement('create index idx_transfusions_tenant_unit on transfusions (tenant_id, blood_unit_id)');
        // Backer for the reaction_reports composite FK.
        DB::statement('create unique index uq_transfusions_tenant_facility_id on transfusions (tenant_id, facility_id, id)');

        Schema::create('reaction_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('transfusion_id');
            $table->timestampTz('occurred_at');
            $table->text('severity'); // mild | moderate | severe
            $table->jsonb('symptoms')->default('[]');
            $table->text('action_taken')->nullable();
            $table->text('status')->default('reported'); // reported | reviewed | closed
            $table->timestampTz('reviewed_at')->nullable();
            $table->uuid('reviewed_by_staff_id')->nullable();
            $table->uuid('reported_by_staff_id');
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'transfusion_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('transfusions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'reported_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'reviewed_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table reaction_reports add constraint chk_reaction_severity check (severity in ('mild', 'moderate', 'severe'))");
        DB::statement("alter table reaction_reports add constraint chk_reaction_status check (status in ('reported', 'reviewed', 'closed'))");
        DB::statement('create unique index uq_reaction_reports_tenant_transfusion on reaction_reports (tenant_id, transfusion_id)');
        DB::statement('create index idx_reaction_reports_tenant_status on reaction_reports (tenant_id, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reaction_reports');
        Schema::dropIfExists('transfusions');
        Schema::dropIfExists('crossmatches');
        Schema::dropIfExists('compatibility_results');
        Schema::dropIfExists('blood_units');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('donors');
        Schema::dropIfExists('critical_care_notes');
        Schema::dropIfExists('icu_alerts');
        Schema::dropIfExists('warning_scores');
        Schema::dropIfExists('icu_observation_sets');
        Schema::dropIfExists('icu_admissions');
        Schema::dropIfExists('icu_beds');
        Schema::dropIfExists('recovery_records');
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('surgical_events');
        Schema::dropIfExists('anesthesia_records');
        Schema::dropIfExists('surgical_team_members');
        Schema::dropIfExists('procedures');
        Schema::dropIfExists('procedure_requests');
        Schema::dropIfExists('theatres');
    }
};
