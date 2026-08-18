<?php

use App\Models\AuditEvent;
use App\Models\Charge;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\Medication;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PharmacyReturn;
use App\Models\PrescriptionLine;
use App\Models\RefundRequest;
use App\Models\Staff;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Pharmacy return → billing notification (PRODUCT_REQUIREMENTS §5.4/§6.7,
 * DATABASE.md §3.30/§3.33/§3.37): every pharmacy return that opens a refund
 * request also creates ONE in-app billing notification (type 'billing'),
 * atomically with the return and typed to the refund request. The partial
 * unique (tenant_id, refund_request_id) makes a duplicate a database-level
 * no-op — retries and concurrent triggers cannot double-notify. The
 * notification is the billing team's surfaced view of the return's refund
 * path (GET refund-requests/{id}/notification); no money moves at the
 * return (approval stays the billing approver's segregation-of-duties-gated
 * action), and the return's financial consistency never depends on delivery
 * (in-app, created 'sent', no provider round-trip).
 */
beforeEach(function (): void {
    seedIdentity();
});

function billingNotifStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
{
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $user->getKey(),
        'designation' => $designation,
        'status' => 'active',
    ]);
}

function billingNotifEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
{
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'status' => Encounter::STATUS_OPEN,
    ]);
}

/**
 * @return array{medication: Medication, item: InventoryItem}
 */
function billingNotifStock(Organization $org, Facility $facility, string $code, int $priceMinor, int $quantity = 100): array
{
    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => $code,
        'generic_name' => 'Paracetamol',
        'price_minor' => $priceMinor,
        'currency' => 'NPR',
        'status' => Medication::STATUS_ACTIVE,
    ]);

    $item = InventoryItem::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'medication_id' => $medication->getKey(),
        'quantity_on_hand' => $quantity,
        'reorder_level' => 10,
        'lock_version' => 0,
    ]);

    StockBatch::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'inventory_item_id' => $item->getKey(),
        'medication_id' => $medication->getKey(),
        'batch_number' => 'B-'.strtoupper(substr((string) Str::uuid(), 0, 8)),
        'expiry_date' => now()->addMonths(6)->toDateString(),
        'quantity_received' => $quantity,
        'quantity_remaining' => $quantity,
        'status' => StockBatch::STATUS_AVAILABLE,
        'controlled_dispense_requires_dual' => false,
        'lock_version' => 0,
    ]);

    return ['medication' => $medication, 'item' => $item];
}

/**
 * Drive prescription creation through the real API (doctor writes the
 * prescription on an open encounter).
 *
 * @return array{prescriptionId: string, lineIds: list<string>, encounterId: string, doctorStaffId: string, patientId: string}
 */
function billingNotifPrescription(TestCase $test, Organization $org, Facility $facility, User $doctorUser, array $lines): array
{
    $doctor = billingNotifStaff($org, $facility, $doctorUser, 'Consultant Physician');
    $encounter = billingNotifEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $response = $test->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/prescriptions', [
            'lines' => collect($lines)->map(fn (array $line, int $i): array => [
                'medicationId' => $line['medicationId'],
                'dose' => '1 '.($i + 1),
                'route' => 'oral',
                'frequency' => 'tid',
                'quantityMinor' => $line['quantityMinor'],
            ])->values()->all(),
        ])
        ->assertCreated();

    return [
        'prescriptionId' => $response->json('data.id'),
        'lineIds' => collect($response->json('data.lines'))->pluck('id')->all(),
        'encounterId' => $encounter->getKey(),
        'doctorStaffId' => $doctor->getKey(),
        'patientId' => $encounter->patient_id,
    ];
}

/**
 * Verify + dispense the prescription as the pharmacist, returning the
 * pharmacist user.
 */
function billingNotifDispense(TestCase $test, Organization $org, Facility $facility, array $rx): User
{
    $pharmacist = Identity::user();
    billingNotifStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $test->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $test->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    return $pharmacist;
}

