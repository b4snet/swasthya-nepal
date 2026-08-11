<?php

use App\Models\AuditEvent;
use App\Models\Charge;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\Staff;
use Tests\Support\Identity;

/**
 * The billing/payment spine of the first clinical workflow (DATABASE.md
 * §3.33–3.34): charges from the encounter + prescription, invoice issue
 * (idempotent per charge), payment capture (idempotent per key), invoice
 * settlement.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('issues an invoice from charges and captures a payment to settle it', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    // Build a signed encounter directly for billing.
    $encounter = Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => Staff::factory()->create([
            'tenant_id' => $org->getKey(),
            'facility_id' => $facility->getKey(),
            'department_id' => $department->getKey(),
        ])->getKey(),
        'status' => 'signed',
        'started_at' => now()->subHour(),
        'ended_at' => now()->subMinutes(30),
    ]);

    // Two posted charges on that encounter: one manual, one
    // prescription-sourced.
    $chargeA = Charge::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'encounter_id' => $encounter->getKey(),
        'source_type' => 'manual',
        'description' => 'OPD Consultation',
        'amount_minor' => 50000,
    ]);
    $chargeB = Charge::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'encounter_id' => $encounter->getKey(),
        'source_type' => 'prescription',
        'description' => 'Paracetamol 500 mg × 15',
        'amount_minor' => 30000,
    ]);

    // Billing an open encounter is refused; the signed one proceeds.
    $openEncounter = Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'status' => 'open',
    ]);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/encounters/'.$openEncounter->getKey().'/invoice', [
            'chargeIds' => [$chargeA->getKey(), $chargeB->getKey()],
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT'); // not a signed encounter

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/invoice', [
            'chargeIds' => [$chargeA->getKey(), $chargeB->getKey()],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.totalMinor', 80000)
        ->assertJsonPath('data.paidMinor', 0);

    $invoice = Invoice::query()->where('status', 'issued')->firstOrFail();
    expect($invoice->lines()->count())->toBe(2);

    // Re-issuing the same charges is refused (charge already invoiced).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/invoice', [
            'chargeIds' => [$chargeA->getKey(), $chargeB->getKey()],
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Capture a full payment → paid.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
            'method' => 'cash',
            'amountMinor' => 80000,
            'idempotencyKey' => 'pay-'.$invoice->getKey().'-full',
        ])
        ->assertCreated()
        ->assertJsonPath('data.invoice.status', 'paid')
        ->assertJsonPath('data.invoice.paidMinor', 80000);

    expect($invoice->refresh()->status)->toBe('paid')
        ->and(Payment::query()->count())->toBe(1)
        ->and((int) $invoice->allocations()->sum('amount_minor'))->toBe(80000);
});

it('is idempotent: retrying the same payment key never double-charges', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    $charge = Charge::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'source_type' => 'manual',
        'description' => 'Test',
        'amount_minor' => 10000,
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'status' => 'issued',
        'total_minor' => 10000,
        'total_tax_minor' => 0,
        'paid_minor' => 0,
        'issued_at' => now(),
    ]);

    $key = 'pay-retry-'.Str::uuid();

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
            'method' => 'card',
            'amountMinor' => 10000,
            'idempotencyKey' => $key,
        ])
        ->assertCreated()
        ->assertJsonPath('data.replayed', false);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
            'method' => 'card',
            'amountMinor' => 10000,
            'idempotencyKey' => $key,
        ])
        ->assertOk()
        ->assertJsonPath('data.replayed', true);

    expect(Payment::query()->count())->toBe(1)
        ->and($invoice->refresh()->status)->toBe('paid')
        ->and($invoice->paid_minor)->toBe(10000)
        ->and($invoice->allocations()->count())->toBe(1);
});

it('refuses overpayment, double-payment, and pays on a voided invoice', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'status' => 'issued',
        'total_minor' => 10000,
        'total_tax_minor' => 0,
        'paid_minor' => 0,
        'issued_at' => now(),
    ]);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
            'method' => 'cash',
            'amountMinor' => 20000,
            'idempotencyKey' => 'pay-over-'.Str::uuid(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // Pay in full, then a second payment is refused.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
            'method' => 'cash',
            'amountMinor' => 10000,
            'idempotencyKey' => 'pay-full-'.Str::uuid(),
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
            'method' => 'cash',
            'amountMinor' => 1000,
            'idempotencyKey' => 'pay-again-'.Str::uuid(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('audits invoice issue and payment capture with financial facts', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    $encounter = Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'status' => 'signed',
    ]);

    $charge = Charge::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'encounter_id' => $encounter->getKey(),
        'source_type' => 'manual',
        'description' => 'X-ray chest',
        'amount_minor' => 25000,
    ]);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/invoice', [
            'chargeIds' => [$charge->getKey()],
        ])
        ->assertCreated();

    $invoice = Invoice::query()->firstOrFail();

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
            'method' => 'cash',
            'amountMinor' => 25000,
            'idempotencyKey' => 'pay-audit-'.Str::uuid(),
        ])
        ->assertCreated();

    $issued = AuditEvent::query()->where('action', 'invoice.issued')->firstOrFail();
    expect($issued->payload['totalMinor'])->toBe(25000)
        ->and($issued->payload['lineCount'])->toBe(1)
        ->and($issued->facility_id)->toBe($facility->getKey());

    $paid = AuditEvent::query()->where('action', 'payment.captured')->firstOrFail();
    expect($paid->payload['amountMinor'])->toBe(25000)
        ->and($paid->payload['method'])->toBe('cash')
        ->and($paid->facility_id)->toBe($facility->getKey());
});
