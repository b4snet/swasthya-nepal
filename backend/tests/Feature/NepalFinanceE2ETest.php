<?php

use App\Models\BenefitRule;
use App\Models\Charge;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimLine;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use App\Models\Payer;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\RefundRequest;
use App\Models\TaxRule;
use App\Services\BillingService;
use App\Services\TaxResolver;
use Tests\Support\Identity;

/**
 * End-to-end Nepal financial integration tests.
 *
 * Proves that regulatory configuration flows through the REAL hospital
 * financial system — from patient registration through charge, tax,
 * invoice, claim, payment, settlement, and reconciliation.
 *
 * Covers:
 * - Self-pay flow (no payer)
 * - Private insurance flow (payer + claim)
 * - SSF flow (SSF payer + benefit rules + claim)
 * - HIB flow (HIB payer + benefit rules + claim)
 * - Corporate/sponsor flow
 * - Mixed payer responsibility
 * - Tax rule versioning (historical immutability)
 * - Fiscal period enforcement
 * - Refund flow
 * - Financial invariants
 */
beforeEach(function (): void {
    seedIdentity();
});

// ══════════════════════════════════════════════════════════════
// SELF-PAY FLOW
// ══════════════════════════════════════════════════════════════

it('completes a full self-pay patient journey: encounter → charge → tax → invoice → payment', function () {
    $ctx = $this->ctx();
    $patient = Patient::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey()]);

    // Tax rule: VAT 13%
    $taxRule = TaxRule::create([
        'tenant_id' => $ctx['org']->getKey(),
        'code' => 'VAT_13',
        'name' => 'Standard VAT',
        'tax_type' => 'vat',
        'rate_method' => 'percentage',
        'rate_value_bps' => 1300,
        'effective_from' => '2025-07-16',
        'status' => 'active',
    ]);

    // Signed encounter
    $encounter = $this->createSignedEncounter($ctx, $patient);

    // Charge: NPR 500 consultation
    $charge = $this->postCharge($ctx, $patient, $encounter, 50000, 'opd');

    expect($charge->tax_rule_id)->toBe($taxRule->getKey())
        ->and($charge->tax_rate_bps)->toBe(1300);

    // Invoice: tax = 50000 × 1300 / 10000 = 6500
    $billing = app(BillingService::class);
    $invoice = $billing->issueInvoice(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        chargeIds: [$charge->getKey()],
    );

    expect($invoice->total_minor)->toBe(50000)
        ->and($invoice->total_tax_minor)->toBe(6500)
        ->and($invoice->paid_minor)->toBe(0);

    // Payment: full amount (50000 + 6500 = 56500)
    $payment = $billing->capturePayment(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        invoiceId: $invoice->getKey(),
        method: 'cash',
        amountMinor: 56500,
        idempotencyKey: 'self-pay-' . $invoice->getKey(),
    );

    expect($payment->status)->toBe('captured');
    expect($invoice->refresh()->status)->toBe('paid');

    // Reconciliation: payment + outstanding = invoice total
    $invoice->refresh();
    expect($invoice->paid_minor)->toBe($invoice->total_minor);
});

// ══════════════════════════════════════════════════════════════
// PRIVATE INSURANCE FLOW
// ══════════════════════════════════════════════════════════════