it('creates exactly one in-app billing notification atomically with the return', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    // Dispense 5 units at 500 minor each → charge 2500.
    $stock = billingNotifStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = billingNotifPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = billingNotifDispense($this, $org, $facility, $rx);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.notificationId', Notification::query()->sole()->getKey());

    $refund = RefundRequest::query()->sole();
    $notification = Notification::query()->sole();

    expect(Notification::query()->count())->toBe(1)
        ->and($notification->type)->toBe(Notification::TYPE_BILLING)
        ->and($notification->channel)->toBe(Notification::CHANNEL_IN_APP)
        ->and($notification->status)->toBe(Notification::STATUS_SENT)
        ->and($notification->sensitive)->toBeTrue()
        ->and($notification->patient_id)->toBe($rx['patientId'])
        ->and($notification->refund_request_id)->toBe($refund->getKey())
        ->and($notification->payload['refundRequestId'])->toBe($refund->getKey())
        ->and($notification->payload['chargeId'])->toBe($refund->charge_id)
        ->and($notification->payload['amountMinor'])->toBe(2500)
        ->and($notification->payload['reasonCode'])->toBe('patient_request');

    // The return's financial path is untouched: refund requested (not
    // approved), the posted charge immutable.
    expect($refund->status)->toBe(RefundRequest::STATUS_REQUESTED)
        ->and($refund->amount_minor)->toBe(2500)
        ->and(Charge::query()->findOrFail($refund->charge_id)->amount_minor)->toBe(2500);

    // Facts-only audit for the notification creation.
    $event = AuditEvent::query()->where('action', 'refund.notification_created')->firstOrFail();
    expect($event->payload)
        ->toHaveKey('refundRequestId', $refund->getKey())
        ->toHaveKey('chargeId', $refund->charge_id)
        ->toHaveKey('amountMinor', 2500)
        ->toHaveKey('reasonCode', 'patient_request')
        ->toHaveKey('channel', Notification::CHANNEL_IN_APP);
});

it('surfaces the notification to the billing team (billing:view)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = billingNotifStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = billingNotifPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = billingNotifDispense($this, $org, $facility, $rx);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(201);

    $refund = RefundRequest::query()->sole();
    $notification = Notification::query()->sole();

    $clerk = Identity::user();
    billingNotifStaff($org, $facility, $clerk, 'Billing Clerk');
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    $this->withToken(Identity::tokenFor($clerk))
        ->getJson('/api/v1/refund-requests/'.$refund->getKey().'/notification')
        ->assertOk()
        ->assertJsonPath('data.id', $notification->getKey())
        ->assertJsonPath('data.refundRequestId', $refund->getKey())
        ->assertJsonPath('data.patientId', $rx['patientId'])
        ->assertJsonPath('data.type', Notification::TYPE_BILLING)
        ->assertJsonPath('data.channel', Notification::CHANNEL_IN_APP)
        ->assertJsonPath('data.status', Notification::STATUS_SENT)
        ->assertJsonPath('data.sensitive', true)
        ->assertJsonPath('data.payload.refundRequestId', $refund->getKey())
        ->assertJsonPath('data.payload.amountMinor', 2500);
});

it('has no notification for a manual refund request (404)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = billingNotifStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = billingNotifPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    billingNotifDispense($this, $org, $facility, $rx);

    // A manual refund request (no pharmacy return) opens the request but
    // never a billing notification — the notification is the return's.
    $charge = Charge::query()->where('prescription_line_id', $rx['lineIds'][0])->firstOrFail();

    $clerk = Identity::user();
    billingNotifStaff($org, $facility, $clerk, 'Billing Clerk');
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 2500,
            'reasonCode' => 'patient_request',
        ])
        ->assertCreated();

    $refund = RefundRequest::query()->sole();
    expect(Notification::query()->count())->toBe(0);

    $this->withToken(Identity::tokenFor($clerk))
        ->getJson('/api/v1/refund-requests/'.$refund->getKey().'/notification')
        ->assertStatus(404);
});

it('refuses a second notification for the same refund request at the database level (partial unique)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = billingNotifStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = billingNotifPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = billingNotifDispense($this, $org, $facility, $rx);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(201);

    $refund = RefundRequest::query()->sole();

    // The unique index exists and a second insert for the same refund
    // request fails — the race backstop behind the atomic creation path.
    $index = DB::connection('pgsql')->selectOne(
        "select indexname from pg_indexes where schemaname = 'public' and tablename = 'notifications' and indexname = 'uq_notifications_tenant_refund_request'"
    );
    expect($index)->not->toBeNull();

    // Established savepoint pattern (AuthSubjectBindingTest / reminder test).
    expect(function () use ($org, $refund): void {
        DB::transaction(function () use ($org, $refund): void {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $org->getKey(),
                'patient_id' => $refund->patient_id,
                'refund_request_id' => $refund->getKey(),
                'type' => Notification::TYPE_BILLING,
                'channel' => Notification::CHANNEL_IN_APP,
                'payload' => '{}',
                'status' => Notification::STATUS_SENT,
                'sensitive' => true,
            ]);
        });
    })->toThrow(QueryException::class);

    expect(Notification::query()->count())->toBe(1);

    // A replayed return on the same line is refused (409) with no second
    // notification and no re-audit.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(409);

    expect(Notification::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'refund.notification_created')->count())->toBe(1);
});

