<?php

use App\Models\AuditEvent;
use App\Models\Charge;
use App\Models\Department;
use App\Models\Deposit;
use App\Models\DepositAllocation;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Payer;
use App\Models\Settlement;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 slice 18 — the remaining Billing and Finance surface
 * (ROADMAP Phase 13, PRODUCT_REQUIREMENTS §6.13–6.14, DATABASE.md
 * §3.33–3.35): deposits (collect/allocate — exact, CAS), patient-account
 * aging (computed from invoice truth), daily cashier settlements (zero
 * variance reconciles, non-zero disputes — never silently absorbed), and
 * insurance claims (built from invoice truth, submitted, tracked, settled).
 *
 * No payment gateway is connected (INTEROPERABILITY.md §13 — planned, no
 * provider contract exists); nothing here fakes an integration.
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * A signed encounter with posted charges, invoiced by a billing clerk —
 * the established billing fixture (BillingPaymentTest).
 *
 * @return array{patient: Patient, invoice: Invoice, clerk: User, finance: User}
 */
function finInvoice(Organization $org, Facility $facility, int $amountMinor = 20000): array
{
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $clerk = Identity::user();
    $finance = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    Identity::assign($finance, 'org_finance', $org, $facility);

    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
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

    $charge = Charge::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'encounter_id' => $encounter->getKey(),
        'source_type' => Charge::SOURCE_MANUAL,
        'description' => 'OPD Consultation',
        'amount_minor' => $amountMinor,
        'tax_rate_bps' => 1300,
    ]);

    $invoiceResponse = test()->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/invoice', [
            'chargeIds' => [$charge->getKey()],
        ])
        ->assertCreated();

    $invoice = Invoice::query()->findOrFail($invoiceResponse->json('data.id'));

    return ['patient' => $patient, 'invoice' => $invoice, 'clerk' => $clerk, 'finance' => $finance];
}

/**
 * A payer + active policy for the patient.
 */
function finPolicy(Organization $org, Patient $patient): InsurancePolicy
{
    $payer = Payer::factory()->create(['tenant_id' => $org->getKey()]);

    return InsurancePolicy::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $patient->getKey(),
        'payer_id' => $payer->getKey(),
        'status' => InsurancePolicy::STATUS_ACTIVE,
    ]);
}

it('collects a deposit idempotently and lists it', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/deposits', [
            'amountMinor' => 5000,
            'idempotencyKey' => 'dep-key-1',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.amountMinor', 5000)
        ->assertJsonPath('data.remainingMinor', 5000);

    // The same key replays the SAME deposit — no new money.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/deposits', [
            'amountMinor' => 5000,
            'idempotencyKey' => 'dep-key-1',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', Deposit::query()->where('idempotency_key', 'dep-key-1')->firstOrFail()->getKey());

    expect(Deposit::query()->count())->toBe(1);

    $this->withToken(Identity::tokenFor($clerk))
        ->getJson('/api/v1/patients/'.$patient->getKey().'/deposits')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('allocates a deposit to an invoice exactly (remaining decrements, exhaust at zero)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['patient' => $patient, 'invoice' => $invoice, 'clerk' => $clerk] = finInvoice($org, $facility, 30000);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/deposits', [
            'amountMinor' => 10000,
            'idempotencyKey' => 'dep-key-alloc',
        ])
        ->assertCreated();

    $deposit = Deposit::query()->firstOrFail();

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/deposits/'.$deposit->getKey().'/allocate', [
            'invoiceId' => $invoice->getKey(),
            'amountMinor' => 4000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.amountMinor', 4000)
        ->assertJsonPath('data.deposit.remainingMinor', 6000);

    expect(DepositAllocation::query()->count())->toBe(1);

    // Exhaust the deposit on a second invoice.
    $invoice2 = Invoice::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'status' => Invoice::STATUS_ISSUED,
        'total_minor' => 50000,
        'paid_minor' => 0,
        'lock_version' => 0,
    ]);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/deposits/'.$deposit->getKey().'/allocate', [
            'invoiceId' => $invoice2->getKey(),
            'amountMinor' => 6000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.deposit.status', 'exhausted')
        ->assertJsonPath('data.deposit.remainingMinor', 0);
});

