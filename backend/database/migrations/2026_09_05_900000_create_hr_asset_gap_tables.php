<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR / Staff & Asset Management gap closure — new tables and schema additions.
 *
 * Tables:
 *   calibration_records    — medical device calibration tracking
 *   equipment_incidents    — equipment safety incident reports
 *   asset_disposals        — disposal/retirement with authorization
 *   staff_transfers        — facility/department transfer with history
 *
 * CHECK constraints enforce DATABASE.md §0.5. Timestamps use timestamptz
 * per DATABASE.md §0.3. Composite FKs follow DATABASE.md §0.9.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 0. Backer indexes ───────────────────────────────────────
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_assets_tenant_facility_id ON assets (tenant_id, facility_id, id)');

        // ── 1. Calibration records (§81-82) ─────────────────────────
        Schema::create('calibration_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('asset_id');
            $table->string('calibration_type'); // internal | external | vendor
            $table->string('provider')->nullable();
            $table->timestampTz('calibrated_at');
            $table->timestampTz('due_at');
            $table->string('result'); // pass | fail | conditional
            $table->text('notes')->nullable();
            $table->uuid('performed_by_staff_id')->nullable();
            $table->uuid('document_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'asset_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('assets')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'performed_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'asset_id']);
            $table->index(['tenant_id', 'due_at']);
        });

        DB::statement("ALTER TABLE calibration_records ADD CONSTRAINT chk_calibration_records_type CHECK (calibration_type IN ('internal', 'external', 'vendor'))");
        DB::statement("ALTER TABLE calibration_records ADD CONSTRAINT chk_calibration_records_result CHECK (result IN ('pass', 'fail', 'conditional'))");

        // ── 2. Equipment incidents (§88) ────────────────────────────
        Schema::create('equipment_incidents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('asset_id');
            $table->string('incident_type'); // malfunction | safety | near_miss | other
            $table->text('description');
            $table->uuid('reported_by_staff_id');
            $table->timestampTz('occurred_at');
            $table->string('severity'); // low | medium | high | critical
            $table->string('status')->default('open'); // open | investigating | resolved | closed
            $table->text('resolution')->nullable();
            $table->uuid('resolved_by_staff_id')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'asset_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('assets')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'reported_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'resolved_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'asset_id']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("ALTER TABLE equipment_incidents ADD CONSTRAINT chk_equipment_incidents_type CHECK (incident_type IN ('malfunction', 'safety', 'near_miss', 'other'))");
        DB::statement("ALTER TABLE equipment_incidents ADD CONSTRAINT chk_equipment_incidents_severity CHECK (severity IN ('low', 'medium', 'high', 'critical'))");
        DB::statement("ALTER TABLE equipment_incidents ADD CONSTRAINT chk_equipment_incidents_status CHECK (status IN ('open', 'investigating', 'resolved', 'closed'))");

        // ── 3. Asset disposals (§91) ────────────────────────────────
        Schema::create('asset_disposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('asset_id');
            $table->string('disposal_type'); // donated | recycled | destroyed | sold | other
            $table->text('reason');
            $table->string('authorization_ref')->nullable();
            $table->uuid('authorized_by_staff_id');
            $table->uuid('performed_by_staff_id')->nullable();
            $table->timestampTz('disposed_at');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'asset_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('assets')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'authorized_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'performed_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'asset_id']);
        });

        DB::statement("ALTER TABLE asset_disposals ADD CONSTRAINT chk_asset_disposals_type CHECK (disposal_type IN ('donated', 'recycled', 'destroyed', 'sold', 'other'))");

        // ── 4. Staff transfers (§58-59) ─────────────────────────────
        Schema::create('staff_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('staff_id');
            $table->uuid('from_department_id')->nullable();
            $table->uuid('to_department_id')->nullable();
            $table->uuid('to_facility_id')->nullable();
            $table->string('reason')->nullable();
            $table->uuid('authorized_by_staff_id');
            $table->timestampTz('effective_at');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'from_department_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('departments')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'to_department_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('departments')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'authorized_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'facility_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_transfers');
        Schema::dropIfExists('asset_disposals');
        Schema::dropIfExists('equipment_incidents');
        Schema::dropIfExists('calibration_records');

        DB::statement('DROP INDEX IF EXISTS uq_assets_tenant_facility_id');
    }
};
