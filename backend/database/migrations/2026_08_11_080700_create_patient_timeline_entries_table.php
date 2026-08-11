<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patient timeline foundation (PRODUCT_REQUIREMENTS §6.1: patient_timeline_entry):
 * one chronological, patient-scoped view of every event — registration,
 * identifier/contact/policy changes, consents, documents, merges. Later
 * phases append encounters, admissions, results, and bills.
 *
 * Written ONLY by the PatientTimeline service alongside the audit logger;
 * entries carry facts and references (never clinical content — the same
 * no-PHI rule as audit payloads, MASTER_RULES.md §10.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_timeline_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('patient_id');
            $table->timestampTz('occurred_at');
            $table->text('event_type');
            $table->jsonb('summary')->default('{}');
            $table->uuid('actor_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();
        });

        Schema::table('patient_timeline_entries', function (Blueprint $table): void {
            $table->index(['tenant_id', 'patient_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_timeline_entries');
    }
};