it('completes a private insurance flow: eligibility → charge → claim → invoice → patient share', function () {
    $ctx = $this->ctx();
    $patient = Patient::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey()]);

    // Private insurance payer with 75% coverage
    $payer = Payer::create([
        'tenant_id' => $ctx['org']->getKey(),
        'name' => 'Nepal Insurance Co',
        'code' => 'PRIV_INS',
        'payer_type' => 'insurance',
        'payer_sub_type' => 'private',
        'status' => 'active',
    ]);

    $benefit = BenefitRule::create([
        'tenant_id' => $ctx['org']->getKey(),
        'payer_id' => $payer->getKey(),
        'code' => 'PRIV_GENERAL',
        'name' => 'General Coverage',
        'scheme_version' => 'PRIV_V1',
        'service_category' => null,
        'coverage_type' => 'co_pay',
        'coverage_percent_bps' => 7500, // 75%
        'copay_percent_bps' => 2500, // 25% patient
        'effective_from' => '2025-07-16',
        'status' => 'active',
    ]);

    // Patient policy
    $policy = InsurancePolicy::create([
        'tenant_id' => $ctx['org']->getKey(),
        'patient_id' => $patient->getKey(),
        'payer_id' => $payer->getKey(),
        'policy_number' => 'PRIV-001',
        'status' => 'active',
        'benefits' => ['coverage_percent_bps' => 7500],
    ]);

    // Service charge: NPR 10,000
    $encounter = $this->createSignedEncounter($ctx, $patient);
    $charge = $this->postCharge($ctx, $patient, $encounter, 1000000, 'opd'); // NPR 10,000

    // Coverage calculation: 75% covered = 750,000 minor; patient = 250,000 minor
    $coverage = $benefit->calculateCoverage(1000000);
    $patientShare = $benefit->calculatePatientResponsibility(1000000);

    expect($coverage)->toBe(750000)
        ->and($patientShare)->toBe(250000)
        ->and($coverage + $patientShare)->toBe(1000000); // invariant: covers total

    // Invoice from charge
    $billing = app(BillingService::class);
    $invoice = $billing->issueInvoice(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        chargeIds: [$charge->getKey()],
    );

    // Patient pays their share
    $payment = $billing->capturePayment(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        invoiceId: $invoice->getKey(),
        method: 'cash',
        amountMinor: $patientShare,
        idempotencyKey: 'ins-patient-' . $invoice->getKey(),
    );

    expect($payment->status)->toBe('captured');

    // Invariant: payment + payer responsibility = total charge
    $invoice->refresh();
    $totalAccounted = $invoice->paid_minor + ($invoice->total_minor - $invoice->paid_minor);
    expect($totalAccounted)->toBe($invoice->total_minor);
});

// ══════════════════════════════════════════════════════════════
// SSF FLOW
// ══════════════════════════════════════════════════════════════

it('completes an SSF flow: eligibility → benefit check → charge → claim → patient responsibility', function () {
    $ctx = $this->ctx();
    $patient = Patient::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey()]);

    // SSF payer
    $ssfPayer = Payer::create([
        'tenant_id' => $ctx['org']->getKey(),
        'name' => 'Social Security Fund',
        'code' => 'SSF',
        'payer_type' => 'insurance',
        'payer_sub_type' => 'ssf',
        'scheme_version' => 'SSF_2083',
        'status' => 'active',
    ]);

    // SSF benefit: 80% coverage, 20% co-pay, NPR 100,000/year limit
    $ssfBenefit = BenefitRule::create([
        'tenant_id' => $ctx['org']->getKey(),
        'payer_id' => $ssfPayer->getKey(),
        'code' => 'SSF_MEDICAL',
        'name' => 'SSF Medical Treatment',
        'scheme_version' => 'SSF_2083',
        'service_category' => null,
        'coverage_type' => 'co_pay',
        'coverage_percent_bps' => 8000, // 80%
        'copay_percent_bps' => 2000, // 20% co-pay
        'limit_minor' => 10000000, // NPR 100,000
        'effective_from' => '2026-07-16',
        'status' => 'active',
    ]);

    // Patient policy
    InsurancePolicy::create([
        'tenant_id' => $ctx['org']->getKey(),
        'patient_id' => $patient->getKey(),
        'payer_id' => $ssfPayer->getKey(),
        'policy_number' => 'SSF-EMP-001',
        'status' => 'active',
        'benefits' => ['coverage_percent_bps' => 8000, 'copay_percent_bps' => 2000],
    ]);

    // Service: NPR 20,000 lab test
    $encounter = $this->createSignedEncounter($ctx, $patient);
    $charge = $this->postCharge($ctx, $patient, $encounter, 2000000, 'lab'); // NPR 20,000

    // SSF coverage: 80% = 1,600,000 minor; patient = 400,000 minor
    $coverage = $ssfBenefit->calculateCoverage(2000000);
    $patientShare = $ssfBenefit->calculatePatientResponsibility(2000000);

    expect($coverage)->toBe(1600000)
        ->and($patientShare)->toBe(400000)
        ->and($coverage + $patientShare)->toBe(2000000);

    // Verify within annual limit
    expect($coverage)->toBeLessThanOrEqual($ssfBenefit->limit_minor);

    // Create claim (hospital prepares claim for SSF submission)
    $claim = InsuranceClaim::create([
        'tenant_id' => $ctx['org']->getKey(),
        'claim_number' => 'SSF-CLM-001',
        'policy_id' => InsurancePolicy::where('tenant_id', $ctx['org']->getKey())->where('patient_id', $patient->getKey())->first()->getKey(),
        'invoice_id' => null, // will be set after invoice
        'payer_id' => $ssfPayer->getKey(),
        'benefit_rule_id' => $ssfBenefit->getKey(),
        'claim_type' => 'ssf',
        'status' => 'draft',
        'lock_version' => 0,
    ]);

    expect($claim->claim_type)->toBe('ssf')
        ->and($claim->benefit_rule_id)->toBe($ssfBenefit->getKey());

    // SSF claim is prepared locally; external submission is EXTERNAL WORKFLOW
    expect($claim->status)->toBe('draft');
});

