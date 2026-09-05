<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Queue management enterprise hardening:
 *  1. queue_entry_history — append-only audit trail for queue state transitions.
 *     Every call, transfer, skip, recall, completion, and no-show is recorded.
 *  2. queue_entries.queue_entries_tenant_dept_status_idx — optimized index
 *     for the call-next query pattern (tenant, department, status, priority).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_entry_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->uuid('queue_entry_id');
            $table->string('action', 50); // called, started, completed, cancelled, no_show, skipped, recalled, transferred
            $table->string('from_status', 50);
            $table->string('to_status', 50);
            $table->string('department', 100)->nullable(); // destination department for transfers
            $table->uuid('actor_id')->nullable(); // user who performed the action
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('queue_entry_id');
            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement('ALTER TABLE queue_entry_history ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE queue_entry_history FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY sel_queue_entry_history ON queue_entry_history
                FOR SELECT USING (
                    tenant_id = public.swasthya_rls_tenant_id()
                    AND (facility_id = public.swasthya_rls_facility_id() OR public.swasthya_rls_facility_id() IS NULL)
                )
        ');
        DB::statement('
            CREATE POLICY ins_queue_entry_history ON queue_entry_history
                FOR INSERT WITH CHECK (true)
        ');

        // Optimized index for call-next query pattern
        DB::statement("
            CREATE INDEX idx_queue_entries_call_next
            ON queue_entries (tenant_id, department, status, priority, token_number)
            WHERE status = 'waiting'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_queue_entries_call_next');
        DB::statement('DROP POLICY IF EXISTS ins_queue_entry_history ON queue_entry_history');
        DB::statement('DROP POLICY IF EXISTS sel_queue_entry_history ON queue_entry_history');
        DB::statement('ALTER TABLE queue_entry_history DISABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('queue_entry_history');
    }
};
