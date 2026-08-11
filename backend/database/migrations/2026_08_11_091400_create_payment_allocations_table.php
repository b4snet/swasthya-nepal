<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment allocations (DATABASE.md §3.34): how a payment is split across
 * invoices. One allocation per (payment, invoice). Allocations are
 * immutable once written — the invoice's paid_minor derives from them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('payment_id');
            $table->uuid('invoice_id');
            $table->bigInteger('amount_minor');
            $table->timestampTz('allocated_at');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'payment_id'])
                ->references(['tenant_id', 'id'])
                ->on('payments')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'invoice_id'])
                ->references(['tenant_id', 'id'])
                ->on('invoices')
                ->restrictOnDelete();
        });

        DB::statement('alter table payment_allocations add constraint chk_payment_allocations_amount check (amount_minor > 0)');

        DB::statement(
            'create unique index uq_payment_allocations_tenant_payment_invoice on payment_allocations (tenant_id, payment_id, invoice_id)'
        );
        DB::statement('create index idx_payment_allocations_tenant_invoice on payment_allocations (tenant_id, invoice_id)');
        DB::statement('create index idx_payment_allocations_tenant_payment on payment_allocations (tenant_id, payment_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