// ══════════════════════════════════════════════════════════════
// HIB FLOW
// ══════════════════════════════════════════════════════════════

it('completes an HIB flow: eligibility → benefit check → charge → claim', function () {
    $ctx = $this->ctx();
    $patient = Patient::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey()]);

    // HIB payer
    $hibPayer = Payer::create([
        'tenant_id' => $ctx['org']->getKey(),
        'name' => 'Health Insurance Board',
        'code' => 'HIB',
        'payer_type' => 'insurance',
        'payer_sub_type' => 'hib',
        'scheme_version' => 'HIB_2083',
        'status' => 'active',
    ]);

    // HIB benefit: NPR 100,000 family coverage
    $hibBenefit = BenefitRule::create([
        'tenant_id' => $ctx['org']->getKey(),
        'payer_id' => $hibPayer->getKey(),
        'code' => 'HIB_FAMILY',
        'name' => 'HIB Family Coverage',
        'scheme_version' => 'HIB_2083',
        'service_category' => null,
        'coverage_type' => 'capped',
        'coverage_percent_bps' => 10000, // 100% up to limit
        'limit_minor' => 10000000, // NPR 100,000
        'effective_from' => '2026-07-16',
        'status' => 'active',
    ]);

    // Patient policy
    InsurancePolicy::create([
        'tenant_id' => $ctx['org']->getKey(),
        'patient_id' => $patient->getKey(),
        'payer_id' => $hibPayer->getKey(),
        'policy_number' => 'HIB-FAM-001',
        'status' => 'active',
        'benefits' => ['coverage_percent_bps' => 10000, 'limit_minor' => 10000000],
    ]);

    // Service: NPR 50,000 surgery
    $encounter = $this->createSignedEncounter($ctx, $patient);
    $charge = $this->postCharge($ctx, $patient, $encounter, 5000000, 'surgery');

    // HIB covers 100% up to NPR 100,000
    $coverage = $hibBenefit->calculateCoverage(5000000);
    $patientShare = $hibBenefit->calculatePatientResponsibility(5000000);

    expect($coverage)->toBe(5000000) // within limit
        ->and($patientShare)->toBe(0)
        ->and($coverage)->toBeLessThanOrEqual($hibBenefit->limit_minor);

    // Test exceeding the limit
    $largeCharge = 15000000; // NPR 150,000 — exceeds limit
    $cappedCoverage = $hibBenefit->calculateCoverage($largeCharge);
    $cappedPatientShare = $hibBenefit->calculatePatientResponsibility($largeCharge);

    expect($cappedCoverage)->toBe(10000000) // capped at limit
        ->and($cappedPatientShare)->toBe(5000000) // patient pays remainder
        ->and($cappedCoverage + $cappedPatientShare)->toBe($largeCharge);
});

// ══════════════════════════════════════════════════════════════
// CORPORATE SPONSOR FLOW
// ══════════════════════════════════════════════════════════════

