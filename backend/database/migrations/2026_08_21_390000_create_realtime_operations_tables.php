<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();

            // Event identity
            $table->string('event_type', 50)->comment('appointment.check_in, queue.update, lab.critical_value, etc.');
            $table->string('category', 30)->comment('appointment|clinical|pharmacy|billing|admin|system');
            $table->string('severity', 20)->default('info')->comment('info|warning|urgent|critical');
            $table->string('priority', 20)->default('normal')->comment('low|normal|high|urgent');

            // Content
            $table->string('title', 255);
            $table->text('message')->nullable();
            $table->json('metadata')->nullable()->comment('Context-specific payload');
            $table->string('action_url', 500)->nullable()->comment('Deep-link for click-through');

            // Routing
            $table->string('channel', 30)->default('operations')->comment('operations|clinical|finance|admin|emergency');
            $table->json('target_roles')->nullable()->comment('Roles that should receive this event');
            $table->json('target_users')->nullable()->comment('Specific user IDs (null = role-based)');
            $table->boolean('broadcast')->default(false)->comment('Whether to broadcast to all eligible users');

            // Source linkage
            $table->string('source_type', 50)->nullable()->comment('Source model class');
            $table->string('source_id', 36)->nullable()->comment('Source model primary key');

            // Delivery tracking
            $table->integer('delivered_count')->default(0);
            $table->integer('acknowledged_count')->default(0);
            $table->integer('acknowledgement_required_count')->default(0);
            $table->boolean('acknowledgement_required')->default(false);

            // Lifecycle
            $table->string('status', 20)->default('active')->comment('active|expired|cancelled');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate();

            // Indexes
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'category']);
            $table->index(['tenant_id', 'severity']);
            $table->index(['tenant_id', 'channel']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['source_type', 'source_id']);
            $table->index(['status', 'expires_at']);
        });

        // ── Realtime event receipts (per-user delivery tracking) ──
        Schema::create('realtime_event_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('event_id');
            $table->uuid('user_id');

            $table->string('status', 20)->default('delivered')->comment('delivered|read|acknowledged|dismissed');
            $table->timestamp('delivered_at')->useCurrent();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_note')->nullable();

            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('realtime_events')->cascadeOnDelete();

            $table->unique(['event_id', 'user_id']);
            $table->index(['tenant_id', 'user_id', 'status']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });

        // RLS
        DB::statement('ALTER TABLE public.realtime_events ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.realtime_events FORCE ROW LEVEL SECURITY');
        DB::statement('
            CREATE POLICY p_rls_realtime_events ON public.realtime_events
            USING (swasthya_rls_is_platform() = true OR tenant_id::text = current_setting(\'app.current_tenant\', true))
            WITH CHECK (swasthya_rls_is_platform() = true OR tenant_id::text = current_setting(\'app.current_tenant\', true))
        ');

        DB::statement('ALTER TABLE public.realtime_event_receipts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.realtime_event_receipts FORCE ROW LEVEL SECURITY');
        DB::statement('
            CREATE POLICY p_rls_realtime_event_receipts ON public.realtime_event_receipts
            USING (swasthya_rls_is_platform() = true OR tenant_id::text = current_setting(\'app.current_tenant\', true))
            WITH CHECK (swasthya_rls_is_platform() = true OR tenant_id::text = current_setting(\'app.current_tenant\', true))
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_event_receipts');
        Schema::dropIfExists('realtime_events');
    }
};
