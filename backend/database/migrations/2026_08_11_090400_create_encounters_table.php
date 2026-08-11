<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encounters (DATABASE.md §3.17): the clinical visit container — the spine
 * of the clinical record. An OPD visit is started from a checked-in
 * appointment; one encounter per appointment (partial unique).
 *
 * Tenant-scoped with tenant-safe composite FKs. Signed encounters are
 * immutable history — amendment is the only evolution path (Phase 8+);
 * `lock_version` guards concurrent edits of open encounters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('appointment_id')->nullable();
            $table->uuid('provider_staff_id');
            $table->text('type')->default('opd');
            $table->text('status')->default('open'); // open → signed (→ amended / closed, later phases)
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->uuid('signed_by')->nullable();
            $table->timestampTz('signed_at')->nullable();
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

            $table->foreign(['tenant_id', 'facility_id', 'appointment_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('appointments')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'provider_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign('signed_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table encounters add constraint chk_encounters_type check (type in ('opd', 'ipd', 'er', 'teleconsult'))"
        );
        DB::statement(
            "alter table encounters add constraint chk_encounters_status check (status in ('open', 'in_progress', 'signed', 'amended', 'closed'))"
        );

        // One encounter per appointment where appointment-driven.
        DB::statement(
            'create unique index uq_encounters_tenant_appointment on encounters (tenant_id, appointment_id) where appointment_id is not null'
        );
        // Composite-FK support: diagnoses, clinical_notes, prescriptions,
        // charges reference encounters via (tenant_id, id).
        DB::statement('create unique index uq_encounters_tenant_id on encounters (tenant_id, id)');
        DB::statement('create index idx_encounters_tenant_patient_start on encounters (tenant_id, patient_id, started_at)');
        DB::statement('create index idx_encounters_tenant_provider_start on encounters (tenant_id, provider_staff_id, started_at)');
        DB::statement('create index idx_encounters_tenant_facility_status on encounters (tenant_id, facility_id, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('encounters');
    }
};
