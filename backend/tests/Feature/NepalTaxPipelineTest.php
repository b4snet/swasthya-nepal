<?php

use App\Models\BenefitRule;
use App\Models\Charge;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\Payer;
use App\Models\Patient;
use App\Models\TaxRule;
use Tests\Support\Identity;

/**
 * End-to-end test: Nepal tax rules flowing through the full billing pipeline.
 *
 * Verifies:
 * 1. Tax rule creation (effective-dated)
 * 2. Charge posting with auto-resolved tax_rule_id and tax_rate_bps
 * 3. Invoice generation using the TaxRule model for tax calculation
 * 4. Payment capture settling the invoice
 * 5. Historical reproducibility (tax rule snapshot on the charge)
 * 6. SSF/HIB payer configuration with benefit rules
 */
beforeEach(function (): void {
    seedIdentity();
});

it('resolves the correct tax rule on a charge and calculates invoice tax', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    // ── Step 1: Create a tax rule (VAT 13%) ──────────────────
    $vatRule = TaxRule::create([
        'tenant_id' => $org->getKey(),
        'facility_id' => null, // org-wide
        'code' => 'VAT_13',
        'name' => 'Standard VAT 13%',
        'tax_type' => 'vat',
        'rate_method' => 'percentage',
        'rate_value_bps' => 1300, // 13%
        'currency' => 'NPR',
        'jurisdiction' => 'nepal',
        'effective_from' => '2025-07-16',
        'status' => 'active',
    ]);

    expect($vatRule->isCurrentlyEffective())->toBeTrue();

    // ── Step 2: Create a patient and signed encounter ─────────
    $patient = Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $staff = Identity::user();
    Identity::assign($staff, 'hospital_admin', $org, $facility);

    $staffModel = \App\Models\Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $staff->getKey(),
    ]);

    $encounter = Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $staffModel->getKey(),
        'status' => 'signed',
        'started_at' => now()->subHour(),
        'ended_at' => now()->subMinutes(30),
    ]);

    // ── Step 3: Post a charge — should auto-resolve tax rule ─
    $charge = Charge::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'source_type' => Charge::SOURCE_ENCOUNTER,
        'encounter_id' => $encounter->getKey(),
        'description' => 'OPD Consultation',
        'amount_minor' => 50000, // NPR 500
        'currency' => 'NPR',
        ...Charge::resolveTaxFields($facility->getKey(), 'opd'),
        'status' => Charge::STATUS_POSTED,
        'charged_at' => now(),
        'created_by' => $staff->getKey(),
    ]);

    // Charge should have the tax rule linked
    expect($charge->tax_rule_id)->not->toBeNull()
        ->and($charge->tax_rate_bps)->toBe(1300);

    // The tax rule should still be the same one we created
    $linkedRule = $charge->taxRule;
    expect($linkedRule)->not->toBeNull()
        ->and($linkedRule->code)->toBe('VAT_13');

    // ── Step 4: Issue an invoice — should use TaxRule for tax ─
    $billing = app(\App\Services\BillingService::class);

    $invoice = $billing->issueInvoice(
        tenantId: $org->getKey(),
        facilityId: $facility->getKey(),
        patientId: $patient->getKey(),
        chargeIds: [$charge->getKey()],
        createdBy: $staff->getKey(),
    );

    expect($invoice->status)->toBe('issued')
        ->and($invoice->total_minor)->toBe(50000)
        // Tax: 50000 × 1300 / 10000 = 6500 (NPR 65)
        ->and($invoice->total_tax_minor)->toBe(6500)
        ->and($invoice->paid_minor)->toBe(0);

    // Invoice line should carry the correct tax
    $line = $invoice->lines()->first();
    expect($line->amount_minor)->toBe(50000)
        ->and($line->tax_minor)->toBe(6500);

    // ── Step 5: Capture payment — settle the invoice ──────────
    $payment = $billing->capturePayment(
        tenantId: $org->getKey(),
        facilityId: $facility->getKey(),
        patientId: $patient->getKey(),
        invoiceId: $invoice->getKey(),
        method: 'cash',
        amountMinor: 56500, // 50000 + 6500 tax
        idempotencyKey: 'nepal-tax-pipeline-full',
        receivedBy: $staff->getKey(),
    );

    expect($payment->status)->toBe('captured')
        ->and($payment->amount_minor)->toBe(56500);

    $invoice->refresh();
    expect($invoice->status)->toBe('paid')
        ->and($invoice->paid_minor)->toBe(56500);
});

