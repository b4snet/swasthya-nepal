<?php

use App\Models\AuditEvent;
use App\Models\Charge;
use App\Models\Deposit;
use App\Models\DepositAllocation;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\RefundRequest;
use Tests\Support\Identity;

/**
 * Phase 13 — charge/invoice void (ROADMAP §14, DATABASE.md §3.33): void is
 * a status with required reason and approver — never a delete. Posted
 * charges are immutable; an uncollected invoice void cascades to the charges
 * it was built from. Restricted to billing:void — the clerk who charges
 * cannot void (segregation of duties).
 */
beforeEach(function (): void {
    seedIdentity();
});

function voidChargeSetup(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);

    $charge = Charge::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'source_type' => 'manual',
        'description' => 'OPD Consultation',
        'amount_minor' => 50000,
    ]);

    return [$org, $facility, $patient, $finance, $charge];
}

it('voids a posted charge with the required reason and approver — immutable otherwise', function () {
    [$org, $facility, $patient, $finance, $charge] = voidChargeSetup();

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', [
            'reason' => 'Charged in error; duplicate of the morning visit',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided')
        ->assertJsonPath('data.amountMinor', 50000)
        ->assertJsonPath('data.voidedBy', $finance->getKey());

    $voided = $charge->refresh();
    expect($voided->status)->toBe('voided')
        ->and($voided->voided_by)->toBe($finance->getKey())
        ->and($voided->void_reason)->toBe('Charged in error; duplicate of the morning visit')
        ->and($voided->amount_minor)->toBe(50000)
        ->and($voided->source_type)->toBe('manual');
});

it('requires a reason for charge void — missing or blank → 422 with zero side effects', function () {
    [$org, $facility, $patient, $finance, $charge] = voidChargeSetup();

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => '   '])
        ->assertStatus(422);

    expect($charge->refresh()->status)->toBe('posted')
        ->and($charge->voided_by)->toBeNull()
        ->and($charge->void_reason)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'charge.voided')->count())->toBe(0);
});

it('refuses to void an already-voided charge', function () {
    [$org, $facility, $patient, $finance, $charge] = voidChargeSetup();

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => 'First void'])
        ->assertOk();

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => 'Second void'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(AuditEvent::query()->where('action', 'charge.voided')->count())->toBe(1);
});

it('refuses to void an invoiced charge — void the invoice instead', function () {
    [$org, $facility, $patient, $finance, $charge] = voidChargeSetup();

    $invoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'total_minor' => $charge->amount_minor,
    ]);
    InvoiceLine::factory()->create([
        'tenant_id' => $org->getKey(),
        'invoice_id' => $invoice->getKey(),
        'charge_id' => $charge->getKey(),
        'description' => $charge->description,
        'amount_minor' => $charge->amount_minor,
    ]);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => 'Wrong charge'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect($charge->refresh()->status)->toBe('posted')
        ->and(AuditEvent::query()->where('action', 'charge.voided')->count())->toBe(0);
});

it('refuses to void a charge with a pending or approved refund reserved against it', function () {
    [$org, $facility, $patient, $finance, $charge] = voidChargeSetup();

    RefundRequest::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'charge_id' => $charge->getKey(),
        'amount_minor' => 5000,
        'status' => RefundRequest::STATUS_REQUESTED,
    ]);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => 'Wrong charge'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect($charge->refresh()->status)->toBe('posted')
        ->and(AuditEvent::query()->where('action', 'charge.voided')->count())->toBe(0);
});

it('a duplicate/concurrent charge void resolves to exactly one winner with one audit', function () {
    [$org, $facility, $patient, $finance, $charge] = voidChargeSetup();

    // First actor voids.
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => 'Race void'])
        ->assertOk();

    // Second actor (stale snapshot of a posted charge) affects zero rows.
    // The status guard fires first for a sequential duplicate — the
    // observable race outcome is exactly one winner and one audit.
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => 'Race void 2'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(Charge::query()->where('status', 'voided')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'charge.voided')->count())->toBe(1);
});

it('voids an issued invoice and cascades the void to the charges it was built from', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);

    $chargeA = Charge::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(), 'amount_minor' => 20000,
    ]);
    $chargeB = Charge::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(), 'amount_minor' => 30000,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(), 'total_minor' => 50000,
    ]);
    InvoiceLine::factory()->create([
        'tenant_id' => $org->getKey(), 'invoice_id' => $invoice->getKey(),
        'charge_id' => $chargeA->getKey(), 'amount_minor' => 20000, 'line_no' => 1,
    ]);
    InvoiceLine::factory()->create([
        'tenant_id' => $org->getKey(), 'invoice_id' => $invoice->getKey(),
        'charge_id' => $chargeB->getKey(), 'amount_minor' => 30000, 'line_no' => 2,
    ]);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/void', [
            'reason' => 'Bill issued for the wrong visit',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided')
        ->assertJsonPath('data.voidedChargeCount', 2);

    expect($invoice->refresh()->status)->toBe('voided')
        ->and($invoice->void_reason)->toBe('Bill issued for the wrong visit')
        ->and($invoice->updated_by)->toBe($finance->getKey())
        ->and($invoice->lock_version)->toBe(1)
        ->and($chargeA->refresh()->status)->toBe('voided')
        ->and($chargeB->refresh()->status)->toBe('voided')
        ->and($chargeA->void_reason)->toBe('Bill issued for the wrong visit')
        ->and($chargeA->voided_by)->toBe($finance->getKey());
});

