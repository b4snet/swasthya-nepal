<?php

use App\Models\AuditEvent;
use App\Models\Charge;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PharmacyReturn;
use App\Models\PrescriptionLine;
use App\Models\RefundRequest;
use App\Models\Staff;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Phase 3 slice 8 — pharmacy returns & reversals (PRODUCT_REQUIREMENTS §6.7,
 * DATABASE.md §3.30): a pharmacist reverses a dispensed line — reason
 * captured, stock restored through the append-only ledger, line marked
 * reversed, and the refund path opened against the linked posted charge via
 * the existing billing mechanism (requested → approved by billing — the
 * charge itself is never mutated).
 */
beforeEach(function (): void {
    seedIdentity();
});

function pharmacyReturnStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function pharmacyReturnEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
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
function pharmacyReturnStock(Organization $org, Facility $facility, string $code, int $priceMinor, int $quantity = 100): array
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

    // Phase 3 slice 17 — dispensing is batch-selected; the shelf always
    // carries a matching available, unexpired batch (and a return restores
    // to the SAME batch).
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
 * @param  list<array{medicationId: string, quantityMinor: int}>  $lines
 * @return array{prescriptionId: string, lineIds: list<string>, encounterId: string, doctorStaffId: string, patientId: string}
 */
function pharmacyReturnPrescription(TestCase $test, Organization $org, Facility $facility, User $doctorUser, array $lines): array
{
    $doctor = pharmacyReturnStaff($org, $facility, $doctorUser, 'Consultant Physician');
    $encounter = pharmacyReturnEncounter($org, $facility, $doctor);
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
 * The full happy path to a returned line. Returns the line id, pharmacist,
 * the opened refund request, and the stock fixture.
 *
 * @return array{lineId: string, pharmacist: User, refundRequest: ?RefundRequest, stock: array{medication: Medication, item: InventoryItem}, prescriptionId: string}
 */
function pharmacyReturnedLine(TestCase $test, Organization $org, Facility $facility, User $doctorUser, int $quantity = 2, string $reasonCode = 'patient_return', ?string $reasonNote = null): array
{
    $stock = pharmacyReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = pharmacyReturnPrescription($test, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => $quantity]]);

    $pharmacist = Identity::user();
    pharmacyReturnStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $test->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $test->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    $lineId = $rx['lineIds'][0];
    $test->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$lineId.'/return', [
            'reasonCode' => $reasonCode,
            'reasonNote' => $reasonNote,
        ])
        ->assertStatus(201);

    $chargeId = Charge::query()->where('prescription_line_id', $lineId)->value('id');
    $refundRequest = $chargeId !== null
        ? RefundRequest::query()->where('charge_id', $chargeId)->first()
        : null;

    return ['lineId' => $lineId, 'pharmacist' => $pharmacist, 'refundRequest' => $refundRequest, 'stock' => $stock, 'prescriptionId' => $rx['prescriptionId']];
}

it('returns a dispensed line: stock restored, line reversed, ledger + refund path opened', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $outcome = pharmacyReturnedLine($this, $org, $facility, $doctorUser, quantity: 2);

    $line = PrescriptionLine::query()->findOrFail($outcome['lineId']);
    $stock = $outcome['stock'];

    // Stock restored to the shelf (100 − 2 dispensed + 2 returned).
    expect(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(100);

    // Line explicitly reversed (dispensed → reversed).
    expect($line->status)->toBe(PrescriptionLine::STATUS_REVERSED);

    // Ledger: a positive 'return' movement mirroring the negative 'dispense'.
    $returns = InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_RETURN)->get();
    expect($returns)->toHaveCount(1)
        ->and($returns->first()->quantity_delta)->toBe(2)
        ->and($returns->first()->prescription_line_id)->toBe($line->getKey());

    // The immutable reversal record exists with the full quantity.
    $pharmacyReturn = PharmacyReturn::query()->where('prescription_line_id', $line->getKey())->firstOrFail();
    expect($pharmacyReturn->quantity_minor)->toBe(2)
        ->and($pharmacyReturn->reason_code)->toBe('patient_return');

    // The refund path opened against the linked posted charge — requested,
    // NOT approved (the money gate is the billing approver's).
    expect($outcome['refundRequest'])->not->toBeNull()
        ->and($outcome['refundRequest']->status)->toBe(RefundRequest::STATUS_REQUESTED)
        ->and($outcome['refundRequest']->amount_minor)->toBe(1000) // 2 × 500
        ->and($outcome['refundRequest']->reason_code)->toBe('patient_request');

    // The original charge is untouched — posted, immutable.
    $charge = Charge::query()->where('prescription_line_id', $line->getKey())->firstOrFail();
    expect($charge->status)->toBe(Charge::STATUS_POSTED)
        ->and($charge->amount_minor)->toBe(1000);

    // Audit: one pharmacy.returned event with facts.
    expect(AuditEvent::query()->where('action', 'pharmacy.returned')->count())->toBe(1);
});