it('uses the facility-specific tax rule when one exists over the org-wide rule', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    // Org-wide rule: 13%
    TaxRule::create([
        'tenant_id' => $org->getKey(),
        'code' => 'VAT_13',
        'name' => 'Org VAT',
        'tax_type' => 'vat',
        'rate_method' => 'percentage',
        'rate_value_bps' => 1300,
        'effective_from' => '2025-07-16',
        'status' => 'active',
    ]);

    // Facility-specific rule: 5%
    TaxRule::create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'HEALTH_TAX',
        'name' => 'Health Service Tax',
        'tax_type' => 'health_service_tax',
        'rate_method' => 'percentage',
        'rate_value_bps' => 500,
        'effective_from' => '2025-07-16',
        'status' => 'active',
    ]);

    $resolver = app(\App\Services\TaxResolver::class);
    $resolved = $resolver->resolve($facility->getKey(), 'opd');

    // Facility-specific rule should win
    expect($resolved)->not->toBeNull()
        ->and($resolved->code)->toBe('HEALTH_TAX')
        ->and($resolved->rate_value_bps)->toBe(500);
});

it('returns no tax when no rules are configured', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    $resolver = app(\App\Services\TaxResolver::class);
    $resolved = $resolver->resolve($facility->getKey(), 'opd');

    expect($resolved)->toBeNull();

    $fields = Charge::resolveTaxFields($facility->getKey(), 'opd');
    expect($fields['tax_rule_id'])->toBeNull()
        ->and($fields['tax_rate_bps'])->toBe(0);
});

it('applies tax only to the matching service category', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    // OPD-specific rule
    TaxRule::create([
        'tenant_id' => $org->getKey(),
        'code' => 'OPD_TAX',
        'name' => 'OPD Only',
        'tax_type' => 'vat',
        'rate_method' => 'percentage',
        'rate_value_bps' => 1300,
        'service_category' => 'opd',
        'effective_from' => '2025-07-16',
        'status' => 'active',
    ]);

    $resolver = app(\App\Services\TaxResolver::class);

    // OPD should resolve
    $opd = $resolver->resolve($facility->getKey(), 'opd');
    expect($opd)->not->toBeNull()
        ->and($opd->code)->toBe('OPD_TAX');

    // Pharmacy should NOT resolve (no pharmacy rule exists)
    $pharmacy = $resolver->resolve($facility->getKey(), 'pharmacy');
    expect($pharmacy)->toBeNull();
});

it('respects effective dates — expired rules are not resolved', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    // Rule that expired in 2024
    TaxRule::create([
        'tenant_id' => $org->getKey(),
        'code' => 'OLD_TAX',
        'name' => 'Expired Tax',
        'tax_type' => 'vat',
        'rate_method' => 'percentage',
        'rate_value_bps' => 1000,
        'effective_from' => '2024-01-01',
        'effective_to' => '2024-12-31',
        'status' => 'active',
    ]);

    // Rule effective from 2025
    TaxRule::create([
        'tenant_id' => $org->getKey(),
        'code' => 'NEW_TAX',
        'name' => 'Current Tax',
        'tax_type' => 'vat',
        'rate_method' => 'percentage',
        'rate_value_bps' => 1300,
        'effective_from' => '2025-01-01',
        'effective_to' => null,
        'status' => 'active',
    ]);

    $resolver = app(\App\Services\TaxResolver::class);

    // Resolving for 2025 should only find NEW_TAX
    $resolved = $resolver->resolve($facility->getKey(), 'opd', '2025-08-15');
    expect($resolved)->not->toBeNull()
        ->and($resolved->code)->toBe('NEW_TAX');

    // Resolving for 2024 should only find OLD_TAX
    $resolved2 = $resolver->resolve($facility->getKey(), 'opd', '2024-06-15');
    expect($resolved2)->not->toBeNull()
        ->and($resolved2->code)->toBe('OLD_TAX');
});

