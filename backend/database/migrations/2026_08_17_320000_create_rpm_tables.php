<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 25 — Remote Patient Monitoring (ROADMAP Phase 20,
 * PRODUCT_REQUIREMENTS §6.20 device feeds, DATABASE.md §3.56).
 *
 * The RPM surface is a clinical-data ingestion path with HUMAN-mediated
 * escalation (CLINICAL_SAFETY.md §7):
 *
 *  - rpm_devices — device adapters ENROLLED against a patient, with
 *    personalized thresholds (settings.thresholds) and an alert cooldown
 *    (settings.alert_cooldown_minutes). status is CAS-guarded
 *    (pending|active|disabled); only ACTIVE devices ingest.
 *  - rpm_readings — append-only, VALIDATED and clearly LABELED readings
 *    (validation_status: validated|flagged|rejected — never silently
 *    treated as verified, ROADMAP Phase 20 acceptance). Idempotent by
 *    (tenant_id, ingestion_id) for adapter retries; BRIN-indexed on
 *    received_at (DATABASE.md §4 design default for high-volume tables).
 *  - rpm_alerts — personalized-threshold breaches requiring a human
 *    acknowledgment (who/what/when) and a resolution. Dedup: one OPEN
 *    alert per (device, parameter); cooldown tuned to prevent fatigue.
 *
 * Every table is TENANT_FACILITY scoped (RLS on + FORCED in the companion
 * migration) with tenant-safe composite FKs, so cross-tenant children are
 * structurally impossible. Value payloads are clinical PHI and never reach
 * audit payloads (facts and ids only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpm_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->text('device_identifier'); // adapter-facing serial, unique per tenant
            $table->text('model')->nullable();
            $table->text('manufacturer')->nullable();
            $table->text('reading_type'); // bp, pulse, temp, spo2, glucose, weight
            $table->text('status'); // pending, active, disabled
            $table->jsonb('settings')->default('{}'); // thresholds + alert_cooldown_minutes
            $table->text('adapter')->nullable(); // transport/adapter ref (never content)
            $table->timestampTz('last_seen_at')->nullable();
            $table->uuid('created_by');
            $table->uuid('updated_by')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'device_identifier'], 'uq_rpm_devices_tenant_identifier');
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'patient_id', 'status']);

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'facility_id', 'created_by'])
                ->references(['tenant_id', 'facility_id', 'id'])->on('staff')->restrictOnDelete();
        });

        // Composite-FK support index for the alerts → devices FK.
        DB::statement('create unique index uq_rpm_devices_tenant_facility_id on rpm_devices (tenant_id, facility_id, id)');

        Schema::create('rpm_readings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('device_id');
            $table->text('reading_type');
            $table->jsonb('value'); // typed per reading_type (bp → {systolic, diastolic})
            $table->text('units')->nullable();
            $table->timestampTz('measured_at'); // device-reported time
            $table->timestampTz('received_at')->useCurrent(); // server time
            $table->text('source')->default('device');
            $table->text('validation_status'); // validated, flagged, rejected
            $table->text('validation_reason')->nullable();
            $table->jsonb('provenance')->default('{}'); // adapter, firmware, raw ref
            $table->text('ingestion_id')->nullable(); // adapter idempotency key (retries)
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'ingestion_id'], 'uq_rpm_readings_tenant_ingestion');
            $table->index(['tenant_id', 'patient_id', 'measured_at']);
            $table->index(['tenant_id', 'device_id', 'measured_at']);

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'facility_id', 'device_id'])
                ->references(['tenant_id', 'facility_id', 'id'])->on('rpm_devices')->restrictOnDelete();
        });

        // BRIN index on the append-heavy received_at column (DATABASE.md §4
        // design default for high-volume tables) and the composite-FK
        // support index for the alerts → readings FK.
        DB::statement('create index idx_rpm_readings_received_brin on rpm_readings using brin (received_at)');
        DB::statement('create unique index uq_rpm_readings_tenant_id on rpm_readings (tenant_id, id)');

        Schema::create('rpm_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('device_id')->nullable();
            $table->uuid('reading_id')->nullable();
            $table->text('alert_type'); // threshold_high, threshold_low
            $table->text('parameter'); // systolic, diastolic, value
            $table->jsonb('threshold_value');
            $table->jsonb('observed_value');
            $table->text('severity'); // low, medium, high
            $table->text('status'); // open, acknowledged, resolved
            $table->uuid('acknowledged_by')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('acknowledged_note')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'patient_id', 'status']);
            $table->index(['tenant_id', 'device_id', 'status']);

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])->on('patients')->restrictOnDelete();
            $table->foreign(['tenant_id', 'facility_id', 'device_id'])
                ->references(['tenant_id', 'facility_id', 'id'])->on('rpm_devices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reading_id'])
                ->references(['tenant_id', 'id'])->on('rpm_readings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'facility_id', 'acknowledged_by'])
                ->references(['tenant_id', 'facility_id', 'id'])->on('staff')->nullOnDelete();
            $table->foreign(['tenant_id', 'facility_id', 'resolved_by'])
                ->references(['tenant_id', 'facility_id', 'id'])->on('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rpm_alerts');
        Schema::dropIfExists('rpm_readings');
        Schema::dropIfExists('rpm_devices');
    }
};
