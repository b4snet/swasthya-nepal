<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 7 — Laboratory critical-value escalation (PRODUCT_REQUIREMENTS
 * §6.8 workflow 6, CLINICAL_SAFETY §7, MASTER_RULES §11.3).
 *
 * A critical/panic value is "the loudest event in the product": the result
 * enterer flags it (isCritical at entry), which triggers a critical_value_event
 * targeted at the ordering clinician. The clinician ACKNOWLEDGES it (recorded
 * who/when); if it stays unacknowledged, a supervisor ESCALATES it — fail
 * loudly, never silently. Acknowledgment after escalation still closes the
 * loop (escalation does not remove the target's responsibility).
 *
 *   triggered → acknowledged   (target clinician, lab:acknowledge)
 *   triggered → escalated      (supervisor, lab:escalate, never the target)
 *   escalated → acknowledged   (target clinician — escalation keeps it loud
 *                               until a human closes it)
 *
 * Every transition is a compare-and-swap on (status, lock_version). One OPEN
 * event per item is enforced by the partial unique (tenant_id,
 * lab_order_item_id) on the open statuses — a repeated trigger while open
 * affects zero rows; after acknowledgment a NEW event can be raised (a
 * correction touching a critical value re-runs escalation, CLINICAL_SAFETY
 * §7). The event references the flagged lab_order_item (which carries the
 * result) but stores NO result value itself.
 *
 * Money/audit note: notification delivery (SMS/email) is the notifications
 * module (DATABASE.md §3.37) — this slice records the event and its
 * acknowledgment/escalation trail; the timing-based auto-escalation job is a
 * later-phase scheduler concern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('critical_value_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('lab_order_item_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id');
            $table->uuid('target_staff_id'); // the ordering clinician who must acknowledge
            $table->text('status')->default('triggered'); // triggered, escalated, acknowledged
            $table->uuid('detected_by_staff_id');
            $table->timestampTz('detected_at');
            $table->uuid('escalated_by_staff_id')->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->uuid('acknowledged_by_staff_id')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'lab_order_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('lab_order_items')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'target_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'detected_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table critical_value_events add constraint chk_critical_value_events_status check (status in ('triggered', 'escalated', 'acknowledged'))"
        );

        // One OPEN event per item: a repeated trigger while open is a no-op,
        // but after acknowledgment (or a correction) a fresh event is legal.
        DB::statement(
            "create unique index uq_critical_value_events_tenant_item_open on critical_value_events (tenant_id, lab_order_item_id) where status in ('triggered', 'escalated')"
        );

        DB::statement('create index idx_critical_value_events_tenant_facility_status on critical_value_events (tenant_id, facility_id, status, detected_at)');
        DB::statement('create index idx_critical_value_events_tenant_encounter on critical_value_events (tenant_id, encounter_id)');
        DB::statement('create index idx_critical_value_events_tenant_target on critical_value_events (tenant_id, target_staff_id, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('critical_value_events');
    }
};