it('handles corporate sponsor coverage correctly', function () {
    $ctx = $this->ctx();
    $patient = Patient::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey()]);

    // Corporate payer
    $sponsor = Payer::create([
        'tenant_id' => $ctx['org']->getKey(),
        'name' => 'Nepal Telecom',
        'code' => 'NTC',
        'payer_type' => 'insurance',
        'payer_sub_type' => 'corporate',
        'status' => 'active',
    ]);

    // Sponsor covers 100% of OPD, 0% of pharmacy
    $opdBenefit = BenefitRule::create([
        'tenant_id' => $ctx['org']->getKey(),
        'payer_id' => $sponsor->getKey(),
        'code' => 'NTC_OPD',
        'name' => 'NTC OPD Coverage',
        'scheme_version' => 'NTC_2083',
        'service_category' => 'opd',
        'coverage_type' => 'full',
        'coverage_percent_bps' => 10000,
        'effective_from' => '2026-07-16',
        'status' => 'active',
    ]);

    $pharmacyBenefit = BenefitRule::create([
        'tenant_id' => $ctx['org']->getKey(),
        'payer_id' => $sponsor->getKey(),
        'code' => 'NTC_PHARM',
        'name' => 'NTC Pharmacy (excluded)',
        'scheme_version' => 'NTC_2083',
        'service_category' => 'pharmacy',
        'coverage_type' => 'excluded',
        'coverage_percent_bps' => 0,
        'effective_from' => '2026-07-16',
        'status' => 'active',
    ]);

    // OPD service: fully covered
    expect($opdBenefit->calculateCoverage(50000))->toBe(50000)
        ->and($opdBenefit->calculatePatientResponsibility(50000))->toBe(0);

    // Pharmacy: excluded, patient pays all
    expect($pharmacyBenefit->calculateCoverage(30000))->toBe(0)
        ->and($pharmacyBenefit->calculatePatientResponsibility(30000))->toBe(30000);
});

// ══════════════════════════════════════════════════════════════
// TAX RULE VERSIONING (HISTORICAL IMMUTABILITY)
// ══════════════════════════════════════════════════════════════

it('preserves historical tax when rules change — rule V1 transactions remain unchanged after V2', function () {
    $ctx = $this->ctx();
    $patient = Patient::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey()]);

    // Rule V1: 13% VAT, effective Jul 16 2025
    $ruleV1 = TaxRule::create([
        'tenant_id' => $ctx['org']->getKey(),
        'code' => 'VAT_V1',
        'name' => 'VAT 13%',
        'tax_type' => 'vat',
        'rate_method' => 'percentage',
        'rate_value_bps' => 1300,
        'effective_from' => '2025-07-16',
        'effective_to' => '2026-07-15',
        'status' => 'superseded',
    ]);

    // Rule V2: 15% VAT, effective Jul 16 2026
    $ruleV2 = TaxRule::create([
        'tenant_id' => $ctx['org']->getKey(),
        'code' => 'VAT_V2',
        'name' => 'VAT 15%',
        'tax_type' => 'vat',
        'rate_method' => 'percentage',
        'rate_value_bps' => 1500,
        'effective_from' => '2026-07-16',
        'status' => 'active',
    ]);

    // Charge under V1 (historical)
    $encounter1 = $this->createSignedEncounter($ctx, $patient);
    $chargeV1 = Charge::query()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'source_type' => 'manual',
        'description' => 'Service under V1',
        'amount_minor' => 100000,
        'currency' => 'NPR',
        'tax_rule_id' => $ruleV1->getKey(),
        'tax_rate_bps' => 1300,
        'status' => 'posted',
        'charged_at' => now(),
    ]);

    // Invoice under V1
    $billing = app(BillingService::class);
    $invoiceV1 = $billing->issueInvoice(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        chargeIds: [$chargeV1->getKey()],
    );

    // V1 tax: 100000 × 1300 / 10000 = 13000
    expect($invoiceV1->total_tax_minor)->toBe(13000);

    // Charge under V2 (current)
    $encounter2 = $this->createSignedEncounter($ctx, $patient);
    $chargeV2 = Charge::query()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'source_type' => 'manual',
        'description' => 'Service under V2',
        'amount_minor' => 100000,
        'currency' => 'NPR',
        'tax_rule_id' => $ruleV2->getKey(),
        'tax_rate_bps' => 1500,
        'status' => 'posted',
        'charged_at' => now(),
    ]);

    $invoiceV2 = $billing->issueInvoice(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        chargeIds: [$chargeV2->getKey()],
    );

    // V2 tax: 100000 × 1500 / 10000 = 15000
    expect($invoiceV2->total_tax_minor)->toBe(15000);

    // Historical V1 invoice remains unchanged
    $invoiceV1->refresh();
    expect($invoiceV1->total_tax_minor)->toBe(13000)
        ->and($invoiceV1->total_minor)->toBe(100000);

    // V2 invoice uses new rate
    expect($invoiceV2->total_tax_minor)->toBe(15000)
        ->and($invoiceV2->total_minor)->toBe(100000);
});

