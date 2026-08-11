<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices (DATABASE.md §3.33): the bill presented to the patient, built
 * from posted charges. Lines live in invoice_lines as frozen snapshots.
 *
 * Tenant-scoped with tenant-safe composite FKs. `invoice_number` is unique
 * per tenant. Status lifecycle: draft → issued → partially_paid → paid
 * (voided with reason). lock_version guards concurrent payment allocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->string('invoice_number', 50);
            $table->text('status')->default('draft'); // draft, issued, partially_paid, paid, voided
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('total_tax_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->timestampTz('issued_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table invoices add constraint chk_invoices_status check (status in ('draft', 'issued', 'partially_paid', 'paid', 'voided'))"
        );
        DB::statement('alter table invoices add constraint chk_invoices_totals check (total_minor >= 0 and total_tax_minor >= 0 and paid_minor >= 0)');

        DB::statement('create unique index uq_invoices_tenant_number on invoices (tenant_id, invoice_number)');
        // Composite-FK support: invoice_lines and payment_allocations
        // reference invoices via (tenant_id, id).
        DB::statement('create unique index uq_invoices_tenant_id on invoices (tenant_id, id)');
        DB::statement('create index idx_invoices_tenant_patient on invoices (tenant_id, patient_id, created_at)');
        DB::statement('create index idx_invoices_tenant_status on invoices (tenant_id, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
