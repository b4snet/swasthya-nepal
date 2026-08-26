<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── receipts ───────────────────────────────────────────────
        Schema::create('receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('payment_id');
            $table->uuid('invoice_id');
            $table->uuid('patient_id');
            $table->string('receipt_number', 100);
            $table->string('status', 20)->default('issued');
            $table->integer('amount_minor');
            $table->string('currency', 3)->default('NPR');
            $table->string('method', 30);
            $table->string('payment_method_label', 100)->nullable();
            $table->json('items')->nullable();
            $table->json('branding_snapshot')->nullable();
            $table->boolean('printed')->default(false);
            $table->timestamp('printed_at')->nullable();
            $table->boolean('emailed')->default(false);
            $table->timestamp('emailed_at')->nullable();
            $table->string('issued_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();

            $table->unique(['tenant_id', 'receipt_number']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['payment_id']);
        });

        DB::statement('ALTER TABLE public.receipts ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.receipts FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY p_rls_receipts ON public.receipts
            USING (swasthya_rls_is_platform() = true OR tenant_id = swasthya_rls_tenant_id())
            WITH CHECK (swasthya_rls_is_platform() = true OR tenant_id = swasthya_rls_tenant_id())
        ');

        // ── billing_adjustments ─────────────────────────────────────
        Schema::create('billing_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('invoice_id');
            $table->uuid('patient_id');
            $table->string('adjustment_number', 100);
            $table->string('type', 20)->comment('credit|debit');
            $table->integer('amount_minor');
            $table->string('currency', 3)->default('NPR');
            $table->string('reason_code', 50);
            $table->text('reason_note')->nullable();
            $table->string('status', 20)->default('pending');
            $table->uuid('adjustment_of_charge_id')->nullable();
            $table->string('requested_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();

            $table->unique(['tenant_id', 'adjustment_number']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'patient_id']);
        });

        DB::statement('ALTER TABLE public.billing_adjustments ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.billing_adjustments FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY p_rls_billing_adjustments ON public.billing_adjustments
            USING (swasthya_rls_is_platform() = true OR tenant_id = swasthya_rls_tenant_id())
            WITH CHECK (swasthya_rls_is_platform() = true OR tenant_id = swasthya_rls_tenant_id())
        ');

        // ── procedure_billing_items ─────────────────────────────────
        Schema::create('procedure_billing_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('procedure_id');
            $table->uuid('patient_id');
            $table->uuid('encounter_id')->nullable();
            $table->string('item_code', 50);
            $table->string('description', 255);
            $table->integer('amount_minor');
            $table->string('currency', 3)->default('NPR');
            $table->integer('quantity')->default(1);
            $table->integer('tax_rate_bps')->default(0);
            $table->string('status', 20)->default('pending');
            $table->uuid('charge_id')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('procedure_id')->references('id')->on('procedures')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();

            $table->index(['tenant_id', 'procedure_id']);
            $table->index(['tenant_id', 'patient_id']);
        });

        DB::statement('ALTER TABLE public.procedure_billing_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.procedure_billing_items FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY p_rls_procedure_billing_items ON public.procedure_billing_items
            USING (swasthya_rls_is_platform() = true OR tenant_id = swasthya_rls_tenant_id())
            WITH CHECK (swasthya_rls_is_platform() = true OR tenant_id = swasthya_rls_tenant_id())
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_billing_items');
        Schema::dropIfExists('billing_adjustments');
        Schema::dropIfExists('receipts');
    }
};
