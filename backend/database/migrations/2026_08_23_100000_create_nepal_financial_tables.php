<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nepal Financial Architecture (PHASE NEXT)
 *
 * Creates:
 * 1. tax_rules — effective-dated tax/VAT configuration per jurisdiction/service
 * 2. benefit_rules — versioned benefit rules for SSF/HIB/payers
 * 3. Adds calendar_type and nepal_fiscal_year to financial_periods
 * 4. Adds Nepal-specific fields to payers table
 *
 * CRITICAL: All statutory values are CONFIGURABLE, not hard-coded.
 * Historical records use the rules that were active at posting time.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Tax Rules ──────────────────────────────────────────────
        // Effective-dated tax configuration. Every charge references the
        // tax rule active at posting time. Historical invoices remain
        // reproducible using the rule version that applied.
        Schema::create('tax_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id')->nullable(); // null = org-wide default

            // Rule identity
            $table->string('code', 50);               // e.g. 'VAT_STANDARD', 'HEALTH_SERVICE_TAX'
            $table->string('name', 100);               // human-readable
            $table->string('tax_type', 50);            // 'vat', 'health_service_tax', 'health_equity_fee', 'excise', 'other'
            $table->string('description')->nullable();

            // Rate configuration
            $table->string('rate_method', 20);         // 'percentage', 'fixed_amount', 'per_unit'
            $table->integer('rate_value_bps');          // basis points (1300 = 13.00%, 500 = 5.00%)
            $table->string('currency', 3)->default('NPR');
            $table->integer('fixed_amount_minor')->nullable(); // for fixed_amount method

            // Scope
            $table->string('jurisdiction', 50)->default('nepal'); // 'nepal', 'province', 'local'
            $table->string('service_category', 50)->nullable();   // null = all services
            $table->boolean('applies_toOPD')->default(true);
            $table->boolean('applies_toIPD')->default(true);
            $table->boolean('applies_to_pharmacy')->default(true);
            $table->boolean('applies_to_lab')->default(true);
            $table->boolean('applies_to_radiology')->default(true);

            // Effective dates (CRITICAL: historical records use the rule active at posting time)
            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // null = currently active

            // Source tracking (authoritative evidence)
            $table->string('source_authority', 100)->nullable(); // e.g. 'Inland Revenue Department'
            $table->string('source_document', 200)->nullable();  // e.g. 'Finance Act 2082/83'
            $table->date('source_effective_date')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('source_version', 50)->nullable();

            // Status
            $table->string('status', 20)->default('active'); // 'active', 'inactive', 'superseded'
            $table->boolean('is_default')->default(false);

            // Audit
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            // Constraints
            $table->unique(['tenant_id', 'code', 'effective_from']);
            $table->index('tenant_id');
            $table->index('tenant_id', 'tax_rules_tenant_effective_idx');
            $table->foreign('tenant_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        // ── Benefit Rules ──────────────────────────────────────────
        // Versioned benefit rules for payers (SSF, HIB, private insurance).
        // Each rule defines coverage for a service category under a payer scheme.
        // Historical claims use the rule active at claim time.
        Schema::create('benefit_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('payer_id'); // references payers table

            // Rule identity
            $table->string('code', 50);               // e.g. 'SSF_OPD_MEDICINE', 'HIB_IPD_SURGERY'
            $table->string('name', 100);               // human-readable
            $table->string('scheme_version', 50);      // e.g. 'SSF_2082', 'HIB_BP_V3'

            // Coverage rules
            $table->string('service_category', 50);    // 'opd', 'ipd', 'medicine', 'diagnostic', 'surgery', 'maternity', 'emergency'
            $table->string('coverage_type', 20);        // 'full', 'co_pay', 'deductible', 'capped', 'excluded'
            $table->integer('coverage_percent_bps')->nullable(); // basis points (10000 = 100%)
            $table->integer('limit_minor')->nullable();          // annual/per-claim limit in minor units
            $table->integer('copay_minor')->nullable();          // fixed co-payment
            $table->integer('copay_percent_bps')->nullable();    // percentage co-payment
            $table->integer('deductible_minor')->nullable();     // deductible before coverage kicks in

            // Eligibility
            $table->boolean('eligible_opd')->default(true);
            $table->boolean('eligible_ipd')->default(true);
            $table->boolean('eligible_maternity')->default(false);
            $table->boolean('eligible_dependents')->default(false);
            $table->integer('max_dependents')->nullable();

            // Effective dates
            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Source tracking
            $table->string('source_authority', 100)->nullable();
            $table->string('source_document', 200)->nullable();
            $table->date('source_effective_date')->nullable();
            $table->string('source_url', 500)->nullable();

            // Status
            $table->string('status', 20)->default('active');

            // Audit
            $table->timestamps();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            // Constraints
            $table->unique(['tenant_id', 'payer_id', 'code', 'effective_from']);
            $table->index('tenant_id');
            $table->index('payer_id');
            $table->foreign('tenant_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('payer_id')->references('id')->on('payers')->cascadeOnDelete();
        });

        // ── Extend Financial Periods ───────────────────────────────
        // Add Nepal fiscal year support to existing financial_periods table.
        Schema::table('financial_periods', function (Blueprint $table): void {
            $table->string('calendar_type', 20)->default('gregorian'); // 'gregorian', 'nepal_fiscal'
            $table->string('nepal_fiscal_year', 20)->nullable();       // e.g. '2082/83'
            $table->date('nepal_start_date')->nullable();              // BS start date
            $table->date('nepal_end_date')->nullable();                // BS end date
            $table->string('period_status', 20)->default('open');      // 'open', 'closing', 'closed', 'locked'
            $table->text('close_notes')->nullable();
            $table->uuid('locked_by_staff_id')->nullable();
            $table->timestamp('locked_at')->nullable();
        });

        // ── Extend Payers ──────────────────────────────────────────
        // Add Nepal-specific payer configuration.
        Schema::table('payers', function (Blueprint $table): void {
            $table->string('payer_sub_type', 50)->nullable();  // 'ssf', 'hib', 'private', 'corporate', 'government'
            $table->string('scheme_version', 50)->nullable();   // current scheme version
            $table->string('registration_number', 100)->nullable(); // SSF/HIB registration
            $table->string('contact_person', 100)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 100)->nullable();
            $table->text('notes')->nullable();
            $table->json('config')->nullable(); // payer-specific configuration (benefit limits, etc.)
        });

        // ── Extend Charges ─────────────────────────────────────────
        // Link charges to the tax rule that was active at posting time.
        Schema::table('charges', function (Blueprint $table): void {
            $table->uuid('tax_rule_id')->nullable()->after('tax_rate_bps');
            $table->foreign('tax_rule_id')->references('id')->on('tax_rules')->nullOnDelete();
        });

        // ── Extend Claims ──────────────────────────────────────────
        // Add benefit rule reference and Nepal-specific claim fields.
        Schema::table('claims', function (Blueprint $table): void {
            $table->uuid('benefit_rule_id')->nullable()->after('policy_id');
            $table->string('claim_type', 20)->default('standard'); // 'standard', 'ssf', 'hib', 'emergency'
            $table->string('external_claim_number', 100)->nullable(); // SSF/HIB claim reference
            $table->text('rejection_reason')->nullable();
            $table->integer('patient_responsibility_minor')->default(0); // copay + deductible
            $table->foreign('benefit_rule_id')->references('id')->on('benefit_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table): void {
            $table->dropForeign(['benefit_rule_id']);
            $table->dropColumn(['benefit_rule_id', 'claim_type', 'external_claim_number', 'rejection_reason', 'patient_responsibility_minor']);
        });

        Schema::table('charges', function (Blueprint $table): void {
            $table->dropForeign(['tax_rule_id']);
            $table->dropColumn('tax_rule_id');
        });

        Schema::table('payers', function (Blueprint $table): void {
            $table->dropColumn(['payer_sub_type', 'scheme_version', 'registration_number', 'contact_person', 'contact_phone', 'contact_email', 'notes', 'config']);
        });

        Schema::table('financial_periods', function (Blueprint $table): void {
            $table->dropColumn(['calendar_type', 'nepal_fiscal_year', 'nepal_start_date', 'nepal_end_date', 'period_status', 'close_notes', 'locked_by_staff_id', 'locked_at']);
        });

        Schema::dropIfExists('benefit_rules');
        Schema::dropIfExists('tax_rules');
    }
};
