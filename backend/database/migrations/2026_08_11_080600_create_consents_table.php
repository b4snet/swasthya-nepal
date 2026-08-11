<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient consents (DATABASE.md §3.39): consent as a first-class, versioned,
 * auditable record — treatment, data use, telehealth, marketing opt-out,
 * research.
 *
 * One active consent per (patient, type); a new capture creates a NEW
 * version (the old one is superseded by status, never deleted — consent
 * history outlives the consent). `document_id` links the signed artifact
 * when the documents phase provides one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('patient_id');
            $table->text('consent_type');
            $table->integer('version');
            $table->text('status')->default('active');
            $table->jsonb('scope')->default('{}');
            $table->uuid('given_by')->nullable();
            $table->timestampTz('given_at');
            $table->uuid('revoked_by')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->uuid('document_id')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign('document_id')
                ->references('id')
                ->on('patient_documents')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table consents add constraint chk_consents_type check (consent_type in ('treatment', 'data_use', 'telehealth', 'marketing', 'research'))"
        );
        DB::statement(
            "alter table consents add constraint chk_consents_status check (status in ('active', 'revoked', 'expired'))"
        );

        // One active consent per (patient, type); new capture = new version.
        DB::statement(
            'create unique index uq_consents_tenant_patient_type on consents (tenant_id, patient_id, consent_type) where status = \'active\''
        );
        DB::statement('create index idx_consents_tenant_patient on consents (tenant_id, patient_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
