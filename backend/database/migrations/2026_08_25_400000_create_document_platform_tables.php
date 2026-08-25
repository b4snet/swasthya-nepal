<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospital_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('facility_id')->nullable()->index();
            $t->string('document_code')->unique();
            $t->string('document_type')->index();
            $t->string('category')->index();
            $t->string('classification')->default('internal');
            $t->string('title');
            $t->text('description')->nullable();
            $t->uuid('patient_id')->nullable()->index();
            $t->uuid('encounter_id')->nullable();
            $t->uuid('staff_id')->nullable()->index();
            $t->string('department')->nullable()->index();
            $t->string('source_type')->nullable();
            $t->uuid('source_id')->nullable();
            $t->uuid('parent_document_id')->nullable()->index();
            $t->integer('version')->default(1);
            $t->boolean('is_latest')->default(true);
            $t->string('file_path')->nullable();
            $t->string('file_hash')->nullable();
            $t->string('mime_type')->nullable();
            $t->bigInteger('file_size_bytes')->nullable();
            $t->string('object_key')->nullable();
            $t->json('metadata')->nullable();
            $t->json('tags')->nullable();
            $t->string('status')->default('draft');
            $t->uuid('uploaded_by')->nullable();
            $t->uuid('finalized_by')->nullable();
            $t->timestamp('finalized_at')->nullable();
            $t->integer('retention_days')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();

            $t->index(['category', 'document_type']);
            $t->index(['patient_id', 'category']);
        });

        Schema::create('document_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('document_id')->index();
            $t->integer('version_number');
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_hash')->nullable();
            $t->string('mime_type')->nullable();
            $t->bigInteger('file_size_bytes')->nullable();
            $t->string('object_key')->nullable();
            $t->text('change_reason')->nullable();
            $t->uuid('created_by')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();

            $t->unique(['document_id', 'version_number']);
        });

        Schema::create('document_acknowledgements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('document_id')->index();
            $t->uuid('user_id')->index();
            $t->uuid('staff_id')->nullable();
            $t->string('status')->default('pending');
            $t->timestamp('read_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('user_agent')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();

            $t->unique(['document_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_acknowledgements');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('hospital_documents');
    }
};
