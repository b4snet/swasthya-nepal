<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 — Patient Portal PHR tables.
 *
 * Tables:
 *  - patient_consent_records: granular consent for each PHR data category
 *  - patient_notification_preferences: per-patient notification channel prefs
 *  - secure_messages: patient-to-provider secure messaging
 *
 * NOTE: patient_documents already exists (2026_08_11_080500). This migration
 * adds the Phase 16 portal-access columns to the existing table via ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Extend existing patient_documents with portal-access columns ──
        Schema::table('patient_documents', function (Blueprint $table): void {
            $table->uuid('facility_id')->nullable()->after('tenant_id');
            $table->uuid('encounter_id')->nullable()->after('patient_id');
            $table->text('title')->nullable()->after('encounter_id');
            $table->text('description')->nullable()->after('title');
            $table->text('file_path')->nullable()->after('description');
            $table->text('file_hash')->nullable()->after('checksum');
            $table->text('visibility')->default('patient_only')->after('file_hash');
            $table->boolean('patient_accessible')->default(true)->after('visibility');
            $table->uuid('uploaded_by_staff_id')->nullable()->after('uploaded_by');
            $table->boolean('phi_safe')->default(true)->after('patient_accessible');
        });

        // ── patient_consent_records ──
        Schema::create('patient_consent_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->text('data_category');
            $table->text('consent_status');
            $table->text('purpose')->nullable();
            $table->text('granted_by')->nullable();
            $table->uuid('granted_by_staff_id')->nullable();
            $table->timestampTz('granted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
        });

        DB::statement("CREATE UNIQUE INDEX uq_patient_consent_category ON patient_consent_records (patient_id, data_category) WHERE consent_status = 'granted'");
        DB::statement('CREATE INDEX idx_patient_consent_patient ON patient_consent_records (patient_id, data_category)');

        // ── patient_notification_preferences ──
        Schema::create('patient_notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('appointment_reminders')->default(true);
            $table->boolean('result_notifications')->default(true);
            $table->boolean('billing_notifications')->default(true);
            $table->boolean('messaging_notifications')->default(true);
            $table->boolean('marketing_opt_out')->default(false);
            $table->text('preferred_language')->default('en');
            $table->text('timezone')->default('Asia/Kathmandu');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX uq_patient_notif_prefs ON patient_notification_preferences (patient_id)');

        // ── secure_messages ──
        Schema::create('secure_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('sender_staff_id')->nullable();
            $table->boolean('sender_is_patient')->default(false);
            $table->uuid('recipient_staff_id')->nullable();
            $table->boolean('recipient_is_patient')->default(false);
            $table->text('subject');
            $table->text('body');
            $table->text('status')->default('unread');
            $table->text('category')->default('general');
            $table->uuid('related_encounter_id')->nullable();
            $table->boolean('phi_safe')->default(true);
            $table->timestampTz('read_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
        });

        DB::statement('CREATE INDEX idx_secure_messages_patient ON secure_messages (patient_id, created_at DESC)');
        DB::statement('CREATE INDEX idx_secure_messages_staff ON secure_messages (recipient_staff_id, status) WHERE recipient_staff_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_messages');
        Schema::dropIfExists('patient_notification_preferences');
        Schema::dropIfExists('patient_consent_records');

        // Revert patient_documents ALTER (drop added columns)
        Schema::table('patient_documents', function (Blueprint $table): void {
            $table->dropColumn([
                'facility_id', 'encounter_id', 'title', 'description',
                'file_path', 'file_hash', 'visibility', 'patient_accessible',
                'uploaded_by_staff_id', 'phi_safe',
            ]);
        });
    }
};