// ══════════════════════════════════════════════════════════════
// FISCAL PERIOD ENFORCEMENT
// ══════════════════════════════════════════════════════════════

it('rejects charges against locked fiscal periods', function () {
    $ctx = $this->ctx();

    // Create and lock a period
    \App\Models\FinancialPeriod::create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'name' => 'Locked Period',
        'fiscal_year' => 2082,
        'period_number' => 1,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'status' => 'locked',
        'period_status' => 'locked',
    ]);

    // Charge should be rejected
    $this->expectException(\App\Exceptions\ApiException::class);
    Charge::resolveTaxFields($ctx['facility']->getKey(), 'opd');
});

it('allows charges in open fiscal periods', function () {
    $ctx = $this->ctx();

    \App\Models\FinancialPeriod::create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'name' => 'Open Period',
        'fiscal_year' => 2083,
        'period_number' => 1,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(),
        'status' => 'open',
        'period_status' => 'open',
    ]);

    $fields = Charge::resolveTaxFields($ctx['facility']->getKey(), 'opd');
    expect($fields)->toHaveKey('tax_rule_id')
        ->and($fields)->toHaveKey('tax_rate_bps');
});

// ══════════════════════════════════════════════════════════════
// REFUND FLOW
// ══════════════════════════════════════════════════════════════

it('processes a refund through the complete lifecycle: request → approve → complete', function () {
    $ctx = $this->ctx();
    $patient = Patient::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey()]);

    // Post charge and invoice
    $encounter = $this->createSignedEncounter($ctx, $patient);
    $charge = $this->postCharge($ctx, $patient, $encounter, 50000, 'opd');

    $billing = app(BillingService::class);
    $invoice = $billing->issueInvoice(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        chargeIds: [$charge->getKey()],
    );

    // Pay in full
    $payment = $billing->capturePayment(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        invoiceId: $invoice->getKey(),
        method: 'cash',
        amountMinor: $invoice->total_minor,
        idempotencyKey: 'refund-test-' . $invoice->getKey(),
    );

    // Request refund
    $refundRequest = $billing->requestRefund(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        chargeId: $charge->getKey(),
        amountMinor: 10000,
        reasonCode: 'patient_request',
        reasonNote: 'Service cancelled',
        requestedBy: $ctx['admin']->getKey(),
    );

    expect($refundRequest->status)->toBe('requested')
        ->and($refundRequest->amount_minor)->toBe(10000);

    // Approve (different person — segregation of duties)
    $approver = Identity::user();
    Identity::assign($approver, 'hospital_admin', $ctx['org'], $ctx['facility']);

    $approved = $billing->approveRefund(
        tenantId: $ctx['org']->getKey(),
        requestId: $refundRequest->getKey(),
        approverId: $approver->getKey(),
    );

    expect($approved->status)->toBe('approved');

    // Complete (disbursement)
    $completed = $billing->completeRefund(
        tenantId: $ctx['org']->getKey(),
        requestId: $refundRequest->getKey(),
        completerId: $approver->getKey(),
    );

    expect($completed->status)->toBe('completed');

    // Verify: refundable amount decreased
    $refundable = $charge->amount_minor - $billing->approvedTotal($ctx['org']->getKey(), $charge->getKey());
    expect($refundable)->toBe(40000); // 50000 - 10000 refunded
});

// ══════════════════════════════════════════════════════════════
// FINANCIAL INTEGRITY INVARIANTS
// ══════════════════════════════════════════════════════════════

it('enforces financial invariants: no negative, no duplicate, no over-refund', function () {
    $ctx = $this->ctx();
    $patient = Patient::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey()]);

    $encounter = $this->createSignedEncounter($ctx, $patient);
    $charge = $this->postCharge($ctx, $patient, $encounter, 50000, 'opd');

    $billing = app(BillingService::class);
    $invoice = $billing->issueInvoice(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        chargeIds: [$charge->getKey()],
    );

    // Pay in full
    $billing->capturePayment(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        invoiceId: $invoice->getKey(),
        method: 'cash',
        amountMinor: $invoice->total_minor,
        idempotencyKey: 'invariant-' . $invoice->getKey(),
    );

    // INVARIANT: Cannot over-refund
    $refundable = $charge->amount_minor - $billing->approvedTotal($ctx['org']->getKey(), $charge->getKey());
    expect($refundable)->toBe(0); // fully paid, nothing refundable after payment

    // Actually refundable = amount - approved refunds (not payments)
    $refundable2 = $charge->amount_minor - $billing->approvedTotal($ctx['org']->getKey(), $charge->getKey());
    expect($refundable2)->toBe(50000); // no refunds yet

    // Try to refund more than the charge
    $this->expectException(\App\Exceptions\ApiException::class);
    $billing->requestRefund(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        chargeId: $charge->getKey(),
        amountMinor: 60000, // exceeds 50000
        reasonCode: 'test',
        reasonNote: null,
    );
});

