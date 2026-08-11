<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Charges (DATABASE.md §3.33): the financial record spine — what was
 * charged, from which source (encounter, prescription, manual).
 *
 * Tenant-scoped with tenant-safe composite FKs. Amounts are integer minor
 * units, never floats (DATABASE.md §0.4). Posted charges are immutable:
 * corrections are reversing entries, never UPDATEs — void is a status with
 * reason and approver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->text('source_type'); // encounter, prescription, manual
            $table->uuid('encounter_id')->nullable();
            $table->uuid('prescription_id')->nullable();
            $table->text('description');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('NPR');
            $table->integer('tax_rate_bps')->default(0);
            $table->text('status')->default('posted'); // posted, voided
            $table->uuid('voided_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampTz('charged_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'prescription_id'])
                ->references(['tenant_id', 'id'])
                ->on('prescriptions')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table charges add constraint chk_charges_source check (source_type in ('encounter', 'prescription', 'manual'))"
        );
        DB::statement(
            "alter table charges add constraint chk_charges_status check (status in ('posted', 'voided'))"
        );
        DB::statement('alter table charges add constraint chk_charges_amount check (amount_minor >= 0)');
        DB::statement('alter table charges add constraint chk_charges_currency check (char_length(currency) = 3)');
        DB::statement('alter table charges add constraint chk_charges_tax check (tax_rate_bps >= 0)');

        // Composite-FK support: invoice_lines reference charges via
        // (tenant_id, id).
        DB::statement('create unique index uq_charges_tenant_id on charges (tenant_id, id)');
        DB::statement('create index idx_charges_tenant_patient on charges (tenant_id, patient_id, charged_at)');
        DB::statement('create index idx_charges_tenant_facility on charges (tenant_id, facility_id)');
        DB::statement('create index idx_charges_tenant_encounter on charges (tenant_id, encounter_id)');
        DB::statement('create index idx_charges_tenant_status on charges (tenant_id, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('charges');
    }
};
