<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 15 — Laboratory (ROADMAP Phase 10, PRODUCT_REQUIREMENTS
 * §6.8, DATABASE.md §3.28): specimens with accession + chain of custody,
 * and corrected result versions.
 *
 *  - specimens           one row per physical sample collected for a lab
 *                        order (an order yields one or more specimens).
 *                        Custody chain: collected → accessioned → processing
 *                        → completed | rejected, each step recording WHO and
 *                        WHEN (the medico-legal specimen chain the design
 *                        requires). Every specimen carries a UNIQUE accession
 *                        number per tenant (the label printed at collection).
 *  - lab_result_versions the append-only version history of each ordered
 *                        test's result. Entry writes version 1; a correction
 *                        (reported → correcting) writes version N+1 with its
 *                        reason. The ORIGINAL always remains visible;
 *                        CLINICAL_SAFETY §7: "a corrected result is a new,
 *                        verified, audited version; the original remains
 *                        visible; if the correction touches a critical value,
 *                        escalation re-runs."
 *
 * lab_orders gains the 'correcting' state (reported → correcting →
 * results_entered → verified → reported — a correction re-runs the entry →
 * verification → release discipline with the reason captured at initiation).
 *
 * Both new tables are TENANT_FACILITY tier; RLS on + FORCED by the companion
 * migration (2026_08_16_220100). The item's current result columns remain the
 * live view (backward compatible with the Slice 2 surface); the versions
 * table is the append-only history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specimens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('lab_order_id');
            $table->string('accession_number', 64);
            $table->string('specimen_type', 50); // blood, urine, swab, …
            $table->string('container', 50)->nullable();
            $table->text('status')->default('collected'); // collected, accessioned, processing, completed, rejected
            $table->uuid('collected_by_staff_id')->nullable();
            $table->timestampTz('collected_at')->nullable();
            $table->uuid('accessioned_by_staff_id')->nullable();
            $table->timestampTz('accessioned_at')->nullable();
            $table->uuid('processing_by_staff_id')->nullable();
            $table->timestampTz('processing_at')->nullable();
            $table->uuid('completed_by_staff_id')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('rejected_by_staff_id')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'lab_order_id'])
                ->references(['tenant_id', 'id'])
                ->on('lab_orders')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'collected_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'accessioned_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table specimens add constraint chk_specimens_status check (status in ('collected', 'accessioned', 'processing', 'completed', 'rejected'))"
        );
        DB::statement(
            "alter table specimens add constraint chk_specimens_reject check (status <> 'rejected' or rejection_reason is not null)"
        );
        DB::statement(
            'alter table specimens add constraint chk_specimens_collected check (collected_at is not null and collected_by_staff_id is not null)'
        );

        // The accession label is unique per tenant.
        DB::statement('create unique index uq_specimens_tenant_accession on specimens (tenant_id, accession_number)');
        DB::statement('create index idx_specimens_tenant_order on specimens (tenant_id, lab_order_id, created_at)');

        Schema::create('lab_result_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('lab_order_item_id');
            $table->integer('version_no');
            $table->text('result_value');
            $table->text('result_unit')->nullable();
            $table->text('reference_range')->nullable(); // snapshot at entry
            $table->boolean('is_critical')->default(false);
            $table->text('correction_reason')->nullable(); // only on versions > 1
            $table->uuid('entered_by_staff_id');
            $table->timestampTz('entered_at');
            $table->uuid('verified_by_staff_id')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'lab_order_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('lab_order_items')
                ->restrictOnDelete();
        });

        DB::statement(
            'alter table lab_result_versions add constraint chk_lab_result_versions_reason check (version_no = 1 or correction_reason is not null)'
        );
        DB::statement(
            'alter table lab_result_versions add constraint chk_lab_result_versions_verified check (verified_at is null or (verified_at is not null and verified_by_staff_id is not null))'
        );

        // One version per item per number — the append-only history.
        DB::statement('create unique index uq_lab_result_versions_tenant_item_version on lab_result_versions (tenant_id, lab_order_item_id, version_no)');
        DB::statement('create index idx_lab_result_versions_tenant_item on lab_result_versions (tenant_id, lab_order_item_id)');

        // lab_orders: the correction state (reported → correcting → … → reported)
        // and the captured reason/actor of the correction initiation.
        Schema::table('lab_orders', function (Blueprint $table): void {
            $table->text('correction_reason')->nullable();
            $table->uuid('correcting_by_staff_id')->nullable();
            $table->timestampTz('correcting_at')->nullable();
        });

        DB::statement(
            'alter table lab_orders drop constraint chk_lab_orders_status'
        );
        DB::statement(
            "alter table lab_orders add constraint chk_lab_orders_status check (status in ('ordered', 'collected', 'processing', 'results_entered', 'verified', 'reported', 'correcting'))"
        );
    }

    public function down(): void
    {
        DB::statement(
            'alter table lab_orders drop constraint chk_lab_orders_status'
        );
        DB::statement(
            "alter table lab_orders add constraint chk_lab_orders_status check (status in ('ordered', 'collected', 'processing', 'results_entered', 'verified', 'reported'))"
        );

        Schema::table('lab_orders', function (Blueprint $table): void {
            $table->dropColumn(['correction_reason', 'correcting_by_staff_id', 'correcting_at']);
        });

        Schema::dropIfExists('lab_result_versions');
        Schema::dropIfExists('specimens');
    }
};