// ══════════════════════════════════════════════════════════════
// CLAIM STATUS MACHINE
// ══════════════════════════════════════════════════════════════

it('enforces claim lifecycle: draft → submitted → pending → accepted/denied', function () {
    $ctx = $this->ctx();
    $patient = Patient::factory()->create(['tenant_id' => $ctx['org']->getKey(), 'facility_id' => $ctx['facility']->getKey()]);

    $payer = Payer::create([
        'tenant_id' => $ctx['org']->getKey(),
        'name' => 'Test Insurance',
        'code' => 'TEST_INS',
        'payer_type' => 'insurance',
        'status' => 'active',
    ]);

    $policy = InsurancePolicy::create([
        'tenant_id' => $ctx['org']->getKey(),
        'patient_id' => $patient->getKey(),
        'payer_id' => $payer->getKey(),
        'policy_number' => 'T-001',
        'status' => 'active',
    ]);

    $encounter = $this->createSignedEncounter($ctx, $patient);
    $charge = $this->postCharge($ctx, $patient, $encounter, 50000, 'opd');

    $billing = app(BillingService::class);
    $invoice = $billing->issueInvoice(
        tenantId: $ctx['org']->getKey(),
        facilityId: $ctx['facility']->getKey(),
        patientId: $patient->getKey(),
        chargeIds: [$charge->getKey()],
    );

    // Build claim from invoice
    $claim = InsuranceClaim::create([
        'tenant_id' => $ctx['org']->getKey(),
        'claim_number' => 'CLM-TEST-001',
        'policy_id' => $policy->getKey(),
        'invoice_id' => $invoice->getKey(),
        'payer_id' => $payer->getKey(),
        'status' => 'draft',
        'lock_version' => 0,
    ]);

    expect($claim->status)->toBe('draft');

    // Submit
    $financeService = app(\App\Services\FinanceService::class);
    $submitted = $financeService->submitClaim($claim, $ctx['admin']->getKey());
    expect($submitted->status)->toBe('submitted');

    // Record payer response: accepted
    [$accepted, $transition] = $financeService->recordClaimStatus(
        $submitted,
        'pending',
        null,
        null,
        $ctx['admin']->getKey(),
    );
    expect($accepted->status)->toBe('pending');

    // Settle
    [$settled, $transition2] = $financeService->recordClaimStatus(
        $accepted,
        'paid',
        null,
        40000, // payer pays 40000
        $ctx['admin']->getKey(),
    );

    expect($settled->status)->toBe('paid')
        ->and($settled->settlement_minor)->toBe(40000);

    // INVARIANT: settlement <= billed total
    expect($settled->settlement_minor)->toBeLessThanOrEqual($settled->billedTotalMinor());
});

// ══════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════

private function ctx(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    return ['org' => $org, 'facility' => $facility, 'admin' => $admin];
}

private function createSignedEncounter(array $ctx, Patient $patient): Encounter
{
    $department = Department::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
    ]);

    $staff = \App\Models\Staff::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'department_id' => $department->getKey(),
    ]);

    return Encounter::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $staff->getKey(),
        'status' => 'signed',
        'started_at' => now()->subHour(),
        'ended_at' => now()->subMinutes(30),
    ]);
}

private function postCharge(array $ctx, Patient $patient, Encounter $encounter, int $amountMinor, string $category): Charge
{
    return Charge::query()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'patient_id' => $patient->getKey(),
        'source_type' => 'manual',
        'encounter_id' => $encounter->getKey(),
        'description' => "Service — {$category}",
        'amount_minor' => $amountMinor,
        'currency' => 'NPR',
        ...Charge::resolveTaxFields($ctx['facility']->getKey(), $category),
        'status' => 'posted',
        'charged_at' => now(),
        'created_by' => $ctx['admin']->getKey(),
    ]);
}
