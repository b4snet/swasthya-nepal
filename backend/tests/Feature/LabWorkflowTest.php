<?php

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Phase 3 slice 2 — the laboratory/radiology order lifecycle
 * (PRODUCT_REQUIREMENTS §6.8): doctor orders from an open encounter →
 * sample collected → processing → results entered → verified by a
 * DIFFERENT staff member → final report released (immutable). Every
 * transition is compare-and-swap on (status, lock_version); entry ≠
 * verification is enforced by permission split AND a different-staff guard.
 */
beforeEach(function (): void {
    seedIdentity();
});

function labDoctor(Organization $org, Facility $facility, User $user): Staff
{
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $user->getKey(),
        'designation' => 'Consultant Physician',
        'status' => 'active',
    ]);
}

function labStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function labEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
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

function labCatalog(Organization $org, Facility $facility, string $code, string $name, string $category = 'laboratory', ?string $range = '4.0–11.0'): LabTest
{
    return LabTest::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => $code,
        'name' => $name,
        'category' => $category,
        'reference_range' => $range,
        'status' => LabTest::STATUS_ACTIVE,
    ]);
}

/**
 * Drive an order from creation to results_entered through the real API.
 *
 * @return array{orderId: string, itemIds: list<string>}
 */
function labOrderWithResults(TestCase $test, array $setup): array
{
    [$org, $facility, $doctorUser, $techUser, $tests] = $setup;

    $doctor = labDoctor($org, $facility, $doctorUser);
    $encounter = labEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    $response = $test->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => collect($tests)->map(fn (LabTest $t): string => $t->getKey())->all(),
        ])
        ->assertCreated();

    $orderId = $response->json('data.id');
    $itemIds = collect($response->json('data.items'))->pluck('id')->all();

    $tech = labStaff($org, $facility, $techUser, 'Lab Technician');

    $test->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/collect')
        ->assertOk();

    $test->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/process')
        ->assertOk();

    $test->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/results', [
            'results' => collect($itemIds)->map(fn (string $itemId): array => [
                'itemId' => $itemId,
                'resultValue' => '12.5',
                'resultUnit' => 'mg/dL',
            ])->all(),
        ])
        ->assertOk();

    return ['orderId' => $orderId, 'itemIds' => $itemIds, 'techStaffId' => $tech->getKey(), 'encounterId' => $encounter->getKey()];
}

it('creates a lab order from an open encounter with reference-range snapshots and audits it', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labDoctor($org, $facility, $doctorUser);
    $encounter = labEncounter($org, $facility, $doctor);
    $cbc = labCatalog($org, $facility, 'CBC', 'Complete Blood Count', 'laboratory', '4.0–11.0');
    $glucose = labCatalog($org, $facility, 'GLU', 'Blood Glucose', 'biochemistry', '70–99');
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => [$cbc->getKey(), $glucose->getKey()],
            'priority' => 'urgent',
            'clinicalIndication' => 'Fever of unknown origin',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'ordered')
        ->assertJsonPath('data.priority', 'urgent')
        ->assertJsonPath('data.patientId', $encounter->patient_id)
        ->assertJsonPath('data.encounterId', $encounter->getKey())
        ->assertJsonPath('data.orderedByStaffId', $doctor->getKey())
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.referenceRange', '4.0–11.0')
        ->assertJsonPath('data.items.1.referenceRange', '70–99');

    expect(LabOrder::query()->count())->toBe(1)
        ->and(LabOrder::query()->first()->items()->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'lab_order.created')->where('resource_id', LabOrder::query()->first()->getKey())->exists())->toBeTrue();

    $event = AuditEvent::query()->where('action', 'lab_order.created')->firstOrFail();
    expect($event->payload['testCount'])->toBe(2)
        ->and($event->payload['patientId'])->toBe($encounter->patient_id)
        ->and($event->facility_id)->toBe($facility->getKey());
});

