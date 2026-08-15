<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 2 — Laboratory & radiology order lifecycle (DATABASE.md
 * §3.25–3.27, PRODUCT_REQUIREMENTS §6.8). Three tenant+facility-scoped
 * tables:
 *
 *   lab_tests        the tenant's test catalog (laboratory + radiology
 *                    studies share this reference, mirroring medications)
 *   lab_orders       the order container, driven by one status state machine:
 *                    ordered → collected → processing → results_entered →
 *                    verified → reported (reported is immutable)
 *   lab_order_items  one row per ordered test; carries the entered/verified
 *                    result value, unit, and the reference range snapshot
 *                    taken at order time (the report shows the range the
 *                    result was measured against)
 *
 * All three are TENANT_FACILITY tier like medications/encounters: RLS is
 * enabled + FORCED by the companion migration (2026_08_15_130100). Entry ≠
 * verification is enforced at the application layer (distinct permissions
 * lab:result_entry vs lab:verify AND a different-staff guard); the DB layer
 * enforces the state machine's legality via the status CHECK constraint and
 * one-row-per-test uniqueness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_tests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('code', 50);
            $table->text('name');
            $table->text('category')->default('laboratory'); // laboratory, hematology, biochemistry, microbiology, immunology, pathology, radiology, ultrasound, other
            $table->text('sample_type')->nullable(); // blood, urine, swab, …; null for imaging studies
            $table->text('unit')->nullable();
            $table->text('reference_range')->nullable();
            $table->text('method')->nullable();
            $table->text('status')->default('active'); // active, inactive
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

        Schema::create('lab_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id');
            $table->uuid('ordered_by_staff_id');
            $table->text('priority')->default('routine'); // routine, urgent, stat
            $table->text('status')->default('ordered'); // ordered → collected → processing → results_entered → verified → reported
            $table->text('clinical_indication')->nullable();
            $table->timestampTz('ordered_at');
            $table->uuid('collected_by_staff_id')->nullable();
            $table->timestampTz('collected_at')->nullable();
            $table->timestampTz('processing_at')->nullable();
            $table->uuid('verified_by_staff_id')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('reported_by_staff_id')->nullable();
            $table->timestampTz('reported_at')->nullable();
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

            // Encounters carry their own facility_id, so the composite FK is
            // (tenant_id, encounter_id) → (tenant_id, id) — the established
            // child pattern (diagnoses/clinical_notes reference encounters
            // the same way).
            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'ordered_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'collected_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'verified_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'reported_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        // Composite-FK support for lab_order_items (created below): the
        // (tenant_id, id) unique indexes must exist before the child table's
        // FKs are declared.
        DB::statement('create unique index uq_lab_tests_tenant_id on lab_tests (tenant_id, id)');
        DB::statement('create unique index uq_lab_orders_tenant_id on lab_orders (tenant_id, id)');

        Schema::create('lab_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('lab_order_id');
            $table->uuid('lab_test_id');
            $table->text('result_value')->nullable();
            $table->text('result_unit')->nullable();
            $table->text('reference_range')->nullable(); // snapshot from the catalog at order time
            $table->uuid('entered_by_staff_id')->nullable();
            $table->timestampTz('entered_at')->nullable();
            $table->uuid('verified_by_staff_id')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'lab_order_id'])
                ->references(['tenant_id', 'id'])
                ->on('lab_orders')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'lab_test_id'])
                ->references(['tenant_id', 'id'])
                ->on('lab_tests')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table lab_tests add constraint chk_lab_tests_status check (status in ('active', 'inactive'))"
        );
        DB::statement(
            "alter table lab_tests add constraint chk_lab_tests_category check (category in ('laboratory', 'hematology', 'biochemistry', 'microbiology', 'immunology', 'pathology', 'radiology', 'ultrasound', 'other'))"
        );
        DB::statement(
            "alter table lab_orders add constraint chk_lab_orders_status check (status in ('ordered', 'collected', 'processing', 'results_entered', 'verified', 'reported'))"
        );
        DB::statement(
            "alter table lab_orders add constraint chk_lab_orders_priority check (priority in ('routine', 'urgent', 'stat'))"
        );

        // One catalog entry per (tenant, facility, code) while active.
        DB::statement(
            'create unique index uq_lab_tests_tenant_facility_code on lab_tests (tenant_id, facility_id, code) where deleted_at is null'
        );
        DB::statement('create index idx_lab_tests_tenant_facility on lab_tests (tenant_id, facility_id)');
        DB::statement('create index idx_lab_tests_tenant_name on lab_tests (tenant_id, name)');

        DB::statement('create index idx_lab_orders_tenant_facility_status on lab_orders (tenant_id, facility_id, status, ordered_at)');
        DB::statement('create index idx_lab_orders_tenant_patient on lab_orders (tenant_id, patient_id, ordered_at)');
        DB::statement('create index idx_lab_orders_tenant_encounter on lab_orders (tenant_id, encounter_id)');

        // One item per test per order.
        DB::statement('create unique index uq_lab_order_items_tenant_order_test on lab_order_items (tenant_id, lab_order_id, lab_test_id)');
        DB::statement('create unique index uq_lab_order_items_tenant_id on lab_order_items (tenant_id, id)');
        DB::statement('create index idx_lab_order_items_tenant_order on lab_order_items (tenant_id, lab_order_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_order_items');
        Schema::dropIfExists('lab_orders');
        Schema::dropIfExists('lab_tests');
    }
};