it('requires a structured reason code for a return', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = pharmacyReturnStock($org, $facility, 'PARA', 500);
    $rx = pharmacyReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    $pharmacist = Identity::user();
    pharmacyReturnStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    // Missing reason → 422; unknown code → 422.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'made_up_reason',
        ])
        ->assertStatus(422);

    // Nothing happened.
    expect(PrescriptionLine::query()->findOrFail($rx['lineIds'][0])->status)->toBe(PrescriptionLine::STATUS_DISPENSED)
        ->and(PharmacyReturn::query()->count())->toBe(0)
        ->and(RefundRequest::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'pharmacy.returned')->count())->toBe(0);
});

it('refuses to return a line that was never dispensed or is already reversed', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = pharmacyReturnStock($org, $facility, 'PARA', 500);
    $rx = pharmacyReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    $pharmacist = Identity::user();
    pharmacyReturnStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);

    // Ordered line (never verified/dispensed) → 409.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Verify + dispense, then return once (201) and again (409).
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(201);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(409);

    // Exactly one reversal, one refund request, stock restored exactly once.
    expect(PharmacyReturn::query()->where('prescription_line_id', $rx['lineIds'][0])->count())->toBe(1)
        ->and(RefundRequest::query()->count())->toBe(1)
        ->and(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(100)
        ->and(InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_RETURN)->count())->toBe(1);
});

it('wins the concurrent return race exactly once (status CAS + unique backstop)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = pharmacyReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = pharmacyReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 2]]);

    $pharmacist = Identity::user();
    pharmacyReturnStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    $line = PrescriptionLine::query()->findOrFail($rx['lineIds'][0]);
    expect($line->status)->toBe(PrescriptionLine::STATUS_DISPENSED);

    // The winning reversal advances the line atomically — the exact status
    // CAS the service runs: WHERE status = dispensed, then flip to reversed.
    $winner = DB::table('prescription_lines')
        ->where('id', $rx['lineIds'][0])
        ->where('status', PrescriptionLine::STATUS_DISPENSED)
        ->update(['status' => PrescriptionLine::STATUS_REVERSED]);

    expect($winner)->toBe(1);

    // A second returner holding the SAME stale snapshot can never advance
    // the line again: the CAS affects zero rows.
    $loser = DB::table('prescription_lines')
        ->where('id', $rx['lineIds'][0])
        ->where('status', PrescriptionLine::STATUS_DISPENSED)
        ->update(['status' => PrescriptionLine::STATUS_REVERSED]);

    expect($loser)->toBe(0);

    // The losing HTTP request fails safely with CONFLICT.
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // The line is now reversed — a real (service-driven) return would find it
    // so and refuse. The winner here was a direct DB CAS (this test bypasses
    // the controller), so no reversal record/ledger/audit exists; the losing
    // HTTP request produced none either, and stock is untouched by the loser
    // (98 = 100 − 2 dispensed — only the service restores it).
    expect(PrescriptionLine::query()->findOrFail($rx['lineIds'][0])->status)->toBe(PrescriptionLine::STATUS_REVERSED)
        ->and(PharmacyReturn::query()->count())->toBe(0)
        ->and(RefundRequest::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_RETURN)->count())->toBe(0)
        ->and(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(98)
        ->and(AuditEvent::query()->where('action', 'pharmacy.returned')->count())->toBe(0);
});