it('rejects malformed lab order payloads with structured validation errors', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labDoctor($org, $facility, $doctorUser);
    $encounter = labEncounter($org, $facility, $doctor);
    $cbc = labCatalog($org, $facility, 'CBC', 'Complete Blood Count');
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', ['testIds' => []])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // Duplicate testIds are refused (one item per test per order).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => [$cbc->getKey(), $cbc->getKey()],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // Invalid priority and an unknown field are refused.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => [$cbc->getKey()],
            'priority' => 'asap',
            'sneakyField' => 'x',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect(LabOrder::query()->count())->toBe(0);
});

it('refuses to order investigations on a signed encounter', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labDoctor($org, $facility, $doctorUser);
    $encounter = labEncounter($org, $facility, $doctor);
    $encounter->update(['status' => Encounter::STATUS_SIGNED]);
    $cbc = labCatalog($org, $facility, 'CBC', 'Complete Blood Count');
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', ['testIds' => [$cbc->getKey()]])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('denies ordering by non-provider doctors and by roles without lab:order', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labDoctor($org, $facility, $doctorUser);
    $encounter = labEncounter($org, $facility, $doctor);
    $cbc = labCatalog($org, $facility, 'CBC', 'Complete Blood Count');
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    // A nurse has lab:view but NOT lab:order → 403.
    $nurseUser = Identity::user();
    Identity::assign($nurseUser, 'nurse', $org, $facility);

    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', ['testIds' => [$cbc->getKey()]])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // A different doctor (with lab:order) is NOT the encounter provider → 403.
    $otherUser = Identity::user();
    labDoctor($org, $facility, $otherUser);
    Identity::assign($otherUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($otherUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', ['testIds' => [$cbc->getKey()]])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    expect(LabOrder::query()->count())->toBe(0);
});

it('rejects unknown, inactive, and cross-tenant tests at order time', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $doctorUser = Identity::user();
    $doctor = labDoctor($orgA, $facilityA, $doctorUser);
    $encounter = labEncounter($orgA, $facilityA, $doctor);
    $active = labCatalog($orgA, $facilityA, 'CBC', 'Complete Blood Count');
    $inactive = labCatalog($orgA, $facilityA, 'OLD', 'Retired Test');
    $inactive->update(['status' => LabTest::STATUS_INACTIVE]);

    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $foreign = labCatalog($orgB, $facilityB, 'FGN', 'Foreign Test');

    Identity::assign($doctorUser, 'doctor', $orgA, $facilityA);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => ['00000000-0000-7000-8000-000000000000'],
        ])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => [$inactive->getKey()],
        ])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => [$foreign->getKey()],
        ])
        ->assertStatus(422);

    // A valid one still works.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => [$active->getKey()],
        ])
        ->assertCreated();

    expect(LabOrder::query()->count())->toBe(1);
});

it('hides another tenant\'s lab orders (read 404, write 403)', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $doctorB = labDoctor($orgB, $facilityB, Identity::user());
    $encounterB = labEncounter($orgB, $facilityB, $doctorB);
    $cbcB = labCatalog($orgB, $facilityB, 'CBC', 'CBC B');
    $orderB = LabOrder::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $encounterB->patient_id,
        'encounter_id' => $encounterB->getKey(),
        'ordered_by_staff_id' => $doctorB->getKey(),
        'status' => LabOrder::STATUS_ORDERED,
    ]);
    LabOrderItem::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'lab_order_id' => $orderB->getKey(),
        'lab_test_id' => $cbcB->getKey(),
    ]);

    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/lab-orders/'.$orderB->getKey())
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/encounters/'.$encounterB->getKey().'/lab-orders')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/lab-orders/'.$orderB->getKey().'/collect')
        ->assertStatus(403);
});

