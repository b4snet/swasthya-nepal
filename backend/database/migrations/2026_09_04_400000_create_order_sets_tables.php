<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order Sets — versioned bundles of predefined clinical orders for
 * common workflows (CPOE加速). Supports governance (draft → review →
 * approved → published → retired), provenance tracking, and individual
 * item overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('specialty', 100)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->uuid('owner_staff_id')->nullable();
            $table->uuid('reviewer_staff_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'specialty']);
        });

        DB::statement('ALTER TABLE order_sets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE order_sets FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY sel_order_sets ON order_sets
                FOR SELECT USING (
                    tenant_id = public.swasthya_rls_tenant_id()
                    AND (facility_id = public.swasthya_rls_facility_id() OR public.swasthya_rls_facility_id() IS NULL)
                )
        ');
        DB::statement('
            CREATE POLICY ins_order_sets ON order_sets
                FOR INSERT WITH CHECK (tenant_id = public.swasthya_rls_tenant_id())
        ');
        DB::statement('
            CREATE POLICY upd_order_sets ON order_sets
                FOR UPDATE USING (
                    tenant_id = public.swasthya_rls_tenant_id()
                )
        ');

        // Order set items
        Schema::create('order_set_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('order_set_id');
            $table->string('order_type', 30);
            $table->string('service_code', 100)->nullable();
            $table->string('service_name', 255);
            $table->unsignedInteger('default_quantity')->nullable();
            $table->string('default_frequency', 50)->nullable();
            $table->string('default_duration', 50)->nullable();
            $table->text('default_instructions')->nullable();
            $table->string('default_priority', 20)->default('routine');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_optional')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['order_set_id']);
            $table->index(['tenant_id', 'order_type']);
        });

        DB::statement('ALTER TABLE order_set_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE order_set_items FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY sel_order_set_items ON order_set_items
                FOR SELECT USING (
                    tenant_id = public.swasthya_rls_tenant_id()
                )
        ');
        DB::statement('
            CREATE POLICY ins_order_set_items ON order_set_items
                FOR INSERT WITH CHECK (tenant_id = public.swasthya_rls_tenant_id())
        ');

        // Order set applications (provenance)
        Schema::create('order_set_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable();
            $table->uuid('order_set_id');
            $table->unsignedInteger('order_set_version');
            $table->uuid('encounter_id');
            $table->uuid('applied_by_staff_id');
            $table->timestampTz('applied_at');
            $table->unsignedInteger('item_count')->default(0);
            $table->jsonb('modified_items')->nullable();
            $table->jsonb('created_orders')->nullable();
            $table->timestampsTz();

            $table->index(['encounter_id']);
            $table->index(['tenant_id', 'order_set_id']);
        });

        DB::statement('ALTER TABLE order_set_applications ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE order_set_applications FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY sel_order_set_applications ON order_set_applications
                FOR SELECT USING (
                    tenant_id = public.swasthya_rls_tenant_id()
                )
        ');
        DB::statement('
            CREATE POLICY ins_order_set_applications ON order_set_applications
                FOR INSERT WITH CHECK (tenant_id = public.swasthya_rls_tenant_id())
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_set_applications');
        Schema::dropIfExists('order_set_items');
        Schema::dropIfExists('order_sets');
    }
};