it('refuses a return when no posted charge is linked to the line', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = pharmacyReturnStock($org, $facility, 'PARA', 500, quantity: 100);
    $rx = pharmacyReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    $pharmacist = Identity::user();
    pharmacyReturnStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    // Void the linked charge — no posted charge remains to reverse.
    $charge = Charge::query()->where('prescription_line_id', $rx['lineIds'][0])->firstOrFail();
    $charge->update(['status' => Charge::STATUS_VOIDED, 'void_reason' => 'Posted in error']);

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(409);

    // Nothing mutated: stock untouched, line still dispensed, no reversal,
    // no refund request, no success audit.
    expect(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(99)
        ->and(PrescriptionLine::query()->findOrFail($rx['lineIds'][0])->status)->toBe(PrescriptionLine::STATUS_DISPENSED)
        ->and(PharmacyReturn::query()->count())->toBe(0)
        ->and(RefundRequest::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_RETURN)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'pharmacy.returned')->count())->toBe(0);
});

it('opens the refund path for the billing approver (integration with slice 5)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $outcome = pharmacyReturnedLine($this, $org, $facility, $doctorUser);

    // The org admin (billing:refund-approve) approves the opened request —
    // the immutable reversing entry — while the charge stays posted.
    $approver = Identity::user();
    Identity::assign($approver, 'org_admin', $org, $facility);
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$outcome['refundRequest']->getKey().'/approve')
        ->assertOk()
        ->assertJsonPath('data.status', RefundRequest::STATUS_APPROVED);

    $charge = Charge::query()->where('prescription_line_id', $outcome['lineId'])->firstOrFail();
    expect($charge->status)->toBe(Charge::STATUS_POSTED)
        ->and(AuditEvent::query()->where('action', 'refund.approved')->count())->toBe(1);
});

it('enforces RBAC: only the pharmacist role can return a line', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = pharmacyReturnStock($org, $facility, 'PARA', 500);
    $rx = pharmacyReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    $pharmacist = Identity::user();
    pharmacyReturnStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    // The doctor (pharmacy:view only) cannot return; nor can a nurse or the
    // receptionist — pharmacy:return is the pharmacist's clinical act.
    foreach (['doctor' => $doctorUser, 'nurse' => Identity::user(), 'receptionist' => Identity::user()] as $role => $user) {
        if ($role !== 'doctor') {
            Identity::assign($user, $role, $org, $facility);
        }
        $this->withToken(Identity::tokenFor($user))
            ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
                'reasonCode' => 'patient_return',
            ])
            ->assertStatus(403);
    }

    // The line is untouched.
    expect(PrescriptionLine::query()->findOrFail($rx['lineIds'][0])->status)->toBe(PrescriptionLine::STATUS_DISPENSED)
        ->and(PharmacyReturn::query()->count())->toBe(0);
});

it('requires authentication (401)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = pharmacyReturnStock($org, $facility, 'PARA', 500);
    $rx = pharmacyReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    // The doctor's token persists from the prescription call — flush it so
    // the request is genuinely unauthenticated.
    $this->flushHeaders();
    $this->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
        'reasonCode' => 'patient_return',
    ])->assertStatus(401);
});

