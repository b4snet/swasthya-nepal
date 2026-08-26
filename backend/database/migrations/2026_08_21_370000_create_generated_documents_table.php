<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();

            // Document identity
            $table->string('document_number', 100)->comment('Unique document number (e.g. LAB-2026-00001)');
            $table->string('document_type', 50)->comment('lab_report|radiology_report|discharge_summary|invoice|receipt|prescription|referral|consent|form|clinical_note|other');
            $table->string('category', 50)->comment('clinical|financial|administrative|operational|compliance');
            $table->string('title', 255)->comment('Human-readable document title');

            // Source linkage
            $table->string('source_type', 50)->nullable()->comment('Source model class (e.g. LabOrder, RadiologyReport, Invoice)');
            $table->uuid('source_id')->nullable()->comment('Source model primary key');

            // Patient context
            $table->uuid('patient_id')->nullable();
            $table->foreign('patient_id')->references('id')->on('patients')->nullOnDelete();

            // Provider context
            $table->uuid('provider_staff_id')->nullable()->comment('Authoring provider');
            $table->string('provider_name', 255)->nullable();
            $table->string('department_name', 255)->nullable();

            // Content
            $table->text('content_html')->nullable()->comment('Rendered HTML content');
            $table->text('content_text')->nullable()->comment('Plain text content for search');
            $table->json('metadata')->nullable()->comment('Additional document metadata');

            // Branding snapshot (captured at generation time)
            $table->json('branding_snapshot')->nullable()->comment('Hospital branding at time of generation');

            // Status and lifecycle
            $table->string('status', 20)->default('generated')->comment('generated|verified|final|archived|cancelled');
            $table->boolean('verified')->default(false);
            $table->uuid('verified_by_staff_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('signed')->default(false);
            $table->uuid('signed_by_staff_id')->nullable();
            $table->timestamp('signed_at')->nullable();

            // Print/PDF
            $table->boolean('printable')->default(true);
            $table->boolean('pdf_capable')->default(true);
            $table->string('pdf_path', 500)->nullable()->comment('Storage path to generated PDF');
            $table->integer('page_count')->nullable();

            // Access control
            $table->string('visibility', 20)->default('staff')->comment('staff|patient|both');
            $table->boolean('shared_with_patient')->default(false);
            $table->timestamp('shared_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'document_number']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'document_type']);
            $table->index(['tenant_id', 'category']);
            $table->index(['source_type', 'source_id']);
        });

        // RLS: tenant isolation
        DB::statement('ALTER TABLE public.generated_documents ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.generated_documents FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY p_rls_generated_documents ON public.generated_documents
            USING (
                swasthya_rls_is_platform() = true
                OR tenant_id = swasthya_rls_tenant_id()
            )
            WITH CHECK (
                swasthya_rls_is_platform() = true
                OR tenant_id = swasthya_rls_tenant_id()
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