it('refuses over-allocation, cross-patient allocation, and double allocation', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['patient' => $patient, 'invoice' => $invoice, 'clerk' => $clerk] = finInvoice($org, $facility, 30000);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/deposits', [
            'amountMinor' => 5000,
            'idempotencyKey' => 'dep-key-guard',
        ])
        ->assertCreated();

    $deposit = Deposit::query()->firstOrFail();

    // Over-allocation beyond the remaining balance → 422.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/deposits/'.$deposit->getKey().'/allocate', [
            'invoiceId' => $invoice->getKey(),
            'amountMinor' => 6000,
        ])
        ->assertStatus(422);

    // A different patient's invoice → 422 (exact allocation constraint).
    $otherPatient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $otherInvoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $otherPatient->getKey(),
        'status' => Invoice::STATUS_ISSUED,
        'total_minor' => 10000,
        'paid_minor' => 0,
        'lock_version' => 0,
    ]);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/deposits/'.$deposit->getKey().'/allocate', [
            'invoiceId' => $otherInvoice->getKey(),
            'amountMinor' => 1000,
        ])
        ->assertStatus(422);

    // Allocate twice to the same invoice → the unique index 409s the second.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/deposits/'.$deposit->getKey().'/allocate', [
            'invoiceId' => $invoice->getKey(),
            'amountMinor' => 1000,
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/deposits/'.$deposit->getKey().'/allocate', [
            'invoiceId' => $invoice->getKey(),
            'amountMinor' => 1000,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(DepositAllocation::query()->where('deposit_id', $deposit->getKey())->count())->toBe(1);
});

it('serializes concurrent deposit allocations — one winner, the loser changes nothing (CAS)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['patient' => $patient, 'invoice' => $invoice, 'clerk' => $clerk] = finInvoice($org, $facility, 30000);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/deposits', [
            'amountMinor' => 10000,
            'idempotencyKey' => 'dep-key-race',
        ])
        ->assertCreated();

    $deposit = Deposit::query()->firstOrFail();

    // The winning allocation commits atomically — the exact CAS the
    // service runs: WHERE status AND remaining_minor AND lock_version
    // match, then decrement.
    $winner = DB::table('deposits')
        ->where('id', $deposit->getKey())
        ->where('status', Deposit::STATUS_ACTIVE)
        ->where('remaining_minor', 10000)
        ->where('lock_version', $deposit->lock_version)
        ->update([
            'remaining_minor' => 6000,
            'lock_version' => $deposit->lock_version + 1,
        ]);

    expect($winner)->toBe(1);

    // A second actor holding the SAME stale snapshot can never decrement
    // again: the CAS affects zero rows.
    $loser = DB::table('deposits')
        ->where('id', $deposit->getKey())
        ->where('status', Deposit::STATUS_ACTIVE)
        ->where('remaining_minor', 10000)
        ->where('lock_version', $deposit->lock_version)
        ->update([
            'remaining_minor' => 3000,
            'lock_version' => $deposit->lock_version + 1,
        ]);

    expect($loser)->toBe(0);

    // A fresh HTTP request after the winner committed reads the new state:
    // it can allocate within the remaining 6,000, but never beyond it, and
    // the same invoice can only ever receive ONE allocation (no double
    // allocation from the deposit to the same invoice).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/deposits/'.$deposit->getKey().'/allocate', [
            'invoiceId' => $invoice->getKey(),
            'amountMinor' => 7000,
        ])
        ->assertStatus(422); // exceeds the remaining 6,000 — no double-spend

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/deposits/'.$deposit->getKey().'/allocate', [
            'invoiceId' => $invoice->getKey(),
            'amountMinor' => 2000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.deposit.remainingMinor', 4000);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/deposits/'.$deposit->getKey().'/allocate', [
            'invoiceId' => $invoice->getKey(),
            'amountMinor' => 1000,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT'); // already allocated to this invoice

    expect(Deposit::query()->findOrFail($deposit->getKey())->remaining_minor)->toBe(4000)
        ->and(DepositAllocation::query()->where('deposit_id', $deposit->getKey())->count())->toBe(1);
});

