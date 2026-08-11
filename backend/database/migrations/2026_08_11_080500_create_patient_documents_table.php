<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient documents (DATABASE.md §3.38): metadata for every file — consent
 * forms, IDs, referrals, reports. The object key is the pointer; the bytes
 * live in object storage.
 *
 * Object storage does NOT exist yet (no S3, no signed URLs — SECURITY.md
 * §12 design only). Phase 5 implements the honest metadata half: a document
 * is registered as `status = staged` with no object key; it becomes
 * `available` only when the storage integration lands. No endpoint pretends
 * a file can be downloaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('patient_id')->nullable();
            $table->text('document_type');
            $table->string('object_key')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->text('status')->default('staged');
            $table->uuid('uploaded_by')->nullable();
            $table->timestampTz('uploaded_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->text('retention_class')->nullable();
            $table->uuid('parent_document_id')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();
        });

        // The self-referencing parent_document_id FK must be added AFTER the
        // table (and its primary key) exist — PostgreSQL rejects a deferred
        // alter that references a key that does not exist yet.
        Schema::table('patient_documents', function (Blueprint $table): void {
            $table->foreign('parent_document_id')
                ->references('id')
                ->on('patient_documents')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table patient_documents add constraint chk_documents_type check (document_type in ('consent', 'id', 'referral', 'report', 'discharge', 'other'))"
        );
        DB::statement(
            "alter table patient_documents add constraint chk_documents_status check (status in ('staged', 'available', 'archived', 'purged'))"
        );

        DB::statement('create index idx_documents_tenant_patient on patient_documents (tenant_id, patient_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_documents');
    }
};
