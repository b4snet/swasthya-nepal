<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the existing notifications table with Phase 12 lifecycle columns.
 *
 * Adds: priority, severity, campaign_id, delivery_window, retry_count,
 * max_retries, next_retry_at, acknowledged_at, acknowledgement_required,
 * escalation_level, expires_at, metadata.
 *
 * Preserves full backward compatibility with the existing Phase 3 slice 10
 * follow-up reminder surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            // Lifecycle
            $table->text('priority')->default('normal')->after('status');
            $table->text('severity')->default('info')->after('priority');

            // Campaign linkage
            $table->uuid('campaign_id')->nullable()->after('severity');
            $table->foreign('campaign_id')
                ->references('id')
                ->on('broadcast_campaigns')
                ->nullOnDelete();

            // Delivery control
            $table->text('delivery_window')->nullable(); // e.g. '09:00-17:00'
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampTz('expires_at')->nullable();

            // Acknowledgement
            $table->boolean('acknowledgement_required')->default(false);
            $table->timestampTz('acknowledged_at')->nullable();
            $table->integer('escalation_level')->default(0);

            // Audit
            $table->jsonb('metadata')->default('{}');
        });

        // Update the CHECK constraint to include new statuses
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS chk_notifications_status');
        DB::statement(
            "ALTER TABLE notifications ADD CONSTRAINT chk_notifications_status CHECK (status IN ('queued', 'sending', 'sent', 'delivered', 'failed', 'cancelled', 'expired', 'bounced'))"
        );

        // Update the CHECK constraint to include new types
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS chk_notifications_type');
        DB::statement(
            "ALTER TABLE notifications ADD CONSTRAINT chk_notifications_type CHECK (type IN ('appointment_reminder', 'result', 'billing', 'clinical_alert', 'stock_alert', 'emergency', 'broadcast'))"
        );

        // Indexes for new columns
        DB::statement('CREATE INDEX idx_notifications_priority ON notifications (tenant_id, priority, status)');
        DB::statement('CREATE INDEX idx_notifications_retry ON notifications (next_retry_at) WHERE next_retry_at IS NOT NULL');
        DB::statement('CREATE INDEX idx_notifications_ack_required ON notifications (acknowledgement_required, acknowledged_at) WHERE acknowledgement_required = true');
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropForeign(['campaign_id']);
            $table->dropColumn([
                'priority', 'severity', 'campaign_id',
                'delivery_window', 'retry_count', 'max_retries', 'next_retry_at', 'expires_at',
                'acknowledgement_required', 'acknowledged_at', 'escalation_level',
                'metadata',
            ]);
        });

        // Restore original constraints
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS chk_notifications_status');
        DB::statement(
            "ALTER TABLE notifications ADD CONSTRAINT chk_notifications_status CHECK (status IN ('queued', 'sent', 'delivered', 'failed'))"
        );
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS chk_notifications_type');
        DB::statement(
            "ALTER TABLE notifications ADD CONSTRAINT chk_notifications_type CHECK (type IN ('appointment_reminder', 'result', 'billing', 'clinical_alert', 'stock_alert'))"
        );
    }
};