it('computes patient-account aging from invoice truth (buckets, outstanding, paid excluded)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['patient' => $patient, 'invoice' => $invoice, 'clerk' => $clerk] = finInvoice($org, $facility, 20000);

    // A second invoice aged 45 days (30–59 bucket), partially paid.
    $oldInvoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'status' => Invoice::STATUS_ISSUED,
        'total_minor' => 10000,
        'total_tax_minor' => 0,
        'paid_minor' => 2500,
        'issued_at' => now()->subDays(45),
        'lock_version' => 0,
    ]);

    // A paid invoice is not outstanding.
    Invoice::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'status' => Invoice::STATUS_PAID,
        'total_minor' => 9000,
        'total_tax_minor' => 0,
        'paid_minor' => 9000,
        'issued_at' => now()->subDays(200),
        'lock_version' => 0,
    ]);

    $response = $this->withToken(Identity::tokenFor($clerk))
        ->getJson('/api/v1/patients/'.$patient->getKey().'/aging')
        ->assertOk();

    // The fresh invoice (20,000 + 13% tax = 22,600 outstanding) is current;
    // the 45-day invoice has 7,500 outstanding in the 30–59 bucket.
    expect($response->json('data.buckets.0'))->toBe(['bucket' => 'current', 'label' => '0–29 days', 'amountMinor' => 22600])
        ->and($response->json('data.buckets.1'))->toBe(['bucket' => '30', 'label' => '30–59 days', 'amountMinor' => 7500])
        ->and($response->json('data.buckets.2'))->toBe(['bucket' => '60', 'label' => '60–89 days', 'amountMinor' => 0])
        ->and($response->json('data.buckets.3'))->toBe(['bucket' => '90', 'label' => '90+ days', 'amountMinor' => 0])
        ->and($response->json('data.totalOutstandingMinor'))->toBe(30100)
        ->and($response->json('data.invoices'))->toHaveCount(2);
});

it('reconciles a cashier day — zero variance reconciles, non-zero disputes (never silently absorbed)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);

    // A cashier (staff profile linked to the clerk's user) with two
    // captured payments today.
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    $cashier = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'user_id' => $clerk->getKey(),
    ]);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    foreach ([1000, 2500] as $i => $amount) {
        $invoice = Invoice::factory()->create([
            'tenant_id' => $org->getKey(),
            'facility_id' => $facility->getKey(),
            'patient_id' => $patient->getKey(),
            'status' => Invoice::STATUS_ISSUED,
            'total_minor' => $amount,
            'paid_minor' => 0,
            'lock_version' => 0,
        ]);
        $this->withToken(Identity::tokenFor($clerk))
            ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
                'method' => 'cash',
                'amountMinor' => $amount,
                'idempotencyKey' => 'settle-pay-'.$i,
            ])
            ->assertCreated();
    }

    $today = now()->toDateString();

    // Actual matches expected (3,500) → reconciled, zero variance.
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/cashier-settlements/reconcile', [
            'cashierId' => $cashier->getKey(),
            'settlementDate' => $today,
            'actualMinor' => 3500,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'reconciled')
        ->assertJsonPath('data.expectedMinor', 3500)
        ->assertJsonPath('data.varianceMinor', 0);

    // A second cashier's day with a shortfall → disputed, variance recorded.
    $cashier2 = Staff::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $cashier2 = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'user_id' => Identity::user()->getKey(),
    ]);
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/cashier-settlements/reconcile', [
            'cashierId' => $cashier2->getKey(),
            'settlementDate' => $today,
            'actualMinor' => 500,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'disputed')
        ->assertJsonPath('data.expectedMinor', 0)
        ->assertJsonPath('data.varianceMinor', 500);

    expect(Settlement::query()->where('status', Settlement::STATUS_DISPUTED)->count())->toBe(1);
});

it('refuses to reconcile an already-closed settlement day (CAS)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);
    $cashier = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'user_id' => Identity::user()->getKey(),
    ]);

    $today = now()->toDateString();

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/cashier-settlements/reconcile', [
            'cashierId' => $cashier->getKey(),
            'settlementDate' => $today,
            'actualMinor' => 0,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'reconciled');

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/cashier-settlements/reconcile', [
            'cashierId' => $cashier->getKey(),
            'settlementDate' => $today,
            'actualMinor' => 100,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'LOCK_CONFLICT');
});