it('creates one notification per return event across sequential partial returns', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    // Dispense 5 units at 500 minor each → charge 2500.
    $stock = billingNotifStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = billingNotifPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = billingNotifDispense($this, $org, $facility, $rx);

    // Return 2, then the remaining 3 — two return events.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 2,
        ])
        ->assertStatus(201);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 3,
        ])
        ->assertStatus(201);

    // Exactly two notifications, one per refund request (1000 + 1500).
    expect(Notification::query()->count())->toBe(2)
        ->and(RefundRequest::query()->count())->toBe(2)
        ->and(Notification::query()->pluck('refund_request_id')->unique()->count())->toBe(2)
        ->and(Notification::query()->pluck('payload')->map(fn (array $p): int => $p['amountMinor'])->sort()->values()->all())
        ->toBe([1000, 1500]);

    // The line is fully reversed; stock fully restored; the posted charge
    // never mutated; refunds sum to the charge amount.
    $line = PrescriptionLine::query()->findOrFail($rx['lineIds'][0]);
    $charge = Charge::query()->where('prescription_line_id', $rx['lineIds'][0])->firstOrFail();
    expect($line->status)->toBe(PrescriptionLine::STATUS_REVERSED)
        ->and(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(100);
    expect($charge->status)->toBe(Charge::STATUS_POSTED)
        ->and($charge->amount_minor)->toBe(2500)
        ->and((int) RefundRequest::query()->sum('amount_minor'))->toBe(2500);
});

it('wins the concurrent return race via CAS: a stale returner fabricates no notification', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = billingNotifStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = billingNotifPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = billingNotifDispense($this, $org, $facility, $rx);

    $line = PrescriptionLine::query()->findOrFail($rx['lineIds'][0]);

    // The winning partial advances returned_quantity_minor atomically — the
    // exact CAS the service runs.
    $winner = DB::table('prescription_lines')
        ->where('id', $line->getKey())
        ->where('status', PrescriptionLine::STATUS_DISPENSED)
        ->where('returned_quantity_minor', 0)
        ->update(['returned_quantity_minor' => 2]);

    expect($winner)->toBe(1);

    // A second returner holding the same stale snapshot can never advance
    // the line again — and, having affected zero rows, fabricates no
    // notification, no refund request, no return record.
    $loser = DB::table('prescription_lines')
        ->where('id', $line->getKey())
        ->where('status', PrescriptionLine::STATUS_DISPENSED)
        ->where('returned_quantity_minor', 0)
        ->update(['returned_quantity_minor' => 2]);

    expect($loser)->toBe(0)
        ->and(Notification::query()->count())->toBe(0)
        ->and(RefundRequest::query()->count())->toBe(0);

    // The API layer always re-reads under the row lock: the surviving
    // return of the remaining 3 succeeds and creates exactly ONE
    // notification for its refund request.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$line->getKey().'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 3,
        ])
        ->assertStatus(201);

    expect(Notification::query()->count())->toBe(1)
        ->and(RefundRequest::query()->count())->toBe(1)
        ->and(Notification::query()->sole()->refund_request_id)->toBe(RefundRequest::query()->sole()->getKey());

    // Financial truth after the race: stock restored exactly 3 (98), the
    // line fully reversed, the charge still posted and immutable.
    expect(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(98)
        ->and($line->fresh()->status)->toBe(PrescriptionLine::STATUS_REVERSED)
        ->and(Charge::query()->where('prescription_line_id', $line->getKey())->firstOrFail()->amount_minor)->toBe(2500);
});

it('enforces authorization: only billing viewers read the notification; pharmacy has no billing view', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = billingNotifStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = billingNotifPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = billingNotifDispense($this, $org, $facility, $rx);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(201);

    $refund = RefundRequest::query()->sole();

    // The pharmacist (pharmacy:return) created the return+notification but
    // holds no billing:view → cannot read the billing surface.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->getJson('/api/v1/refund-requests/'.$refund->getKey().'/notification')
        ->assertStatus(403);

    // Unauthenticated → 401.
    $this->flushHeaders();
    $this->getJson('/api/v1/refund-requests/'.$refund->getKey().'/notification')->assertStatus(401);
});

