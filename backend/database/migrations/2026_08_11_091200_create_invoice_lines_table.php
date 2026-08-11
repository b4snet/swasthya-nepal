<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice lines (DATABASE.md §3.33): frozen snapshots of each charge on the
 * invoice. One charge appears on at most one invoice (partial unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('invoice_id');
            $table->uuid('charge_id');
            $table->text('description');
            $table->bigInteger('amount_minor');
            $table->bigInteger('tax_minor');
            $table->integer('line_no');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'invoice_id'])
                ->references(['tenant_id', 'id'])
                ->on('invoices')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'charge_id'])
                ->references(['tenant_id', 'id'])
                ->on('charges')
                ->restrictOnDelete();
        });

        DB::statement('alter table invoice_lines add constraint chk_invoice_lines_amount check (amount_minor >= 0 and tax_minor >= 0)');

        // A posted charge is invoiced at most once.
        DB::statement(
            'create unique index uq_invoice_lines_tenant_charge on invoice_lines (tenant_id, charge_id)'
        );
        DB::statement('create unique index uq_invoice_lines_tenant_invoice_line on invoice_lines (tenant_id, invoice_id, line_no)');
        DB::statement('create index idx_invoice_lines_tenant_invoice on invoice_lines (tenant_id, invoice_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