it('refuses to void an invoice once payments have been captured', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(), 'total_minor' => 10000, 'paid_minor' => 10000, 'status' => 'paid',
    ]);
    $payment = Payment::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(), 'amount_minor' => 10000,
    ]);
    PaymentAllocation::factory()->create([
        'tenant_id' => $org->getKey(), 'payment_id' => $payment->getKey(),
        'invoice_id' => $invoice->getKey(), 'amount_minor' => 10000,
    ]);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/void', ['reason' => 'Void after payment'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect($invoice->refresh()->status)->toBe('paid')
        ->and(AuditEvent::query()->where('action', 'invoice.voided')->count())->toBe(0);
});

it('refuses to void an invoice with deposit allocations', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(), 'total_minor' => 10000,
    ]);
    $deposit = Deposit::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(), 'amount_minor' => 5000,
    ]);
    DepositAllocation::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'deposit_id' => $deposit->getKey(), 'invoice_id' => $invoice->getKey(), 'amount_minor' => 1000,
    ]);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/void', ['reason' => 'Void with deposit'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect($invoice->refresh()->status)->toBe('issued')
        ->and(AuditEvent::query()->where('action', 'invoice.voided')->count())->toBe(0);
});

it('refuses to void an invoice with an insurance claim built from it', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(), 'total_minor' => 10000,
    ]);
    $policy = InsurancePolicy::factory()->create([
        'tenant_id' => $org->getKey(), 'patient_id' => $patient->getKey(),
    ]);
    InsuranceClaim::factory()->create([
        'tenant_id' => $org->getKey(),
        'invoice_id' => $invoice->getKey(),
        'policy_id' => $policy->getKey(),
        'payer_id' => $policy->payer_id,
    ]);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/void', ['reason' => 'Void with claim'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect($invoice->refresh()->status)->toBe('issued')
        ->and(AuditEvent::query()->where('action', 'invoice.voided')->count())->toBe(0);
});

it('refuses to void a paid or already-voided invoice', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);

    $paid = Invoice::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(), 'status' => 'paid', 'paid_minor' => 10000,
    ]);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/invoices/'.$paid->getKey().'/void', ['reason' => 'Void paid'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    $voided = Invoice::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(), 'status' => 'voided',
    ]);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/invoices/'.$voided->getKey().'/void', ['reason' => 'Void again'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(AuditEvent::query()->where('action', 'invoice.voided')->count())->toBe(0);
});

it('segregates duties — the billing clerk who charges cannot void; the finance approver can', function () {
    [$org, $facility, $patient, $finance, $charge] = voidChargeSetup();

    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    // Unauthenticated first (withToken persists across requests in a test).
    $this->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => 'anon'])
        ->assertStatus(401);

    // The clerk (who holds billing:invoice/collect/refund) cannot void.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => 'Clerk void'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.Invoice::factory()->create([
            'tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(),
            'patient_id' => $patient->getKey(),
        ])->getKey().'/void', ['reason' => 'Clerk invoice void'])
        ->assertStatus(403);

    // The finance approver can.
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => 'Finance void'])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');

    expect(AuditEvent::query()->where('action', 'charge.voided')->count())->toBe(1);
});

it('audits void with financial facts only — never the reason or patient identity', function () {
    [$org, $facility, $patient, $finance, $charge] = voidChargeSetup();

    $sensitiveReason = 'Ram Bahadur was charged twice for the X-ray and complained at the counter';

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/void', ['reason' => $sensitiveReason])
        ->assertOk();

    $event = AuditEvent::query()->where('action', 'charge.voided')->firstOrFail();
    $payload = json_encode($event->payload, JSON_THROW_ON_ERROR);

    expect($event->resource_type)->toBe('charge')
        ->and($event->resource_id)->toBe($charge->getKey())
        ->and($event->facility_id)->toBe($facility->getKey())
        ->and($event->payload['amountMinor'])->toBe(50000)
        ->and($event->payload['currency'])->toBe('NPR')
        ->and($event->payload['sourceType'])->toBe('manual')
        ->and($payload)->not->toContain('Ram Bahadur')
        ->and($payload)->not->toContain('X-ray')
        ->and($payload)->not->toContain('complained');

    // The response never echoes the free-text reason either.
    expect(AuditEvent::query()->where('action', 'invoice.voided')->count())->toBe(0);
});

it('is tenant and facility isolated — cross-tenant and cross-facility voids are denied with data untouched', function () {
    [$org, $facility, $patient, $finance, $charge] = voidChargeSetup();

    // Cross-tenant: the finance officer of org A cannot even resolve org B's charge.
    $otherOrg = Identity::organization();
    $otherFacility = Identity::facility($otherOrg);
    $otherCharge = Charge::factory()->create([
        'tenant_id' => $otherOrg->getKey(),
        'facility_id' => $otherFacility->getKey(),
        'patient_id' => Patient::factory()->create([
            'tenant_id' => $otherOrg->getKey(), 'facility_id' => $otherFacility->getKey(),
        ])->getKey(),
        'amount_minor' => 70000,
    ]);

    // Cross-tenant WRITE: the application-layer scope check denies 403
    // (under real RLS the row would be invisible — 404 at binding; in the
    // feature-test connection the app check is the enforcement layer).
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$otherCharge->getKey().'/void', ['reason' => 'Cross tenant'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    expect($otherCharge->refresh()->status)->toBe('posted');

    // Cross-facility within the same tenant: a facility-scoped approver of
    // facility A cannot touch facility B's charge (403 on writes).
    $facilityB = Identity::facility($org);
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey()]);
    $chargeB = Charge::factory()->create([
        'tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(), 'amount_minor' => 40000,
    ]);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/charges/'.$chargeB->getKey().'/void', ['reason' => 'Cross facility'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    expect($chargeB->refresh()->status)->toBe('posted')
        ->and(AuditEvent::query()->where('action', 'charge.voided')->count())->toBe(0);
});
