<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 4 — discharge & follow-up (PRODUCT_REQUIREMENTS §6.7):
 *
 * follow_ups: a planned return visit or teleconsult, linked to the encounter
 * that generated it (planned → booked → completed, or cancelled). The book
 * transition links the plan to a real appointment of the same patient in the
 * same facility; cancellation records a reason.
 *
 * Encounters gain the discharge stamps (disposition, discharge summary,
 * discharged_by/at): a signed encounter transitions to closed when
 * discharged — discharge is the clinical close of the visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id');
            $table->uuid('provider_staff_id');
            $table->text('follow_up_type'); // return_visit, teleconsult
            $table->timestampTz('planned_at');
            $table->text('reason')->nullable();
            $table->uuid('booked_appointment_id')->nullable();
            $table->text('status')->default('planned'); // planned, booked, completed, cancelled
            $table->text('cancel_reason')->nullable();
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

            $table->foreign(['tenant_id', 'facility_id', 'provider_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'booked_appointment_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('appointments')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table follow_ups add constraint chk_follow_ups_type check (follow_up_type in ('return_visit', 'teleconsult'))"
        );
        DB::statement(
            "alter table follow_ups add constraint chk_follow_ups_status check (status in ('planned', 'booked', 'completed', 'cancelled'))"
        );

        // Composite-FK support: nothing references follow_ups yet, but the
        // tenant-scoped unique keeps the shape consistent with every other
        // tenant-owned table.
        DB::statement('create unique index uq_follow_ups_tenant_id on follow_ups (tenant_id, id)');
        DB::statement('create index idx_follow_ups_tenant_patient on follow_ups (tenant_id, patient_id, planned_at)');
        DB::statement('create index idx_follow_ups_tenant_encounter on follow_ups (tenant_id, encounter_id)');
        DB::statement('create index idx_follow_ups_tenant_provider on follow_ups (tenant_id, provider_staff_id, planned_at)');
        DB::statement('create index idx_follow_ups_tenant_status on follow_ups (tenant_id, facility_id, status, planned_at)');

        // Discharge stamps on the encounter (signed → closed).
        Schema::table('encounters', function (Blueprint $table): void {
            $table->text('disposition')->nullable(); // home, admitted, referred, deceased
            $table->text('discharge_summary')->nullable();
            $table->uuid('discharged_by')->nullable();
            $table->timestampTz('discharged_at')->nullable();
        });

        DB::statement(
            "alter table encounters add constraint chk_encounters_disposition check (disposition is null or disposition in ('home', 'admitted', 'referred', 'deceased'))"
        );
    }

    public function down(): void
    {
        DB::statement('alter table encounters drop constraint if exists chk_encounters_disposition');
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropColumn(['disposition', 'discharge_summary', 'discharged_by', 'discharged_at']);
        });
        Schema::dropIfExists('follow_ups');
    }
};
