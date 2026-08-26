<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Form Library & Document Workflow tables.
 *
 * Implements:
 * - Configurable form templates (versioned, categorized, role-aware)
 * - Form submissions (linked to patients/encounters/admissions)
 * - Digital signatures on form submissions
 * - Document numbering (unique, collision-safe)
 * - CSV import tracking
 * - Form template categories and departments
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── form_templates: configurable hospital form templates ──
        Schema::create('form_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->string('code', 50)->unique();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->text('description')->nullable();

            // Classification
            $table->string('category', 50); // registration, clinical, consent, specialty, pediatric, mental_health, nutrition, dental, eye, imaging, laboratory, admission, icu, pharmacy, referral, insurance, telemedicine, wellness, diagnostic
            $table->string('subcategory', 100)->nullable();
            $table->string('module', 50)->nullable(); // patient, appointment, encounter, emr, pharmacy, laboratory, radiology, ipd, billing, emergency, nursing, blood_bank, procurement, oncology, telehealth, portal
            $table->string('department', 100)->nullable();
            $table->string('specialty', 100)->nullable(); // pediatrics, orthopedics, cardiology, neurology, etc.
            $table->string('workflow', 50)->nullable(); // intake, assessment, consultation, procedure, discharge, follow_up

            // Form definition (JSON schema for form fields)
            $table->json('schema'); // field definitions: type, label, required, options, validation
            $table->json('layout')->nullable(); // layout hints: sections, columns, grouping

            // Role/module access
            $table->json('allowed_roles')->nullable(); // role codes that can use this form
            $table->json('required_modules')->nullable(); // modules that must be enabled

            // Versioning
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_published')->default(false);

            // Print/PDF
            $table->boolean('printable')->default(true);
            $table->boolean('pdf_capable')->default(true);
            $table->json('print_config')->nullable(); // header, footer, orientation, margins

            // Integration
            $table->boolean('linked_to_patient')->default(true);
            $table->boolean('linked_to_encounter')->default(false);
            $table->boolean('linked_to_admission')->default(false);
            $table->boolean('linked_to_appointment')->default(false);
            $table->boolean('generates_document_number')->default(false);
            $table->string('document_number_prefix', 10)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'category']);
            $table->index(['tenant_id', 'module']);
            $table->index(['tenant_id', 'is_active']);
        });

        // ── form_submissions: completed form instances ──
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->uuid('template_id');
            $table->integer('template_version');

            // Linkages
            $table->uuid('patient_id')->nullable();
            $table->uuid('encounter_id')->nullable();
            $table->uuid('admission_id')->nullable();
            $table->uuid('appointment_id')->nullable();

            // Submission data
            $table->json('data'); // form field values
            $table->string('document_number', 50)->nullable()->unique();
            $table->string('status', 30)->default('draft'); // draft, submitted, verified, approved, rejected, cancelled, printed, signed

            // Author
            $table->uuid('submitted_by');
            $table->string('submitted_by_type', 20)->default('staff'); // staff, patient
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // Print/PDF tracking
            $table->integer('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->uuid('last_printed_by')->nullable();

            // CSV import
            $table->uuid('import_id')->nullable();
            $table->integer('import_row')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'template_id']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'encounter_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['document_number']);
        });

        // ── form_signatures: digital signatures on form submissions ──
        Schema::create('form_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('submission_id');
            $table->string('signature_type', 30); // patient, guardian, witness, clinician, doctor, nurse, pharmacist
            $table->uuid('signer_id')->nullable();
            $table->string('signer_name', 200);
            $table->string('signer_role', 100)->nullable();
            $table->text('signature_data'); // base64 image or digital signature
            $table->string('signature_method', 20)->default('drawn'); // drawn, typed, uploaded, digital
            $table->timestamp('signed_at');
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'submission_id']);
            $table->index(['tenant_id', 'signature_type']);
        });

        // ── document_numbers: unique document numbering ──
        Schema::create('document_numbers', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('document_type', 50); // form, prescription, lab_order, lab_report, radiology, invoice, receipt, referral, consent, admission, discharge, procedure, blood_unit, sample
            $table->string('prefix', 20);
            $table->integer('sequence');
            $table->string('full_number', 50);
            $table->uuid('facility_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'document_type', 'full_number']);
            $table->index(['tenant_id', 'document_type']);
        });

        // ── csv_imports: CSV import tracking ──
        Schema::create('csv_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->string('entity_type', 50); // patient, medication, lab_test, etc.
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->integer('total_rows')->default(0);
            $table->integer('success_rows')->default(0);
            $table->integer('error_rows')->default(0);
            $table->string('status', 30)->default('pending'); // pending, validating, dry_run, importing, completed, failed
            $table->json('field_mapping')->nullable(); // CSV column -> entity field mapping
            $table->json('validation_errors')->nullable(); // row-level validation errors
            $table->json('import_errors')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('imported_by');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entity_type']);
            $table->index(['tenant_id', 'status']);
        });

        // ── form_template_categories: configurable categories ──
        Schema::create('form_template_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        // ── Enable RLS on all tables ──
        foreach (['form_templates', 'form_submissions', 'form_signatures', 'document_numbers', 'csv_imports', 'form_template_categories'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }

        // ── RLS policies ──
        $tenantUsing = 'tenant_id = swasthya_rls_tenant_id()';
        $facilityUsing = '(facility_id = swasthya_rls_facility_id() OR swasthya_rls_facility_id() IS NULL)';
        $combinedUsing = $tenantUsing.' AND '.$facilityUsing;

        // Tables WITH facility_id: use combined tenant+facility RLS
        foreach (['form_templates', 'form_submissions', 'csv_imports'] as $table) {
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON {$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_select ON {$table} FOR SELECT USING ({$combinedUsing})");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON {$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_insert ON {$table} FOR INSERT WITH CHECK (true)");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON {$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_update ON {$table} FOR UPDATE USING ({$combinedUsing}) WITH CHECK ({$combinedUsing})");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON {$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_delete ON {$table} FOR DELETE USING ({$tenantUsing})");
        }

        // Tables WITHOUT facility_id: tenant-only RLS
        foreach (['form_signatures', 'form_template_categories'] as $table) {
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_select ON {$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_select ON {$table} FOR SELECT USING ({$tenantUsing})");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_insert ON {$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_insert ON {$table} FOR INSERT WITH CHECK (true)");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_update ON {$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_update ON {$table} FOR UPDATE USING ({$tenantUsing}) WITH CHECK ({$tenantUsing})");
            DB::statement("DROP POLICY IF EXISTS p_rls_{$table}_delete ON {$table}");
            DB::statement("CREATE POLICY p_rls_{$table}_delete ON {$table} FOR DELETE USING ({$tenantUsing})");
        }

        // document_numbers: tenant-scoped (no facility requirement)
        DB::statement('DROP POLICY IF EXISTS p_rls_document_numbers_select ON document_numbers');
        DB::statement("CREATE POLICY p_rls_document_numbers_select ON document_numbers FOR SELECT USING ({$tenantUsing})");
        DB::statement('DROP POLICY IF EXISTS p_rls_document_numbers_insert ON document_numbers');
        DB::statement('CREATE POLICY p_rls_document_numbers_insert ON document_numbers FOR INSERT WITH CHECK (true)');
        DB::statement('DROP POLICY IF EXISTS p_rls_document_numbers_update ON document_numbers');
        DB::statement("CREATE POLICY p_rls_document_numbers_update ON document_numbers FOR UPDATE USING ({$tenantUsing}) WITH CHECK ({$tenantUsing})");
    }

    public function down(): void
    {
        foreach (['form_template_categories', 'csv_imports', 'document_numbers', 'form_signatures', 'form_submissions', 'form_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
