<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — pharmacy return → billing notification (PRODUCT_REQUIREMENTS
 * §5.4, §6.7; DATABASE.md §3.30/§3.33/§3.37): the documented "automatic
 * notification to billing on return" integration.
 *
 * A successful pharmacy return opens a refund REQUEST against the linked
 * posted charge. In the SAME atomic transaction the return now also creates
 * ONE in-app billing notification (type 'billing' — already in the type
 * CHECK from slice 10) typed to that refund request, so billing sees the
 * refund path immediately and acts on it (approval stays the billing
 * approver's separate, segregation-of-duties-gated action — no money moves
 * at the return).
 *
 * The typed linkage mirrors the follow-up reminder exactly
 * (notifications.follow_up_id): a nullable refund_request_id column with a
 * composite (tenant_id, refund_request_id) FK and a partial unique index —
 * one billing notification per refund request is a database-level
 * guarantee, so retries and concurrent triggers cannot duplicate. No
 * notification_templates / delivery_attempts are introduced; in-app
 * dispatch stays synchronous (created 'sent', no provider round-trip
 * exists — INTEROPERABILITY.md §13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->uuid('refund_request_id')->nullable();

            $table->foreign(['tenant_id', 'refund_request_id'])
                ->references(['tenant_id', 'id'])
                ->on('refund_requests')
                ->restrictOnDelete();
        });

        // Duplicate prevention: one billing notification per refund request
        // (the same shape as uq_notifications_tenant_follow_up).
        DB::statement(
            'create unique index uq_notifications_tenant_refund_request on notifications (tenant_id, refund_request_id) where refund_request_id is not null'
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists uq_notifications_tenant_refund_request');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'refund_request_id']);
            $table->dropColumn('refund_request_id');
        });
    }
};
