<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 16 — Radiology (ROADMAP Phase 11, PRODUCT_REQUIREMENTS
 * §6.9, DATABASE.md §3.29, CLINICAL_SAFETY §8). Four TENANT_FACILITY
 * tables:
 *
 *   modalities          the facility's imaging machine catalog (xray, usg,
 *                       ct, mri, …) with per-day capacity and active status
 *   studies             one row per radiology order (the shared lab_orders
 *                       surface with a category='radiology' item): ordered →
 *                       scheduled (modality + slot) → performed → reported
 *                       (cancelled is terminal)
 *   radiology_reports   the report chain — draft → preliminary → final →
 *                       amended; every amendment is a NEW row (original
 *                       preserved); final release requires verification by a
 *                       DIFFERENT radiologist (entry ≠ verification)
 *   image_references    DICOM/PACS study-instance and image references —
 *                       references only, never pixels; a reference can only
 *                       attach to a study that exists in the same tenant
 *                       (the composite FK is the no-dangling guarantee)
 *
 * All four are TENANT_FACILITY tier like the clinical spine: RLS is enabled
 * + FORCED by the companion migration (2026_08_16_230100).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modalities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->text('name');
            $table->text('modality_type'); // xray, usg, ct, mri, fluoroscopy, mammography, other
            $table->integer('daily_capacity')->default(0); // studies per day
            $table->text('status')->default('active'); // active, inactive, down
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        // Composite-FK support for studies (created below): the
        // (tenant_id, id) and (tenant_id, facility_id, id) unique indexes
        // must exist before the child tables' FKs are declared.
        DB::statement('create unique index uq_modalities_tenant_id on modalities (tenant_id, id)');
        DB::statement('create unique index uq_modalities_tenant_facility on modalities (tenant_id, facility_id, id)');

        Schema::create('studies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('lab_order_id'); // the shared order surface (category = radiology)
            $table->uuid('modality_id')->nullable(); // assigned at scheduling
            $table->text('status')->default('ordered'); // ordered → scheduled → performed → reported (cancelled terminal)
            $table->timestampTz('ordered_at');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('performed_at')->nullable();
            $table->uuid('performed_by_staff_id')->nullable();
            $table->text('cancel_reason')->nullable(); // required when cancelled
            $table->text('preparation_instructions')->nullable(); // patient prep from scheduling
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            // The study attaches to the SHARED order surface (lab_orders);
            // the composite FK is the no-dangling guarantee.
            $table->foreign(['tenant_id', 'lab_order_id'])
                ->references(['tenant_id', 'id'])
                ->on('lab_orders')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'modality_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('modalities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'performed_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        // Composite-FK support for radiology_reports + image_references
        // (created below): the (tenant_id, id) unique indexes must exist
        // before the child tables' FKs are declared.
        DB::statement('create unique index uq_studies_tenant_id on studies (tenant_id, id)');

        Schema::create('radiology_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('study_id');
            $table->text('report_type'); // preliminary, final, addendum
            $table->text('status'); // draft → preliminary → final (amended replaces final)
            $table->text('content');
            $table->text('impression')->nullable();
            $table->text('critical_findings')->nullable(); // flagged findings — never auto-escalated (see slice scope)
            $table->uuid('reported_by_staff_id');
            $table->timestampTz('reported_at');
            $table->uuid('verified_by_staff_id')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('parent_report_id')->nullable(); // amendment chain
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'study_id'])
                ->references(['tenant_id', 'id'])
                ->on('studies')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'reported_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'verified_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            // The parent_report_id (amendment chain) FK is added AFTER the
            // (tenant_id, id) unique index below — PostgreSQL validates the
            // referenced constraint at declaration time.
        });

        // Composite-FK support for radiology_reports' self-reference and
        // image_references: the (tenant_id, id) unique indexes must exist
        // before the parent FK is declared.
        DB::statement('create unique index uq_radiology_reports_tenant_id on radiology_reports (tenant_id, id)');
        DB::statement(
            'alter table radiology_reports add constraint fk_radiology_reports_parent '
            .'foreign key (tenant_id, parent_report_id) references radiology_reports (tenant_id, id) on delete restrict'
        );

        Schema::create('image_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('study_id');
            $table->text('reference_type'); // dicom_study_instance_uid, dicom_series_instance_uid, dicom_sop_instance_uid, pacs_url
            $table->text('reference_value');
            $table->text('description')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'study_id'])
                ->references(['tenant_id', 'id'])
                ->on('studies')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table modalities add constraint chk_modalities_type check (modality_type in ('xray', 'usg', 'ct', 'mri', 'fluoroscopy', 'mammography', 'other'))"
        );
        DB::statement(
            "alter table modalities add constraint chk_modalities_status check (status in ('active', 'inactive', 'down'))"
        );
        DB::statement(
            "alter table studies add constraint chk_studies_status check (status in ('ordered', 'scheduled', 'performed', 'reported', 'cancelled'))"
        );
        DB::statement(
            "alter table studies add constraint chk_studies_cancel_reason check (status <> 'cancelled' or cancel_reason is not null)"
        );
        DB::statement(
            "alter table radiology_reports add constraint chk_radiology_reports_type check (report_type in ('preliminary', 'final', 'addendum'))"
        );
        DB::statement(
            "alter table radiology_reports add constraint chk_radiology_reports_status check (status in ('draft', 'preliminary', 'final', 'amended'))"
        );
        DB::statement(
            'alter table radiology_reports add constraint chk_radiology_reports_verified check (verified_at is null or verified_by_staff_id is not null)'
        );

        // One modality code per (tenant, facility) while active.
        DB::statement(
            'create unique index uq_modalities_tenant_facility_code on modalities (tenant_id, facility_id, code) where deleted_at is null'
        );
        DB::statement('create index idx_modalities_tenant_facility on modalities (tenant_id, facility_id)');

        // One study per order (the shared order surface creates the study at
        // order time); one ACTIVE final report per study (an amendment
        // supersedes — the old final becomes 'amended').
        DB::statement('create unique index uq_studies_tenant_order on studies (tenant_id, lab_order_id)');
        DB::statement('create unique index uq_studies_tenant_modality_scheduled on studies (tenant_id, modality_id, scheduled_at)');
        DB::statement('create index idx_studies_tenant_facility_status on studies (tenant_id, facility_id, status, ordered_at)');
        DB::statement('create index idx_studies_tenant_patient on studies (tenant_id, lab_order_id)');

        DB::statement(
            'create unique index uq_radiology_reports_tenant_study_final on radiology_reports (tenant_id, study_id) where status = \'final\''
        );
        DB::statement('create index idx_radiology_reports_tenant_study on radiology_reports (tenant_id, study_id)');

        DB::statement('create index idx_image_references_tenant_study on image_references (tenant_id, study_id)');
        DB::statement('create index idx_image_references_tenant_value on image_references (tenant_id, reference_value)');
    }

    public function down(): void
    {
        Schema::dropIfExists('image_references');
        Schema::dropIfExists('radiology_reports');
        Schema::dropIfExists('studies');
        Schema::dropIfExists('modalities');
    }
};