it('enforces cross-tenant isolation: no existence leak, data untouched', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $doctorAUser = Identity::user();

    $stockA = billingNotifStock($orgA, $facilityA, 'PARA', 500, quantity: 100);
    $rxA = billingNotifPrescription($this, $orgA, $facilityA, $doctorAUser, [['medicationId' => $stockA['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacistA = billingNotifDispense($this, $orgA, $facilityA, $rxA);

    $this->withToken(Identity::tokenFor($pharmacistA))
        ->postJson('/api/v1/prescription-lines/'.$rxA['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(201);

    $refundA = RefundRequest::query()->sole();

    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $clerkB = Identity::user();
    billingNotifStaff($orgB, $facilityB, $clerkB, 'Billing Clerk');
    Identity::assign($clerkB, 'billing_clerk', $orgB, $facilityB);

    // Read is invisible (404) — the notification never leaks across tenants.
    $this->withToken(Identity::tokenFor($clerkB))
        ->getJson('/api/v1/refund-requests/'.$refundA->getKey().'/notification')
        ->assertStatus(404);

    expect(Notification::query()->count())->toBe(1)
        ->and(Notification::query()->sole()->tenant_id)->toBe($orgA->getKey())
        ->and(AuditEvent::query()->where('action', 'refund.notification_created')->count())->toBe(1);
});

it('enforces cross-facility isolation within the tenant (404 read)', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org);
    $doctorAUser = Identity::user();

    $stockA = billingNotifStock($org, $facilityA, 'PARA', 500, quantity: 100);
    $rxA = billingNotifPrescription($this, $org, $facilityA, $doctorAUser, [['medicationId' => $stockA['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacistA = billingNotifDispense($this, $org, $facilityA, $rxA);

    $this->withToken(Identity::tokenFor($pharmacistA))
        ->postJson('/api/v1/prescription-lines/'.$rxA['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(201);

    $refundA = RefundRequest::query()->sole();

    $facilityB = Identity::facility($org);
    $clerkB = Identity::user();
    billingNotifStaff($org, $facilityB, $clerkB, 'Billing Clerk');
    Identity::assign($clerkB, 'billing_clerk', $org, $facilityB);

    $this->withToken(Identity::tokenFor($clerkB))
        ->getJson('/api/v1/refund-requests/'.$refundA->getKey().'/notification')
        ->assertStatus(404);

    expect(Notification::query()->count())->toBe(1);
});

it('keeps patient identifiers and free text out of notification and audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = billingNotifStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = billingNotifPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = billingNotifDispense($this, $org, $facility, $rx);

    $patientName = Patient::query()->findOrFail($rx['patientId'])->full_name;

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'reasonNote' => 'Patient '.$patientName.' returned the medicine after an allergic reaction.',
        ])
        ->assertStatus(201);

    // The notification payload is facts only — no names, no free text.
    $notification = Notification::query()->sole();
    $encoded = json_encode($notification->payload);
    expect($encoded)->not->toContain($patientName)
        ->and($encoded)->not->toContain('allergic')
        ->and($encoded)->not->toContain('returned the medicine');

    // Every audit payload (the return + the notification) is equally clean.
    foreach (AuditEvent::query()->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain($patientName)
            ->and($encoded)->not->toContain('allergic')
            ->and($encoded)->not->toContain('returned the medicine');
    }

    // Facts are present in the notification-creation event.
    $event = AuditEvent::query()->where('action', 'refund.notification_created')->firstOrFail();
    expect($event->payload)
        ->toHaveKey('refundRequestId')
        ->toHaveKey('chargeId')
        ->toHaveKey('amountMinor', 2500)
        ->toHaveKey('reasonCode', 'patient_request')
        ->toHaveKey('channel', Notification::CHANNEL_IN_APP);
});

it('rejected returns leave no notification, no refund request, and no notification audit', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = billingNotifStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = billingNotifPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 5]]);
    $pharmacist = billingNotifDispense($this, $org, $facility, $rx);

    // Over-return: refused with zero side effects.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 6,
        ])
        ->assertStatus(422);

    // Zero-quantity: refused at validation.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
            'quantityMinor' => 0,
        ])
        ->assertStatus(422);

    expect(Notification::query()->count())->toBe(0)
        ->and(RefundRequest::query()->count())->toBe(0)
        ->and(PharmacyReturn::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'refund.notification_created')->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'pharmacy.returned')->count())->toBe(0);

    // The stock and the line are untouched.
    expect(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(95)
        ->and(PrescriptionLine::query()->findOrFail($rx['lineIds'][0])->returned_quantity_minor)->toBe(0);
});