it('collects the sample and refuses invalid collection transitions', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labDoctor($org, $facility, $doctorUser);
    $encounter = labEncounter($org, $facility, $doctor);
    $cbc = labCatalog($org, $facility, 'CBC', 'Complete Blood Count');
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $orderId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', ['testIds' => [$cbc->getKey()]])
        ->json('data.id');

    $techUser = Identity::user();
    $tech = labStaff($org, $facility, $techUser, 'Lab Technician');
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/collect')
        ->assertOk()
        ->assertJsonPath('data.status', 'collected')
        ->assertJsonPath('data.collectedByStaffId', $tech->getKey());

    expect(AuditEvent::query()->where('action', 'lab_order.collected')->exists())->toBeTrue();

    // Double collection is refused.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/collect')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('processes the order and refuses invalid processing transitions', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labDoctor($org, $facility, $doctorUser);
    $encounter = labEncounter($org, $facility, $doctor);
    $cbc = labCatalog($org, $facility, 'CBC', 'Complete Blood Count');
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $orderId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', ['testIds' => [$cbc->getKey()]])
        ->json('data.id');

    $techUser = Identity::user();
    labStaff($org, $facility, $techUser, 'Lab Technician');
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    // Processing an uncollected order is refused.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/process')
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/collect')
        ->assertOk();

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/process')
        ->assertOk()
        ->assertJsonPath('data.status', 'processing');

    // Double processing is refused.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/process')
        ->assertStatus(409);
});

it('enters results once for every item and refuses partial or foreign entries', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = labDoctor($org, $facility, $doctorUser);
    $encounter = labEncounter($org, $facility, $doctor);
    $cbc = labCatalog($org, $facility, 'CBC', 'Complete Blood Count', 'laboratory', '4.0–11.0');
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $orderId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', ['testIds' => [$cbc->getKey()]])
        ->json('data.id');
    $itemId = $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/lab-orders/'.$orderId)
        ->json('data.items.0.id');

    $techUser = Identity::user();
    $tech = labStaff($org, $facility, $techUser, 'Lab Technician');
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    // Results before processing are refused.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/results', [
            'results' => [['itemId' => $itemId, 'resultValue' => '5.0']],
        ])
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/collect')
        ->assertOk();
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/process')
        ->assertOk();

    // Empty results → 422.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/results', ['results' => []])
        ->assertStatus(422);

    // An item that does not belong to this order → 422.
    $foreignOrder = LabOrder::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->getKey(),
        'ordered_by_staff_id' => $doctor->getKey(),
    ]);
    $foreignItem = LabOrderItem::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'lab_order_id' => $foreignOrder->getKey(),
        'lab_test_id' => $cbc->getKey(),
    ]);

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/results', [
            'results' => [['itemId' => $foreignItem->getKey(), 'resultValue' => '5.0']],
        ])
        ->assertStatus(422);

    // A valid complete entry moves the order to results_entered.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/results', [
            'results' => [['itemId' => $itemId, 'resultValue' => '7.2', 'resultUnit' => 'x10^9/L']],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'results_entered')
        ->assertJsonPath('data.items.0.resultValue', '7.2')
        ->assertJsonPath('data.items.0.enteredByStaffId', $tech->getKey());
});

it('enforces entry ≠ verification (same staff denied, distinct permissions)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();
    $supervisorUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [labCatalog($org, $facility, 'CBC', 'CBC')]];
    $chain = labOrderWithResults($this, $setup);

    // The entry staff (lab_technician) lacks lab:verify → 403 at the gate.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/verify')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // A supervisor whose staff profile IS the enterer is refused by the
    // different-staff guard (entry ≠ verification): retag the items as
    // entered by the supervisor's staff id, then verify as that supervisor.
    $supervisor = labStaff($org, $facility, $supervisorUser, 'Lab Supervisor');
    Identity::assign($supervisorUser, 'lab_supervisor', $org, $facility);
    LabOrderItem::query()
        ->whereIn('id', $chain['itemIds'])
        ->update(['entered_by_staff_id' => $supervisor->getKey()]);

    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/verify')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // A DIFFERENT supervisor (never the enterer) verifies successfully.
    $otherSupervisorUser = Identity::user();
    $otherSupervisor = labStaff($org, $facility, $otherSupervisorUser, 'Lab Supervisor');
    Identity::assign($otherSupervisorUser, 'lab_supervisor', $org, $facility);

    $this->withToken(Identity::tokenFor($otherSupervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/verify')
        ->assertOk()
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.verifiedByStaffId', $otherSupervisor->getKey());

    expect(AuditEvent::query()->where('action', 'lab_order.verified')->exists())->toBeTrue();
});

