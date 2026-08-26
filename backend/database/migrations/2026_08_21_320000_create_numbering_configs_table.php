<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant configurable numbering system.
 *
 * Each (tenant, document_type) pair has its own numbering configuration:
 * - prefix: custom prefix (e.g., "SMC" for Swasthya Medical Center)
 * - sequence_length: zero-padded sequence width (e.g., 5 → 00001)
 * - date_format: PHP date format for the date component (e.g., "Ymd", "ym", null for no date)
 * - reset_policy: "daily" (reset each day), "monthly" (reset each month), "yearly" (reset each year), "never" (continuous)
 * - include_facility: whether to include facility code in the number
 * - separator: character between components (default "-")
 * - example: a preview of what the next number will look like
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_configs', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('document_type', 50);
            $table->string('prefix', 20)->default('DOC');
            $table->integer('sequence_length')->default(5);
            $table->string('date_format', 20)->nullable()->comment('PHP date format, null = no date');
            $table->string('reset_policy', 20)->default('never'); // daily, monthly, yearly, never
            $table->boolean('include_facility')->default(false);
            $table->string('separator', 5)->default('-');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'document_type']);
            $table->index(['tenant_id', 'is_active']);
        });

        // RLS
        DB::statement('ALTER TABLE numbering_configs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE numbering_configs FORCE ROW LEVEL SECURITY');

        $tenantUsing = 'tenant_id = swasthya_rls_tenant_id()';

        DB::statement('DROP POLICY IF EXISTS p_rls_numbering_configs_select ON numbering_configs');
        DB::statement("CREATE POLICY p_rls_numbering_configs_select ON numbering_configs FOR SELECT USING ({$tenantUsing})");
        DB::statement('DROP POLICY IF EXISTS p_rls_numbering_configs_insert ON numbering_configs');
        DB::statement('CREATE POLICY p_rls_numbering_configs_insert ON numbering_configs FOR INSERT WITH CHECK (true)');
        DB::statement('DROP POLICY IF EXISTS p_rls_numbering_configs_update ON numbering_configs');
        DB::statement("CREATE POLICY p_rls_numbering_configs_update ON numbering_configs FOR UPDATE USING ({$tenantUsing}) WITH CHECK ({$tenantUsing})");
        DB::statement('DROP POLICY IF EXISTS p_rls_numbering_configs_delete ON numbering_configs');
        DB::statement("CREATE POLICY p_rls_numbering_configs_delete ON numbering_configs FOR DELETE USING ({$tenantUsing})");
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_configs');
    }
};
