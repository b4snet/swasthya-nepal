<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments (DATABASE.md §3.34): money received. Idempotency is enforced by
 * a unique key — retrying the same payment request never double-charges.
 * Allocation across invoices lives in payment_allocations.
 *
 * Tenant-scoped with tenant-safe composite FKs. Amounts are integer minor
 * units. Never soft-deleted: financial rows are immutable once captured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id')->nullable();
            $table->text('method'); // cash, card, wallet, bank, insurance
            $table->string('provider_ref', 100)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('NPR');
            $table->text('status')->default('captured'); // authorized, captured, failed, refunded
            $table->string('idempotency_key', 100);
            $table->uuid('received_by')->nullable();
            $table->timestampTz('received_at');
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
        });

        DB::statement(
            "alter table payments add constraint chk_payments_method check (method in ('cash', 'card', 'wallet', 'bank', 'insurance'))"
        );
        DB::statement(
            "alter table payments add constraint chk_payments_status check (status in ('authorized', 'captured', 'failed', 'refunded'))"
        );
        DB::statement('alter table payments add constraint chk_payments_amount check (amount_minor > 0)');
        DB::statement('alter table payments add constraint chk_payments_currency check (char_length(currency) = 3)');

        DB::statement('create unique index uq_payments_tenant_idempotency on payments (tenant_id, idempotency_key)');
        // Composite-FK support: payment_allocations reference payments via
        // (tenant_id, id).
        DB::statement('create unique index uq_payments_tenant_id on payments (tenant_id, id)');
        DB::statement('create index idx_payments_tenant_received on payments (tenant_id, received_at)');
        DB::statement('create index idx_payments_tenant_facility on payments (tenant_id, facility_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