it('refuses verification in wrong states and double verification', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();
    $supervisorUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [labCatalog($org, $facility, 'CBC', 'CBC')]];
    $chain = labOrderWithResults($this, $setup);

    // Fresh order created through the API has status 'ordered' — verify
    // before collection/processing is refused with 409. The provider is the
    // SAME doctor staff profile from the chain (one active staff per user).
    $doctorStaff = Staff::query()->where('user_id', $doctorUser->getKey())->firstOrFail();
    $fresh = LabOrder::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()])->getKey(),
        'encounter_id' => $chain['encounterId'],
        'ordered_by_staff_id' => $doctorStaff->getKey(),
    ]);

    $supervisor = labStaff($org, $facility, $supervisorUser, 'Lab Supervisor');
    Identity::assign($supervisorUser, 'lab_supervisor', $org, $facility);

    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/lab-orders/'.$fresh->getKey().'/verify')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Double verification after a successful verify is refused.
    $otherSupervisorUser = Identity::user();
    labStaff($org, $facility, $otherSupervisorUser, 'Lab Supervisor');
    Identity::assign($otherSupervisorUser, 'lab_supervisor', $org, $facility);

    $this->withToken(Identity::tokenFor($otherSupervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/verify')
        ->assertOk();

    $this->withToken(Identity::tokenFor($otherSupervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/verify')
        ->assertStatus(409);
});

it('releases the final report and makes the order immutable', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();
    $supervisorUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [labCatalog($org, $facility, 'CBC', 'CBC')]];
    $chain = labOrderWithResults($this, $setup);

    $supervisor = labStaff($org, $facility, $supervisorUser, 'Lab Supervisor');
    Identity::assign($supervisorUser, 'lab_supervisor', $org, $facility);
    $otherSupervisorUser = Identity::user();
    labStaff($org, $facility, $otherSupervisorUser, 'Lab Supervisor');
    Identity::assign($otherSupervisorUser, 'lab_supervisor', $org, $facility);

    // Verify with a different supervisor than the enterer.
    $this->withToken(Identity::tokenFor($otherSupervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/verify')
        ->assertOk();

    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/report')
        ->assertOk()
        ->assertJsonPath('data.status', 'reported')
        ->assertJsonPath('data.reportedByStaffId', $supervisor->getKey());

    expect(AuditEvent::query()->where('action', 'lab_order.reported')->exists())->toBeTrue();

    // Immutable: no further transition is possible.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/collect')
        ->assertStatus(409);
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/results', [
            'results' => [['itemId' => $chain['itemIds'][0], 'resultValue' => '999']],
        ])
        ->assertStatus(409);
    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/verify')
        ->assertStatus(409);
    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/report')
        ->assertStatus(409);

    // The record is still readable.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/lab-orders/'.$chain['orderId'])
        ->assertOk()
        ->assertJsonPath('data.status', 'reported');
});

