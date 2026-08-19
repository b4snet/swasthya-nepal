<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * National Mass Notification Platform (Phase 12).
 *
 * Extends the existing notifications table with:
 *   - notification_templates: reusable message templates per channel
 *   - broadcast_campaigns: targeted mass notification campaigns
 *   - audience_segments: reusable audience definitions
 *   - delivery_attempts: per-recipient per-channel delivery tracking
 *   - notification_recipients: campaign-to-recipient linkage
 *
 * The existing notifications table gets new lifecycle columns via a separate
 * alter-table migration (2026_08_19_200100).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── notification_templates ──
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('code')->unique(); // e.g. 'appointment_reminder_v1'
            $table->text('name');
            $table->text('channel'); // in_app, email, sms, push, voice
            $table->text('type'); // appointment_reminder, result, billing, clinical_alert, stock_alert, emergency, broadcast
            $table->text('subject')->nullable(); // email subject / SMS header
            $table->text('body_template'); // body with {{variable}} placeholders
            $table->text('locale')->default('en');
            $table->boolean('active')->default(true);
            $table->jsonb('metadata')->default('{}'); // template variables schema, etc.
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement('create unique index uq_notification_templates_tenant_code on notification_templates (tenant_id, code)');
        DB::statement('create index idx_notification_templates_tenant_channel on notification_templates (tenant_id, channel, active)');

        // ── audience_segments ──
        Schema::create('audience_segments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('code')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('scope_type'); // national, organization, facility, department, role, custom
            $table->jsonb('criteria')->default('{}'); // targeting rules: roles, facilities, departments, clinical criteria
            $table->integer('estimated_recipients')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement('create unique index uq_audience_segments_tenant_code on audience_segments (tenant_id, code)');

        // ── broadcast_campaigns ──
        Schema::create('broadcast_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('code')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('status')->default('draft'); // draft, review, approved, scheduled, sending, sent, partially_delivered, failed, cancelled, expired
            $table->text('priority')->default('normal'); // low, normal, high, urgent, emergency
            $table->text('severity')->default('info'); // info, warning, critical, emergency
            $table->boolean('is_emergency')->default(false);
            $table->uuid('template_id')->nullable();
            $table->uuid('segment_id')->nullable(); // audience segment
            $table->jsonb('message_content')->default('{}'); // rendered/final message content
            $table->jsonb('targeting_criteria')->default('{}'); // inline targeting if no segment
            $table->jsonb('delivery_config')->default('{}'); // channels, retry policy, escalation
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->text('approval_required')->default('facility_admin'); // none, facility_admin, org_admin, national_admin
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->integer('total_recipients')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('acknowledged_count')->default(0);
            $table->boolean('acknowledgement_required')->default(false);
            $table->jsonb('escalation_policy')->default('{}'); // escalation rules
            $table->jsonb('retry_policy')->default('{}'); // max retries, backoff
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign('template_id')
                ->references('id')
                ->on('notification_templates')
                ->nullOnDelete();

            $table->foreign('segment_id')
                ->references('id')
                ->on('audience_segments')
                ->nullOnDelete();
        });

        DB::statement('create unique index uq_broadcast_campaigns_tenant_code on broadcast_campaigns (tenant_id, code)');
        DB::statement('create index idx_broadcast_campaigns_tenant_status on broadcast_campaigns (tenant_id, status)');
        DB::statement('create index idx_broadcast_campaigns_emergency on broadcast_campaigns (is_emergency, status) where is_emergency = true');
        DB::statement('create index idx_broadcast_campaigns_scheduled on broadcast_campaigns (scheduled_at) where scheduled_at is not null');

        // ── delivery_attempts ──
        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');
            $table->uuid('notification_id')->nullable(); // link to individual notification if created
            $table->uuid('recipient_user_id');
            $table->text('channel'); // in_app, email, sms, push, voice
            $table->text('status')->default('pending'); // pending, sending, sent, delivered, failed, bounced
            $table->text('provider')->nullable(); // adapter name used
            $table->text('provider_message_id')->nullable(); // external provider tracking ID
            $table->text('provider_response')->nullable(); // raw response from provider
            $table->text('error_message')->nullable();
            $table->integer('attempt_number')->default(1);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('acknowledgement_data')->nullable(); // e.g. keypress, button click
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign('campaign_id')
                ->references('id')
                ->on('broadcast_campaigns')
                ->restrictOnDelete();

            $table->foreign('notification_id')
                ->references('id')
                ->on('notifications')
                ->nullOnDelete();
        });

        DB::statement('create index idx_delivery_attempts_campaign on delivery_attempts (campaign_id, status)');
        DB::statement('create index idx_delivery_attempts_recipient on delivery_attempts (recipient_user_id, status)');
        DB::statement('create index idx_delivery_attempts_pending on delivery_attempts (status, attempt_number) where status in (\'pending\', \'sending\')');

        // ── notification_recipients ──
        Schema::create('notification_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');
            $table->uuid('user_id');
            $table->text('delivery_status')->default('pending'); // pending, delivered, failed, acknowledged
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();

            $table->foreign('campaign_id')
                ->references('id')
                ->on('broadcast_campaigns')
                ->restrictOnDelete();
        });

        DB::statement('create unique index uq_notification_recipients_campaign_user on notification_recipients (campaign_id, user_id)');
        DB::statement('create index idx_notification_recipients_tenant on notification_recipients (tenant_id, delivery_status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('broadcast_campaigns');
        Schema::dropIfExists('audience_segments');
        Schema::dropIfExists('notification_templates');
    }
};