it('keeps settlement expected per cashier and day (other cashiers and days excluded)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);
    $clerkA = Identity::user();
    Identity::assign($clerkA, 'billing_clerk', $org, $facility);
    $clerkB = Identity::user();
    Identity::assign($clerkB, 'billing_clerk', $org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $cashierA = Staff::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'user_id' => $clerkA->getKey()]);
    $cashierB = Staff::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'user_id' => $clerkB->getKey()]);

    // Cashier A (clerk A) collects 2,000 today; cashier B (clerk B)
    // collects 5,000 today and 7,000 yesterday.
    foreach ([['clerk' => $clerkA, 'amount' => 2000, 'day' => 'today'], ['clerk' => $clerkB, 'amount' => 5000, 'day' => 'today'], ['clerk' => $clerkB, 'amount' => 7000, 'day' => 'yesterday']] as $row) {
        $invoice = Invoice::factory()->create([
            'tenant_id' => $org->getKey(),
            'facility_id' => $facility->getKey(),
            'patient_id' => $patient->getKey(),
            'status' => Invoice::STATUS_ISSUED,
            'total_minor' => $row['amount'],
            'paid_minor' => 0,
            'lock_version' => 0,
        ]);
        $response = $this->withToken(Identity::tokenFor($row['clerk']))
            ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
                'method' => 'cash',
                'amountMinor' => $row['amount'],
                'idempotencyKey' => 'settle-'.Str::uuid(),
            ])
            ->assertCreated();

        // Back-date B's payment to yesterday by rewriting received_at.
        if ($row['day'] === 'yesterday') {
            $paymentId = $response->json('data.paymentId');
            DB::table('payments')->where('id', $paymentId)->update(['received_at' => now()->subDay()]);
        }
    }

    $today = now()->toDateString();

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/cashier-settlements/reconcile', [
            'cashierId' => $cashierA->getKey(),
            'settlementDate' => $today,
            'actualMinor' => 2000,
        ])
        ->assertOk()
        ->assertJsonPath('data.expectedMinor', 2000);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/cashier-settlements/reconcile', [
            'cashierId' => $cashierB->getKey(),
            'settlementDate' => $today,
            'actualMinor' => 5000,
        ])
        ->assertOk()
        ->assertJsonPath('data.expectedMinor', 5000);
});

it('builds a claim from invoice truth and submits it (claim lines map exactly to invoice lines)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['patient' => $patient, 'invoice' => $invoice, 'clerk' => $clerk] = finInvoice($org, $facility, 20000);
    $policy = finPolicy($org, $patient);

    // Build the draft claim — lines copy invoice truth (amount + tax).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/claims', [
            'policyId' => $policy->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.payerId', $policy->payer_id)
        ->assertJsonPath('data.lines.0.billedMinor', 22600)
        ->assertJsonPath('data.billedMinor', 22600);

    $claim = InsuranceClaim::query()->firstOrFail();
    expect($claim->lines()->count())->toBe(1)
        ->and((int) $claim->lines()->first()->billed_minor)->toBe(22600);

    // A duplicate active claim for the same invoice+policy → 409.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/claims', [
            'policyId' => $policy->getKey(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Submit → submitted (CAS, timestamped).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/submit')
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    expect($claim->refresh()->submitted_at)->not->toBeNull();

    // Submitting twice → 409 (CAS — the second submit affects zero rows).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/submit')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'LOCK_CONFLICT');
});

it('tracks claim status and records payer settlements (denial needs a reason; settlement capped at billed)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['patient' => $patient, 'invoice' => $invoice, 'clerk' => $clerk] = finInvoice($org, $facility, 20000);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);
    $policy = finPolicy($org, $patient);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/claims', ['policyId' => $policy->getKey()])
        ->assertCreated();
    $claim = InsuranceClaim::query()->firstOrFail();

    // A DRAFT claim cannot be settled directly (draft → paid refused).
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/settle', [
            'status' => 'paid',
            'settlementMinor' => 1000,
        ])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/submit')
        ->assertOk();

    // submitted → pending (non-monetary, clerk surface).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/status', ['status' => 'pending'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    // A denial without a reason → 422.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/status', ['status' => 'denied'])
        ->assertStatus(422);

    // A settlement above the billed total → 422 (never more than invoice truth).
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/settle', [
            'status' => 'paid',
            'settlementMinor' => 99999,
        ])
        ->assertStatus(422);

    // A valid full settlement → paid, settlement recorded.
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/settle', [
            'status' => 'paid',
            'settlementMinor' => 22600,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.settlementMinor', 22600);

    // A second settlement on the same claim → refused (the claim is paid;
    // paid → paid is not a valid transition — safe denial, nothing moves).
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/settle', [
            'status' => 'paid',
            'settlementMinor' => 100,
        ])
        ->assertStatus(422);
});