it('exposes the care-team view and the released-results patient view', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();
    $supervisorUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [labCatalog($org, $facility, 'CBC', 'CBC')]];
    $chain = labOrderWithResults($this, $setup);

    // Care-team view: the ordering doctor sees the order with status + items.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/encounters/'.$chain['encounterId'].'/lab-orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'results_entered');

    // Patient surface: NOT released yet → excluded.
    $patientId = LabOrder::query()->findOrFail($chain['orderId'])->patient_id;
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/patients/'.$patientId.'/lab-orders')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Release the report → now visible on the patient surface.
    $otherSupervisorUser = Identity::user();
    labStaff($org, $facility, $otherSupervisorUser, 'Lab Supervisor');
    Identity::assign($otherSupervisorUser, 'lab_supervisor', $org, $facility);
    $this->withToken(Identity::tokenFor($otherSupervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/verify')
        ->assertOk();
    $supervisorUser2 = Identity::user();
    $supervisor2 = labStaff($org, $facility, $supervisorUser2, 'Lab Supervisor');
    Identity::assign($supervisorUser2, 'lab_supervisor', $org, $facility);
    $this->withToken(Identity::tokenFor($supervisorUser2))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/report')
        ->assertOk();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/patients/'.$patientId.'/lab-orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'reported')
        ->assertJsonPath('data.0.items.0.resultValue', '12.5');
});

it('blocks unauthorized and cross-patient results access', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [labCatalog($org, $facility, 'CBC', 'CBC')]];
    $chain = labOrderWithResults($this, $setup);

    $patientAId = LabOrder::query()->findOrFail($chain['orderId'])->patient_id;

    // A billing clerk has neither lab:view nor patient:view → 403.
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    $this->withToken(Identity::tokenFor($clerk))
        ->getJson('/api/v1/patients/'.$patientAId.'/lab-orders')
        ->assertStatus(403);

    // Another patient's scope never returns patient A's orders (the bound
    // patient is the only scope — no cross-patient leakage).
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/patients/'.$patientB->getKey().'/lab-orders')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Patient A's own scope works.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/patients/'.$patientAId.'/lab-orders')
        ->assertOk();
});

it('enforces facility scoping on lab orders (404 read, 403 write)', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org);
    $facilityB = Identity::facility($org);

    $doctorA = labDoctor($org, $facilityA, Identity::user());
    $encounterA = labEncounter($org, $facilityA, $doctorA);
    $cbcA = labCatalog($org, $facilityA, 'CBC', 'CBC A');
    $orderA = LabOrder::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facilityA->getKey(),
        'patient_id' => $encounterA->patient_id,
        'encounter_id' => $encounterA->getKey(),
        'ordered_by_staff_id' => $doctorA->getKey(),
    ]);

    // A facility-B lab technician (lab:specimen etc., facility-scoped) sees
    // nothing of facility A's order.
    $techB = Identity::user();
    labStaff($org, $facilityB, $techB, 'Lab Technician');
    Identity::assign($techB, 'lab_technician', $org, $facilityB);

    $this->withToken(Identity::tokenFor($techB))
        ->getJson('/api/v1/lab-orders/'.$orderA->getKey())
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($techB))
        ->postJson('/api/v1/lab-orders/'.$orderA->getKey().'/collect')
        ->assertStatus(403);

    // The same tenant's org-scoped admin sees it.
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/lab-orders/'.$orderA->getKey())
        ->assertOk();
});