it('creates SSF and HIB payers with benefit rules and validates the full payer model', function () {
    $org = Identity::organization();

    // Create SSF payer
    $ssf = Payer::create([
        'tenant_id' => $org->getKey(),
        'name' => 'Social Security Fund',
        'code' => 'SSF',
        'payer_type' => 'insurance',
        'payer_sub_type' => 'ssf',
        'scheme_version' => 'SSF_2082',
        'status' => 'active',
    ]);

    expect($ssf->payer_sub_type)->toBe('ssf')
        ->and($ssf->scheme_version)->toBe('SSF_2082');

    // Create HIB payer
    $hib = Payer::create([
        'tenant_id' => $org->getKey(),
        'name' => 'Health Insurance Board',
        'code' => 'HIB',
        'payer_type' => 'insurance',
        'payer_sub_type' => 'hib',
        'scheme_version' => 'HIB_BP_V3',
        'status' => 'active',
    ]);

    expect($hib->payer_sub_type)->toBe('hib');

    // Add SSF benefit rule
    $ssfBenefit = BenefitRule::create([
        'tenant_id' => $org->getKey(),
        'payer_id' => $ssf->getKey(),
        'code' => 'SSF_OPD_MED',
        'name' => 'SSF OPD Medicine',
        'scheme_version' => 'SSF_2082',
        'service_category' => 'medicine',
        'coverage_type' => 'capped',
        'coverage_percent_bps' => 10000, // 100%
        'limit_minor' => 500000, // NPR 5,000
        'effective_from' => '2025-07-16',
        'status' => 'active',
    ]);

    expect($ssfBenefit->calculateCoverage(30000))->toBe(30000) // under limit
        ->and($ssfBenefit->calculateCoverage(600000))->toBe(500000) // capped
        ->and($ssfBenefit->calculatePatientResponsibility(600000))->toBe(100000);

    // Add HIB benefit rule with co-pay
    $hibBenefit = BenefitRule::create([
        'tenant_id' => $org->getKey(),
        'payer_id' => $hib->getKey(),
        'code' => 'HIB_IPD',
        'name' => 'HIB IPD',
        'scheme_version' => 'HIB_BP_V3',
        'service_category' => 'ipd',
        'coverage_type' => 'co_pay',
        'coverage_percent_bps' => 7500, // 75%
        'copay_percent_bps' => 2500, // 25% co-pay
        'effective_from' => '2025-07-16',
        'status' => 'active',
    ]);

    expect($hibBenefit->calculateCoverage(100000))->toBe(75000)
        ->and($hibBenefit->calculatePatientResponsibility(100000))->toBe(25000);

    // Verify payer relationships
    expect($ssf->policies()->count())->toBe(0)
        ->and($hib->policies()->count())->toBe(0);
});

it('generates a receipt after payment with correct tax breakdown', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    // Create tax rule
    TaxRule::create([
        'tenant_id' => $org->getKey(),
        'code' => 'VAT_13',
        'name' => 'VAT',
        'tax_type' => 'vat',
        'rate_method' => 'percentage',
        'rate_value_bps' => 1300,
        'effective_from' => '2025-07-16',
        'status' => 'active',
    ]);

    $patient = Patient::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    // Post a charge with tax
    $charge = Charge::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'source_type' => 'manual',
        'description' => 'Lab Test — CBC',
        'amount_minor' => 20000, // NPR 200
        'currency' => 'NPR',
        ...Charge::resolveTaxFields($facility->getKey(), 'lab'),
        'status' => Charge::STATUS_POSTED,
        'charged_at' => now(),
    ]);

    // Issue invoice
    $billing = app(\App\Services\BillingService::class);
    $invoice = $billing->issueInvoice(
        tenantId: $org->getKey(),
        facilityId: $facility->getKey(),
        patientId: $patient->getKey(),
        chargeIds: [$charge->getKey()],
    );

    // Pay
    $payment = $billing->capturePayment(
        tenantId: $org->getKey(),
        facilityId: $facility->getKey(),
        patientId: $patient->getKey(),
        invoiceId: $invoice->getKey(),
        method: 'cash',
        amountMinor: 22600, // 20000 + 2600 tax
        idempotencyKey: 'receipt-tax-test',
    );

    // Generate receipt
    $receipt = $billing->generateReceipt(
        tenantId: $org->getKey(),
        paymentId: $payment->getKey(),
    );

    expect($receipt->receipt_number)->toStartWith('RCP-')
        ->and($receipt->amount_minor)->toBe(22600)
        ->and($receipt->method)->toBe('cash');

    // Receipt items should carry the tax breakdown
    $items = $receipt->items;
    expect($items)->toHaveCount(1)
        ->and($items[0]['amountMinor'])->toBe(20000)
        ->and($items[0]['taxMinor'])->toBe(2600);
});