it('enforces cross-tenant isolation for the whole return surface', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $doctorA = Identity::user();
    $stock = pharmacyReturnStock($orgA, $facilityA, 'PARA', 500, quantity: 100);
    $rx = pharmacyReturnPrescription($this, $orgA, $facilityA, $doctorA, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    $pharmacistA = Identity::user();
    pharmacyReturnStaff($orgA, $facilityA, $pharmacistA, 'Pharmacist');
    Identity::assign($pharmacistA, 'pharmacist', $orgA, $facilityA);
    $this->withToken(Identity::tokenFor($pharmacistA))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $this->withToken(Identity::tokenFor($pharmacistA))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    // Tenant-B pharmacist attacking tenant A's line: read of the
    // prescription hides existence (404); the return write is denied (403).
    $pharmacistB = Identity::user();
    pharmacyReturnStaff($orgB, $facilityB, $pharmacistB, 'Pharmacist');
    Identity::assign($pharmacistB, 'pharmacist', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($pharmacistB))
        ->getJson('/api/v1/prescriptions/'.$rx['prescriptionId'])
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($pharmacistB))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(403);

    // Tenant A's data is untouched — no reversal, no refund request, stock
    // intact, line still dispensed, no audit.
    expect(PharmacyReturn::query()->count())->toBe(0)
        ->and(RefundRequest::query()->count())->toBe(0)
        ->and(InventoryItem::query()->findOrFail($stock['item']->getKey())->quantity_on_hand)->toBe(99)
        ->and(PrescriptionLine::query()->findOrFail($rx['lineIds'][0])->status)->toBe(PrescriptionLine::STATUS_DISPENSED)
        ->and(AuditEvent::query()->where('action', 'pharmacy.returned')->count())->toBe(0);
});

it('enforces cross-facility isolation within a tenant', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org);
    $facilityB = Identity::facility($org);

    $doctorA = Identity::user();
    $stock = pharmacyReturnStock($org, $facilityA, 'PARA', 500, quantity: 100);
    $rx = pharmacyReturnPrescription($this, $org, $facilityA, $doctorA, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    $pharmacistA = Identity::user();
    pharmacyReturnStaff($org, $facilityA, $pharmacistA, 'Pharmacist');
    Identity::assign($pharmacistA, 'pharmacist', $org, $facilityA);
    $this->withToken(Identity::tokenFor($pharmacistA))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $this->withToken(Identity::tokenFor($pharmacistA))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    // Facility-B pharmacist cannot reach facility A's line (404 read, 403 write).
    $pharmacistB = Identity::user();
    pharmacyReturnStaff($org, $facilityB, $pharmacistB, 'Pharmacist');
    Identity::assign($pharmacistB, 'pharmacist', $org, $facilityB);

    $this->withToken(Identity::tokenFor($pharmacistB))
        ->getJson('/api/v1/prescriptions/'.$rx['prescriptionId'])
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($pharmacistB))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'patient_return',
        ])
        ->assertStatus(403);

    expect(PrescriptionLine::query()->findOrFail($rx['lineIds'][0])->status)->toBe(PrescriptionLine::STATUS_DISPENSED);
});

it('keeps patient identifiers, medication names, and reason text out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();

    $stock = pharmacyReturnStock($org, $facility, 'PARA', 500);
    $rx = pharmacyReturnPrescription($this, $org, $facility, $doctorUser, [['medicationId' => $stock['medication']->getKey(), 'quantityMinor' => 1]]);

    $patient = Patient::query()->findOrFail($rx['patientId']);
    $patientName = $patient->full_name;

    $pharmacist = Identity::user();
    pharmacyReturnStaff($org, $facility, $pharmacist, 'Pharmacist');
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/verify')
        ->assertOk();
    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescriptions/'.$rx['prescriptionId'].'/dispense')
        ->assertOk();

    $this->withToken(Identity::tokenFor($pharmacist))
        ->postJson('/api/v1/prescription-lines/'.$rx['lineIds'][0].'/return', [
            'reasonCode' => 'adverse_reaction',
            'reasonNote' => 'Patient '.$patientName.' reported nausea after taking Paracetamol 500mg.',
        ])
        ->assertStatus(201);

    // No patient name, no medication name, no reason text in ANY audit payload.
    foreach (AuditEvent::query()->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain($patientName)
            ->and($encoded)->not->toContain('Paracetamol')
            ->and($encoded)->not->toContain('500mg')
            ->and($encoded)->not->toContain('nausea');
    }

    // Facts are present: the structured reason code, the charge, the quantity.
    $returned = AuditEvent::query()->where('action', 'pharmacy.returned')->firstOrFail();
    expect($returned->payload)
        ->toHaveKey('reasonCode', 'adverse_reaction')
        ->toHaveKey('quantityMinor', 1)
        ->toHaveKey('prescriptionLineId', $rx['lineIds'][0]);
});
