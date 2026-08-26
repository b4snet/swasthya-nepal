<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();

            // Template identity
            $table->string('code', 100)->comment('Unique template code (e.g. appt_confirm)');
            $table->string('name')->comment('Human-readable name');
            $table->string('category', 50)->comment('appointment|followup|result|billing|discharge|portal|general');
            $table->string('type', 50)->comment('confirmation|reminder|missed|invitation|notification|alert');

            // Channels — which delivery channels this template supports
            $table->boolean('channel_in_app')->default(true);
            $table->boolean('channel_email')->default(false);
            $table->boolean('channel_sms')->default(false);
            $table->boolean('channel_whatsapp')->default(false)->comment('WhatsApp handoff (prefilled link or Business API)');

            // Content
            $table->string('subject')->nullable()->comment('Subject line for email/in-app');
            $table->text('body_template')->comment('Body with {{variable}} placeholders');
            $table->text('whatsapp_message')->nullable()->comment('WhatsApp-specific message (shorter)');
            $table->text('sms_message')->nullable()->comment('SMS-specific message (160 char limit)');

            // Variable schema — documents available variables for UI rendering
            $table->json('variables')->nullable()->comment('Array of {name, label, type, required, example}');

            // Retry / delivery configuration
            $table->integer('retry_count')->default(0)->comment('Number of retries on failure (0 = no retry)');
            $table->integer('retry_delay_minutes')->default(60)->comment('Minutes between retries');
            $table->boolean('enabled')->default(true);

            // Locale
            $table->string('locale', 10)->default('en');

            // Metadata
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        // RLS: tenant isolation
        DB::statement('ALTER TABLE public.communication_templates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.communication_templates FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY p_rls_communication_templates ON public.communication_templates
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
        Schema::dropIfExists('communication_templates');
    }
};