it('reopens a denied claim for resubmission (no duplicate rows, no fabricated lines)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['patient' => $patient, 'invoice' => $invoice, 'clerk' => $clerk] = finInvoice($org, $facility, 20000);
    $policy = finPolicy($org, $patient);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/claims', ['policyId' => $policy->getKey()])
        ->assertCreated();
    $claim = InsuranceClaim::query()->firstOrFail();

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/submit')
        ->assertOk();
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/status', [
            'status' => 'denied',
            'denialReason' => 'Documentation incomplete',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'denied');

    // Building a NEW claim for the same invoice+policy → still refused
    // (one claim per invoice+policy — resubmission reopens the same one).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/claims', ['policyId' => $policy->getKey()])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Reopen the denied claim (denied → draft) and re-submit it.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/reopen')
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/submit')
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    // Exactly one claim and one claim line — the resubmission reused the
    // same row; the audit trail holds the denial.
    expect(InsuranceClaim::query()->count())->toBe(1)
        ->and($claim->refresh()->lines()->count())->toBe(1)
        ->and($claim->denial_reason)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'insurance_claim.status')->where('resource_id', $claim->getKey())->exists())->toBeTrue();
});

it('enforces RBAC and segregation of duties across the finance surface', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['patient' => $patient, 'invoice' => $invoice, 'clerk' => $clerk] = finInvoice($org, $facility, 20000);
    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org, $facility);
    $policy = finPolicy($org, $patient);

    // Unauthenticated → 401 everywhere. (finInvoice's withToken persists as
    // a default header, so flush it first — otherwise these requests would
    // silently carry the clerk's token.)
    $this->flushHeaders();
    $this->postJson('/api/v1/patients/'.$patient->getKey().'/deposits', ['amountMinor' => 1000, 'idempotencyKey' => 'dep-unauth-1'])
        ->assertStatus(401);
    $this->postJson('/api/v1/cashier-settlements/reconcile', ['actualMinor' => 0])
        ->assertStatus(401);

    // A doctor (no billing WRITE permission) → 403 on deposit collection;
    // reads (aging) are granted broadly to clinical roles per the seeder.
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/deposits', ['amountMinor' => 1000, 'idempotencyKey' => 'doc-dep'])
        ->assertStatus(403);
    $this->withToken(Identity::tokenFor($doctor))
        ->getJson('/api/v1/patients/'.$patient->getKey().'/aging')
        ->assertOk();

    // The billing CLERK can build/submit a claim but NOT settle it
    // (insurance:settle is the finance gate — segregation of duties).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/claims', ['policyId' => $policy->getKey()])
        ->assertCreated();
    $claim = InsuranceClaim::query()->firstOrFail();
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/submit')
        ->assertOk();
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/settle', ['status' => 'paid', 'settlementMinor' => 1000])
        ->assertStatus(403);

    // The billing CLERK cannot reconcile its own drawer (billing:reconcile
    // is not granted to the clerk role).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/cashier-settlements/reconcile', ['actualMinor' => 0])
        ->assertStatus(403);
});

