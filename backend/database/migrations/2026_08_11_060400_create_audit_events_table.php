<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only, tamper-evident audit trail (DATABASE.md §3.36,
 * MASTER_RULES.md §19).
 *
 *  - Standalone by design: resources are referenced by id + type, never
 *    FK-coupled, so history survives resource purges (TENANCY.md §14).
 *  - Hash chain: each row's event_hash covers the prior row's event_hash
 *    plus this row's canonical payload; the chain is verified by tests
 *    (a tamper attempt breaks it) and by the AuditLogger's write path
 *    (serialized per-transaction so the chain stays linear).
 *  - Append-only: there is no update/delete path in the application;
 *    purge happens only via the scheduled, audited retention job.
 *  - tenant_id is NULL for platform events.
 *
 * NOTE (deviation, recorded): RANGE partitioning on occurred_at (monthly) is
 * the documented design for national scale and is deferred to the
 * partitioning maintenance phase — the table is unpartitioned today and the
 * partition-maintenance job will convert it behind the same schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->text('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->text('actor_email')->nullable();
            $table->text('action');
            $table->text('resource_type');
            $table->uuid('resource_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->jsonb('payload')->default('{}');
            $table->ipAddress('ip_address')->nullable();
            $table->uuid('correlation_id');
            $table->text('prev_hash')->nullable();
            $table->text('event_hash');
        });

        DB::statement(
            "alter table audit_events add constraint chk_audit_events_actor_type check (actor_type in ('user', 'system', 'integration'))"
        );

        DB::statement('create index idx_audit_events_tenant_occurred on audit_events (tenant_id, occurred_at)');
        DB::statement('create index idx_audit_events_tenant_actor on audit_events (tenant_id, actor_id, occurred_at)');
        DB::statement('create index idx_audit_events_tenant_resource on audit_events (tenant_id, resource_type, resource_id)');
        DB::statement('create index idx_audit_events_occurred_brin on audit_events using brin (occurred_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
