<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospital_brandings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();

            // Hospital identity
            $table->string('hospital_name')->nullable();
            $table->string('hospital_name_local')->nullable()->comment('Name in local language (e.g. Nepali)');
            $table->string('logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('primary_color')->nullable()->default('#0891b2')->comment('Brand primary color hex');
            $table->string('secondary_color')->nullable()->default('#1e293b')->comment('Brand secondary color hex');

            // Contact
            $table->string('phone')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // Address
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable()->default('Nepal');
            $table->string('postal_code')->nullable();

            // Document configuration
            $table->text('document_header')->nullable()->comment('Printed at top of documents');
            $table->text('document_footer')->nullable()->comment('Printed at bottom of documents');
            $table->text('letterhead_text')->nullable()->comment('Letterhead text for formal correspondence');
            $table->string('date_format')->nullable()->default('Y-m-d');
            $table->string('time_format')->nullable()->default('H:i');
            $table->string('currency')->nullable()->default('NPR');
            $table->string('currency_symbol')->nullable()->default('Rs.');
            $table->decimal('vat_rate', 5, 2)->nullable()->default(0);
            $table->string('vat_number')->nullable();
            $table->string('registration_number')->nullable()->comment('Hospital registration/license number');
            $table->string('pan_number')->nullable()->comment('Tax PAN number');

            // Footer / legal
            $table->text('terms_and_conditions')->nullable();
            $table->text('privacy_policy')->nullable();

            $table->integer('version')->default(1);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'facility_id']);
        });

        // RLS: tenant isolation
        DB::statement('ALTER TABLE public.hospital_brandings ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.hospital_brandings FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY p_rls_hospital_brandings ON public.hospital_brandings
            USING (
                swasthya_rls_is_platform() = true
                OR tenant_id = swasthya_rls_tenant_id()
            )
            WITH CHECK (
                swasthya_rls_is_platform() = true
                OR tenant_id = swasthya_rls_tenant_id()
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_brandings');
    }
};
