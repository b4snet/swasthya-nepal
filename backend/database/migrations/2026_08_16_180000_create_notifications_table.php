<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 10 — follow-up reminders (PRODUCT_REQUIREMENTS §5.4,
 * DATABASE.md §3.37/§3.17a): the documented `notifications` table, delivered
 * for the in-app channel (email/SMS/push + templates + delivery attempts
 * remain the documented later-phase surface).
 *
 * The slice's reminder workflow: a planned follow-up gets one in-app
 * reminder notification for its patient (type appointment_reminder, channel
 * in_app, sensitive = true — it carries the planned-visit context). The
 * partial unique (tenant_id, follow_up_id) makes a second reminder for the
 * same plan a database-level no-op — retries and concurrent triggers cannot
 * duplicate.
 *
 * Per §3.37 the table is TENANT-scoped (tenant_id NOT NULL, no facility_id):
 * a reminder belongs to the tenant that owns the plan, not to a facility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            // Global identity (users carry no tenant_id — DATABASE.md §1.3),
            // so no FK; consumers resolve the user through the tenant context.
            $table->uuid('user_id')->nullable();
            $table->uuid('patient_id')->nullable();
            // The slice's typed linkage: a follow-up reminder traces to its
            // plan (one per plan, partial unique below).
            $table->uuid('follow_up_id')->nullable();
            $table->text('type'); // appointment_reminder, result, billing, clinical_alert, stock_alert
            $table->text('channel'); // in_app, email, sms, push
            // Template linkage is a later-phase surface (notification_templates).
            $table->uuid('template_id')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->text('status')->default('sent'); // queued, sent, delivered, failed
            // PHI content flag: in-app dispatch is synchronous, so in_app
            // reminders are created 'sent' (no provider round-trip exists).
            $table->boolean('sensitive')->default(false);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->nullOnDelete();

            $table->foreign(['tenant_id', 'follow_up_id'])
                ->references(['tenant_id', 'id'])
                ->on('follow_ups')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table notifications add constraint chk_notifications_type check (type in ('appointment_reminder', 'result', 'billing', 'clinical_alert', 'stock_alert'))"
        );
        DB::statement(
            "alter table notifications add constraint chk_notifications_channel check (channel in ('in_app', 'email', 'sms', 'push'))"
        );
        DB::statement(
            "alter table notifications add constraint chk_notifications_status check (status in ('queued', 'sent', 'delivered', 'failed'))"
        );

        // Composite-FK support + the documented operational indexes (§3.37).
        DB::statement('create unique index uq_notifications_tenant_id on notifications (tenant_id, id)');
        DB::statement('create index idx_notifications_tenant_user on notifications (tenant_id, user_id, status)');
        DB::statement('create index idx_notifications_tenant_created on notifications (tenant_id, created_at)');

        // Duplicate prevention: one reminder per follow-up plan.
        DB::statement(
            'create unique index uq_notifications_tenant_follow_up on notifications (tenant_id, follow_up_id) where follow_up_id is not null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
