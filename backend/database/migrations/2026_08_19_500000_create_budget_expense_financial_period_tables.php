<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 — Enterprise Procurement, Inventory & Finance.
 *
 * Tables:
 *  - budgets: department/facility-level budget allocations per fiscal year
 *  - budget_lines: individual line items within a budget
 *  - expenses: tracked expenses linked to budgets
 *  - expense_categories: configurable expense categories
 *  - financial_periods: fiscal period open/close controls
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── expense_categories ──
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->text('code')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('status')->default('active');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
        });

        // ── budgets ──
        Schema::create('budgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('department_id')->nullable();
            $table->text('budget_code')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('budget_type')->default('operational'); // operational, capital, project
            $table->integer('fiscal_year');
            $table->text('status')->default('draft'); // draft, active, closed, archived
            $table->bigInteger('total_allocation_minor')->default(0);
            $table->bigInteger('spent_minor')->default(0);
            $table->bigInteger('committed_minor')->default(0);
            $table->uuid('created_by_staff_id')->nullable();
            $table->uuid('approved_by_staff_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });

        DB::statement('CREATE INDEX idx_budgets_fiscal ON budgets (tenant_id, fiscal_year, status)');
        DB::statement('CREATE INDEX idx_budgets_facility ON budgets (facility_id, status)');

        // ── budget_lines ──
        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('budget_id');
            $table->uuid('expense_category_id')->nullable();
            $table->text('description');
            $table->bigInteger('allocation_minor')->default(0);
            $table->bigInteger('spent_minor')->default(0);
            $table->bigInteger('committed_minor')->default(0);
            $table->text('status')->default('active');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('budget_id')->references('id')->on('budgets')->restrictOnDelete();
            $table->foreign('expense_category_id')->references('id')->on('expense_categories')->nullOnDelete();
        });

        DB::statement('CREATE INDEX idx_budget_lines_budget ON budget_lines (budget_id)');

        // ── expenses ──
        Schema::create('expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('budget_id')->nullable();
            $table->uuid('budget_line_id')->nullable();
            $table->uuid('expense_category_id');
            $table->text('reference_number');
            $table->text('description');
            $table->bigInteger('amount_minor');
            $table->text('currency')->default('NPR');
            $table->text('status')->default('draft'); // draft, pending_approval, approved, rejected, paid, void
            $table->text('expense_type')->default('operational'); // operational, capital, project
            $table->uuid('vendor_id')->nullable();
            $table->text('invoice_number')->nullable();
            $table->timestampTz('expense_date');
            $table->timestampTz('payment_date')->nullable();
            $table->text('payment_method')->nullable();
            $table->text('payment_reference')->nullable();
            $table->uuid('requested_by_staff_id');
            $table->uuid('approved_by_staff_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('attachments')->default('[]');
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
            $table->foreign('budget_id')->references('id')->on('budgets')->nullOnDelete();
            $table->foreign('budget_line_id')->references('id')->on('budget_lines')->nullOnDelete();
            $table->foreign('expense_category_id')->references('id')->on('expense_categories')->restrictOnDelete();
            $table->foreign('vendor_id')->references('id')->on('vendors')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX uq_expenses_ref ON expenses (tenant_id, reference_number)');
        DB::statement('CREATE INDEX idx_expenses_facility ON expenses (facility_id, status, expense_date)');
        DB::statement('CREATE INDEX idx_expenses_budget ON expenses (budget_id) WHERE budget_id IS NOT NULL');

        // ── financial_periods ──
        Schema::create('financial_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->text('name');
            $table->integer('fiscal_year');
            $table->integer('period_number'); // 1-12 for monthly, or custom
            $table->text('period_type')->default('monthly'); // monthly, quarterly, annual
            $table->date('start_date');
            $table->date('end_date');
            $table->text('status')->default('open'); // open, closed, locked
            $table->bigInteger('total_budget_minor')->default(0);
            $table->bigInteger('total_expenses_minor')->default(0);
            $table->bigInteger('total_revenue_minor')->default(0);
            $table->uuid('closed_by_staff_id')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX uq_financial_periods ON financial_periods (tenant_id, facility_id, fiscal_year, period_number)');
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_periods');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('expense_categories');
    }
};