it('wins the concurrent verification race via the compare-and-swap', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [labCatalog($org, $facility, 'CBC', 'CBC')]];
    $chain = labOrderWithResults($this, $setup);

    $order = LabOrder::query()->findOrFail($chain['orderId']);
    expect($order->status)->toBe(LabOrder::STATUS_RESULTS_ENTERED);

    // The winning verifier commits atomically — the exact compare-and-swap
    // the controller runs: WHERE status AND lock_version match, then advance.
    $winner = DB::table('lab_orders')
        ->where('id', $order->getKey())
        ->where('status', LabOrder::STATUS_RESULTS_ENTERED)
        ->where('lock_version', $order->lock_version)
        ->update(['status' => LabOrder::STATUS_VERIFIED, 'lock_version' => $order->lock_version + 1]);

    expect($winner)->toBe(1);

    // A second verifier holding the SAME stale snapshot can never advance
    // the order again: the CAS affects zero rows (double-advance impossible).
    $loser = DB::table('lab_orders')
        ->where('id', $order->getKey())
        ->where('status', LabOrder::STATUS_RESULTS_ENTERED)
        ->where('lock_version', $order->lock_version)
        ->update(['status' => LabOrder::STATUS_REPORTED, 'lock_version' => $order->lock_version + 1]);

    expect($loser)->toBe(0);

    // And the losing HTTP request — arriving after the winner committed —
    // fails safely with CONFLICT and changes nothing.
    $supervisorUser = Identity::user();
    labStaff($org, $facility, $supervisorUser, 'Lab Supervisor');
    Identity::assign($supervisorUser, 'lab_supervisor', $org, $facility);

    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/verify')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(LabOrder::query()->findOrFail($chain['orderId'])->status)->toBe(LabOrder::STATUS_VERIFIED);
});

it('keeps result values out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [labCatalog($org, $facility, 'CBC', 'CBC')]];
    $chain = labOrderWithResults($this, $setup);

    // Enter a distinctive result value, then verify + report.
    $otherSupervisorUser = Identity::user();
    labStaff($org, $facility, $otherSupervisorUser, 'Lab Supervisor');
    Identity::assign($otherSupervisorUser, 'lab_supervisor', $org, $facility);
    $this->withToken(Identity::tokenFor($otherSupervisorUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/verify')
        ->assertOk();
    $reporterUser = Identity::user();
    labStaff($org, $facility, $reporterUser, 'Lab Supervisor');
    Identity::assign($reporterUser, 'lab_supervisor', $org, $facility);
    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$chain['orderId'].'/report')
        ->assertOk();

    foreach (AuditEvent::query()->get() as $event) {
        expect(json_encode($event->payload))->not->toContain('12.5')
            ->and(json_encode($event->payload))->not->toContain('mg/dL');
    }
});

it('lists and creates catalog tests with org scoping', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    labCatalog($orgA, $facilityA, 'CBC', 'Complete Blood Count');
    labCatalog($orgA, $facilityA, 'GLU', 'Blood Glucose');
    labCatalog($orgB, $facilityB, 'XRAY', 'Chest X-Ray', 'radiology');

    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/organizations/'.$orgA->getKey().'/lab-tests')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Out-of-scope organization → 404 (existence never leaked).
    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/organizations/'.$orgB->getKey().'/lab-tests')
        ->assertStatus(404);

    // Catalog creation by an authorized admin.
    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/lab-tests', [
            'facilityId' => $facilityA->getKey(),
            'code' => 'UA',
            'name' => 'Urine Analysis',
            'category' => 'laboratory',
            'sampleType' => 'urine',
            'referenceRange' => '1.005–1.030',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'UA')
        ->assertJsonPath('data.sampleType', 'urine');

    // A nurse cannot create catalog entries (no lab:manage).
    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $orgA, $facilityA);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/lab-tests', [
            'facilityId' => $facilityA->getKey(),
            'code' => 'HACK',
            'name' => 'Nurse Hack',
        ])
        ->assertStatus(403);

    expect(AuditEvent::query()->where('action', 'lab_test.created')->exists())->toBeTrue();
});

it('rejects cross-tenant catalog writes', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);

    // Proposing a facility from another tenant is refused.
    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/lab-tests', [
            'facilityId' => $facilityB->getKey(),
            'code' => 'X',
            'name' => 'Cross Tenant Test',
        ])
        ->assertStatus(403);

    // An unknown facility id is a 404.
    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/lab-tests', [
            'facilityId' => '00000000-0000-7000-8000-000000000000',
            'code' => 'Y',
            'name' => 'Ghost Test',
        ])
        ->assertStatus(404);

    expect(LabTest::query()->count())->toBe(0);
});
