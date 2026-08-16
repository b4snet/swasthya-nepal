<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 24 — Telehealth (ROADMAP Phase 19, PRODUCT_REQUIREMENTS
 * §6.20): virtual consultations integrated with the same record, not a
 * separate product.
 *
 * Two tables:
 *
 *   - `teleconsults`: the virtual-consultation appointment record. A
 *     teleconsult is booked through the SAME schedule/queue model as an
 *     in-person visit — the appointment row carries appointment_type =
 *     'teleconsult' (Appointment::TYPE_TELECONSULT, already part of the
 *     appointments CHECK). The teleconsult row then carries the virtual
 *     session state machine:
 *       scheduled → ready → in_progress → completed
 *                   ↘ cancelled / failed (connectivity failure → the
 *                     documented fallback mode, CLINICAL_SAFETY.md §7)
 *
 *   - `video_sessions`: the secure video session metadata (WebRTC-ready:
 *     the session id + participant + start/end + recording state). NEVER
 *     pixels, NEVER a media stream — video content is out of the database
 *     (object storage or a streaming relay; this layer records the
 *     metadata and the EXPLICIT recording decision). Recording is
 *     consent-bound and policy-bound: `recording_policy` is a facility
 *     setting (disabled | consent_required | always_allowed — default
 *     disabled), and starting a recording requires the separate
 *     telehealth:record permission AND the patient's ACTIVE telehealth
 *     consent covering 'recording' when the policy requires it.
 *
 * Both tables are TENANT_FACILITY (virtual consultations are facility-run
 * care, exactly like OPD — the facility runs the clinic, the tenant owns
 * the record). RLS enabled + FORCED by the companion migration.
 *
 * Policy count added: 2 tables × 4 = 8 (468 → 476).
 * Scoped matrix: 118 → 120 tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teleconsults', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('appointment_id');
            $table->uuid('patient_id');
            $table->uuid('provider_staff_id');
            $table->text('status')->default('scheduled'); // scheduled | ready | in_progress | completed | cancelled | failed
            $table->timestampTz('scheduled_at');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            // Connectivity-failure fallback (CLINICAL_SAFETY.md §7): when the
            // video session fails, the consult continues via a documented
            // fallback mode — phone / in-person / reschedule. The reason is
            // clinical-adjacent and stays in the clinical row, never in logs.
            $table->text('fallback_mode')->nullable(); // phone | in_person | reschedule
            $table->text('fallback_reason')->nullable();
            $table->uuid('created_by_staff_id')->nullable();
            $table->uuid('updated_by_staff_id')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'appointment_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('appointments')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'provider_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'created_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table teleconsults add constraint chk_teleconsults_status check (status in ('scheduled', 'ready', 'in_progress', 'completed', 'cancelled', 'failed'))"
        );
        DB::statement(
            "alter table teleconsults add constraint chk_teleconsults_fallback check (fallback_mode is null or fallback_mode in ('phone', 'in_person', 'reschedule'))"
        );
        // One teleconsult per teleconsult appointment (the booking is unique).
        DB::statement('create unique index uq_teleconsults_appointment on teleconsults (tenant_id, appointment_id)');
        DB::statement('create index idx_teleconsults_tenant_facility_status on teleconsults (tenant_id, facility_id, status)');
        DB::statement('create index idx_teleconsults_tenant_facility_scheduled on teleconsults (tenant_id, facility_id, scheduled_at)');
        // Backs the video_sessions composite FK.
        DB::statement('create unique index uq_teleconsults_tenant_facility_id on teleconsults (tenant_id, facility_id, id)');

        Schema::create('video_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('teleconsult_id');
            $table->text('status')->default('active'); // active | ended | failed
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            // The WebRTC session identity (relay/sfu room id) — a reference
            // only; the media itself never enters the database.
            $table->string('provider_session_ref', 255)->nullable();
            $table->text('participant_type')->default('provider'); // provider | patient
            $table->boolean('recording_requested')->default(false);
            $table->boolean('recording_consent_verified')->default(false);
            $table->timestampTz('recording_started_at')->nullable();
            $table->timestampTz('recording_ended_at')->nullable();
            $table->string('recording_storage_ref', 255)->nullable();
            // Connectivity failure: documented, audited — the consult falls
            // back to the teleconsult.fallback_mode, never silently drops.
            $table->text('failure_reason')->nullable();
            $table->uuid('created_by_staff_id')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'teleconsult_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('teleconsults')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'created_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table video_sessions add constraint chk_video_sessions_status check (status in ('active', 'ended', 'failed'))"
        );
        DB::statement(
            "alter table video_sessions add constraint chk_video_sessions_participant check (participant_type in ('provider', 'patient'))"
        );
        DB::statement('create index idx_video_sessions_tenant_teleconsult on video_sessions (tenant_id, facility_id, teleconsult_id, started_at desc)');
    }

    public function down(): void
    {
        Schema::dropIfExists('video_sessions');
        Schema::dropIfExists('teleconsults');
    }
};
