<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OT / ICU / Blood Bank gap closure — new tables and schema additions.
 *
 * Tables:
 *   implants                — lot/serial traceability per procedure
 *   procedure_specimens     — OT→Lab specimen handoff linkage
 *   blood_reservations      — unit-level blood reservation
 *   fluid_balance_entries   — ICU fluid intake/output documentation
 *   operative_notes         — versioned operative note (correction model)
 *
 * Columns added:
 *   procedure_requests      — body_site, laterality
 *
 * FK conventions follow DATABASE.md §0.9: composite (tenant_id, facility_id)
 * references for TENANT_FACILITY-tier tables. Backer unique indexes are
 * created BEFORE the child tables that reference them.
 *
 * CHECK constraints enforce DATABASE.md §0.5: every status/type text column
 * has a CHECK constraint. Timestamps use timestamptz per DATABASE.md §0.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 0. Backer indexes on parent tables ───────────────────────
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_procedures_tenant_facility_id ON procedures (tenant_id, facility_id, id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_icu_admissions_tenant_facility_id ON icu_admissions (tenant_id, facility_id, id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_blood_units_tenant_facility_id ON blood_units (tenant_id, facility_id, id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_patients_tenant_facility_id ON patients (tenant_id, facility_id, id)');

        // ── 1. Implant tracking (§26-28, §147) ──────────────────────
        Schema::create('implants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('procedure_id');
            $table->uuid('patient_id');
            $table->string('implant_name');
            $table->string('manufacturer')->nullable();
            $table->string('lot_number')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('implant_location')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('recorded_by_staff_id')->nullable();
            $table->timestampTz('implanted_at');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'procedure_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('procedures')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'patient_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'recorded_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'procedure_id']);
            $table->index(['tenant_id', 'facility_id', 'patient_id']);
            $table->index(['tenant_id', 'lot_number']);
        });

        // ── 2. Specimen handoff OT→Lab (§29-30, §148) ────────────────
        Schema::create('procedure_specimens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('procedure_id');
            $table->uuid('patient_id');
            $table->uuid('specimen_id')->nullable();
            $table->string('specimen_type');
            $table->string('body_site')->nullable();
            $table->string('laterality')->nullable();
            $table->string('status')->default('collected');
            $table->timestampTz('collected_at');
            $table->uuid('collected_by_staff_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'procedure_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('procedures')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'patient_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'collected_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'procedure_id']);
            $table->index(['tenant_id', 'facility_id', 'patient_id']);
        });

        DB::statement("ALTER TABLE procedure_specimens ADD CONSTRAINT chk_procedure_specimens_status CHECK (status IN ('collected', 'sent', 'received', 'rejected'))");

        // ── 3. Blood unit reservation (§76-77) ───────────────────────
        Schema::create('blood_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('blood_unit_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id')->nullable();
            $table->string('status')->default('active');
            $table->string('reason')->nullable();
            $table->timestampTz('reserved_at');
            $table->timestampTz('expires_at')->nullable();
            $table->uuid('reserved_by_staff_id')->nullable();
            $table->uuid('released_by_staff_id')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'blood_unit_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('blood_units')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'patient_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'reserved_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'released_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'patient_id']);
        });

        // One active reservation per unit at a time — partial unique index.
        DB::statement("CREATE UNIQUE INDEX uq_blood_reservation_active ON blood_reservations (tenant_id, blood_unit_id) WHERE status = 'active'");
        DB::statement("ALTER TABLE blood_reservations ADD CONSTRAINT chk_blood_reservations_status CHECK (status IN ('active', 'released', 'converted'))");

        // ── 4. ICU fluid balance entries (§57) ───────────────────────
        Schema::create('fluid_balance_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('icu_admission_id');
            $table->string('direction'); // intake | output
            $table->string('fluid_type');
            $table->integer('volume_ml');
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('recorded_at');
            $table->uuid('recorded_by_staff_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'icu_admission_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('icu_admissions')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'recorded_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'icu_admission_id']);
        });

        DB::statement("ALTER TABLE fluid_balance_entries ADD CONSTRAINT chk_fluid_balance_entries_direction CHECK (direction IN ('intake', 'output'))");

        // ── 5. Operative notes — versioned correction model (§33-34) ─
        Schema::create('operative_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('procedure_id');
            $table->uuid('patient_id');
            $table->text('content');
            $table->string('status')->default('draft');
            $table->uuid('parent_note_id')->nullable();
            $table->string('correction_reason')->nullable();
            $table->uuid('authored_by_staff_id');
            $table->timestampTz('authored_at');
            $table->uuid('signed_by_staff_id')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'procedure_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('procedures')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'patient_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'authored_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'signed_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'procedure_id']);
        });

        // Backer unique index for self-referencing parent_note_id FK.
        DB::statement('CREATE UNIQUE INDEX uq_operative_notes_tenant_facility_id ON operative_notes (tenant_id, facility_id, id)');
        DB::statement('ALTER TABLE operative_notes ADD CONSTRAINT operative_notes_parent_note_fk FOREIGN KEY (tenant_id, facility_id, parent_note_id) REFERENCES operative_notes (tenant_id, facility_id, id) ON DELETE RESTRICT');
        // One signed note per procedure at a time — partial unique index.
        DB::statement("CREATE UNIQUE INDEX uq_operative_note_signed ON operative_notes (tenant_id, procedure_id) WHERE status = 'signed'");
        DB::statement("ALTER TABLE operative_notes ADD CONSTRAINT chk_operative_notes_status CHECK (status IN ('draft', 'signed', 'corrected'))");

        // ── 6. Schema additions ──────────────────────────────────────
        Schema::table('procedure_requests', function (Blueprint $table): void {
            $table->string('body_site')->nullable()->after('procedure_name');
            $table->string('laterality')->nullable()->after('body_site');
        });
    }

    public function down(): void
    {
        Schema::table('procedure_requests', function (Blueprint $table): void {
            $table->dropColumn(['body_site', 'laterality']);
        });

        Schema::dropIfExists('operative_notes');
        Schema::dropIfExists('fluid_balance_entries');
        Schema::dropIfExists('blood_reservations');
        Schema::dropIfExists('procedure_specimens');
        Schema::dropIfExists('implants');

        DB::statement('DROP INDEX IF EXISTS uq_procedures_tenant_facility_id');
        DB::statement('DROP INDEX IF EXISTS uq_icu_admissions_tenant_facility_id');
        DB::statement('DROP INDEX IF EXISTS uq_blood_units_tenant_facility_id');
        DB::statement('DROP INDEX IF EXISTS uq_patients_tenant_facility_id');
    }
};