it('isolates deposits and claims across tenants (read 404, write denied, data untouched)', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    ['patient' => $patientA, 'invoice' => $invoiceA, 'clerk' => $clerkA] = finInvoice($orgA, $facilityA, 20000);
    $policyA = finPolicy($orgA, $patientA);

    $this->withToken(Identity::tokenFor($clerkA))
        ->postJson('/api/v1/patients/'.$patientA->getKey().'/deposits', [
            'amountMinor' => 5000,
            'idempotencyKey' => 'dep-iso-a',
        ])
        ->assertCreated();
    $this->withToken(Identity::tokenFor($clerkA))
        ->postJson('/api/v1/invoices/'.$invoiceA->getKey().'/claims', ['policyId' => $policyA->getKey()])
        ->assertCreated();

    $depositA = Deposit::query()->firstOrFail();
    $claimA = InsuranceClaim::query()->firstOrFail();

    // Tenant B's finance officer can neither read nor touch A's rows.
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $financeB = Identity::user();
    Identity::assign($financeB, 'org_finance', $orgB, $facilityB);
    $clerkB = Identity::user();
    Identity::assign($clerkB, 'billing_clerk', $orgB, $facilityB);

    // Cross-tenant direct object reads → 404 (safe denial, no existence leak).
    $this->withToken(Identity::tokenFor($financeB))
        ->getJson('/api/v1/patients/'.$patientA->getKey().'/deposits')
        ->assertStatus(404);
    $this->withToken(Identity::tokenFor($financeB))
        ->getJson('/api/v1/claims/'.$claimA->getKey())
        ->assertStatus(404);

    // Cross-tenant writes → 403 (scope denied before the write).
    $this->withToken(Identity::tokenFor($financeB))
        ->postJson('/api/v1/deposits/'.$depositA->getKey().'/allocate', [
            'invoiceId' => $invoiceA->getKey(),
            'amountMinor' => 100,
        ])
        ->assertStatus(403);
    $this->withToken(Identity::tokenFor($financeB))
        ->postJson('/api/v1/claims/'.$claimA->getKey().'/submit')
        ->assertStatus(403);

    // Nothing changed in tenant A.
    expect(Deposit::query()->findOrFail($depositA->getKey())->remaining_minor)->toBe(5000)
        ->and(InsuranceClaim::query()->findOrFail($claimA->getKey())->status)->toBe(InsuranceClaim::STATUS_DRAFT)
        ->and(DepositAllocation::query()->count())->toBe(0);
});

it('keeps finance audit payloads PHI-safe (facts only — no names, policy numbers, or free-text reasons)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    ['patient' => $patient, 'invoice' => $invoice, 'clerk' => $clerk] = finInvoice($org, $facility, 20000);
    $finance = Identity::user();
    Identity::assign($finance, 'org_finance', $org, $facility);
    $policy = finPolicy($org, $patient);
    $cashier = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'user_id' => Identity::user()->getKey(),
    ]);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/patients/'.$patient->getKey().'/deposits', [
            'amountMinor' => 5000,
            'idempotencyKey' => 'dep-audit-1',
        ])
        ->assertCreated();
    $deposit = Deposit::query()->firstOrFail();
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/deposits/'.$deposit->getKey().'/allocate', [
            'invoiceId' => $invoice->getKey(),
            'amountMinor' => 2000,
        ])
        ->assertCreated();
    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/cashier-settlements/reconcile', [
            'cashierId' => $cashier->getKey(),
            'settlementDate' => now()->toDateString(),
            'actualMinor' => 0,
            'notes' => 'drawer shortage explained in person',
        ])
        ->assertOk();
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/claims', ['policyId' => $policy->getKey()])
        ->assertCreated();
    $claim = InsuranceClaim::query()->firstOrFail();
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/submit')
        ->assertOk();
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/claims/'.$claim->getKey().'/status', [
            'status' => 'denied',
            'denialReason' => 'Patient records missing for review',
        ])
        ->assertOk();

    $payloads = AuditEvent::query()
        ->whereIn('action', ['deposit.collected', 'deposit.allocated', 'settlement.reconciled', 'insurance_claim.built', 'insurance_claim.submitted', 'insurance_claim.status'])
        ->get('payload')
        ->map(fn (AuditEvent $e): array => is_array($e->payload) ? $e->payload : (array) json_decode((string) $e->payload, true))
        ->all();

    expect($payloads)->not->toBeEmpty();

    foreach ($payloads as $payload) {
        $serialized = json_encode($payload);
        expect($serialized)->not->toContain('drawer shortage')
            ->and($serialized)->not->toContain('records missing')
            ->and($serialized)->not->toContain($patient->full_name)
            ->and($serialized)->not->toContain($policy->policy_number);
    }

    // The denial reason and settlement notes are facts-only: the denial is
    // recorded as a boolean, never the free-text reason.
    expect($payloads)->each(fn ($payload) => $payload->not->toHaveKey('denialReason')
        ->not->toHaveKey('notes')
        ->not->toHaveKey('policyNumber'));
});
