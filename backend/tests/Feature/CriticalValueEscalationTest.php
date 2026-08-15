<?php

use App\Models\AuditEvent;
use App\Models\CriticalValueEvent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\LabTest;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Phase 3 slice 7 — Laboratory critical-value escalation
 * (PRODUCT_REQUIREMENTS §6.8 workflow 6, CLINICAL_SAFETY §7, MASTER_RULES
 * §11.3): a critical result flagged at entry triggers a critical_value_event
 * targeted at the ordering clinician; the clinician acknowledges it
 * (who/when recorded) or a supervisor escalates it — fail loudly, never
 * silently.
 *
 * Lifecycle: triggered → acknowledged (target clinician only, lab:acknowledge)
 * and triggered → escalated (supervisor, lab:escalate, never the target);
 * escalated → acknowledged still closes the loop. Transitions are CAS on
 * (status, lock_version); one OPEN event per item is the DB backstop.
 *
 * The event references the flagged item but stores no result value; audit
 * payloads carry facts (ids, staff, timestamps) — never the value, never
 * patient names.
 */
beforeEach(function (): void {
    seedIdentity();
});

function criticalDoctor(Organization $org, Facility $facility, User $user): Staff
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

function criticalStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function criticalEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
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

function criticalTest(Organization $org, Facility $facility, string $code, string $name): LabTest
{
    return LabTest::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => $code,
        'name' => $name,
        'category' => 'laboratory',
        'reference_range' => '4.0–11.0',
        'status' => LabTest::STATUS_ACTIVE,
    ]);
}

/**
 * Drive an order from creation to results_entered with ONE item flagged
 * critical. Returns the order id, the flagged item id, and the staff ids.
 *
 * @return array{orderId: string, criticalItemId: string, techStaffId: string, doctorStaffId: string, encounterId: string, patientId: string, otherItemId: string}
 */
function criticalOrderWithResults(TestCase $test, array $setup): array
{
    [$org, $facility, $doctorUser, $techUser, $tests] = $setup;

    $doctor = criticalDoctor($org, $facility, $doctorUser);
    $encounter = criticalEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    $response = $test->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => collect($tests)->map(fn (LabTest $t): string => $t->getKey())->all(),
        ])
        ->assertCreated();

    $orderId = $response->json('data.id');
    $itemIds = collect($response->json('data.items'))->pluck('id')->all();
    $criticalItemId = $itemIds[0];
    $otherItemId = $itemIds[1] ?? $itemIds[0];

    $tech = criticalStaff($org, $facility, $techUser, 'Lab Technician');

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
                'resultValue' => $itemId === $criticalItemId ? '18.9' : '12.5',
                'resultUnit' => 'mg/dL',
                'isCritical' => $itemId === $criticalItemId,
            ])->all(),
        ])
        ->assertOk();

    return [
        'orderId' => $orderId,
        'criticalItemId' => $criticalItemId,
        'otherItemId' => $otherItemId,
        'techStaffId' => $tech->getKey(),
        'doctorStaffId' => $doctor->getKey(),
        'encounterId' => $encounter->getKey(),
        'patientId' => $encounter->patient_id,
    ];
}

it('triggers a critical-value event when a result is flagged critical at entry', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [criticalTest($org, $facility, 'CBC', 'CBC'), criticalTest($org, $facility, 'GLU', 'Glucose')]];
    $chain = criticalOrderWithResults($this, $setup);

    // Exactly one open event for the flagged item; the other item has none.
    $event = CriticalValueEvent::query()->where('lab_order_item_id', $chain['criticalItemId'])->firstOrFail();
    expect($event->status)->toBe(CriticalValueEvent::STATUS_TRIGGERED)
        ->and($event->target_staff_id)->toBe($chain['doctorStaffId'])
        ->and($event->detected_by_staff_id)->toBe($chain['techStaffId'])
        ->and($event->patient_id)->toBe($chain['patientId'])
        ->and($event->encounter_id)->toBe($chain['encounterId'])
        ->and($event->lock_version)->toBe(0);

    expect(CriticalValueEvent::query()->where('lab_order_item_id', $chain['otherItemId'])->doesntExist())->toBeTrue();

    // The event is on the escalation queue.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/critical-value-events')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $event->getKey())
        ->assertJsonPath('data.0.status', 'triggered')
        ->assertJsonPath('data.0.targetStaffId', $chain['doctorStaffId'])
        ->assertJsonPath('data.0.itemId', $chain['criticalItemId']);

    // The audit trail recorded the trigger with facts only.
    $trigger = AuditEvent::query()->where('action', 'critical_value.triggered')->firstOrFail();
    expect($trigger->payload)
        ->toHaveKey('encounterId', $chain['encounterId'])
        ->toHaveKey('itemId', $chain['criticalItemId'])
        ->toHaveKey('targetStaffId', $chain['doctorStaffId']);
});

it('acknowledges the critical value as the ordering clinician (who/when recorded)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [criticalTest($org, $facility, 'CBC', 'CBC')]];
    $chain = criticalOrderWithResults($this, $setup);

    $event = CriticalValueEvent::query()->where('lab_order_item_id', $chain['criticalItemId'])->firstOrFail();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/acknowledge')
        ->assertOk()
        ->assertJsonPath('data.status', 'acknowledged')
        ->assertJsonPath('data.acknowledgedByStaffId', $chain['doctorStaffId'])
        ->assertJsonPath('data.acknowledgedAt', $event->refresh()->acknowledged_at->toIso8601String());

    $fresh = $event->refresh();
    expect($fresh->status)->toBe(CriticalValueEvent::STATUS_ACKNOWLEDGED)
        ->and($fresh->acknowledged_by_staff_id)->toBe($chain['doctorStaffId'])
        ->and($fresh->acknowledged_at)->not->toBeNull()
        ->and($fresh->lock_version)->toBe(1);

    // The queue no longer shows it as open (status is terminal).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/critical-value-events')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'acknowledged');

    expect(AuditEvent::query()->where('action', 'critical_value.acknowledged')->count())->toBe(1);
});

it('refuses acknowledgment by anyone but the ordering clinician', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();
    $otherDoctorUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [criticalTest($org, $facility, 'CBC', 'CBC')]];
    $chain = criticalOrderWithResults($this, $setup);

    $event = CriticalValueEvent::query()->where('lab_order_item_id', $chain['criticalItemId'])->firstOrFail();

    // A different doctor (same facility) cannot acknowledge — 403.
    criticalDoctor($org, $facility, $otherDoctorUser);
    Identity::assign($otherDoctorUser, 'doctor', $org, $facility);
    $this->withToken(Identity::tokenFor($otherDoctorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/acknowledge')
        ->assertStatus(403);

    // The lab technician cannot acknowledge — no lab:acknowledge.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/acknowledge')
        ->assertStatus(403);

    // The event is untouched.
    expect($event->refresh()->status)->toBe(CriticalValueEvent::STATUS_TRIGGERED)
        ->and(AuditEvent::query()->where('action', 'critical_value.acknowledged')->doesntExist())->toBeTrue();
});

it('escalates an unacknowledged critical value and refuses the target clinician as escalator', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();
    $supervisorUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [criticalTest($org, $facility, 'CBC', 'CBC')]];
    $chain = criticalOrderWithResults($this, $setup);

    $event = CriticalValueEvent::query()->where('lab_order_item_id', $chain['criticalItemId'])->firstOrFail();

    // The ordering clinician cannot escalate their own critical value — they
    // must acknowledge it (fail loudly means a supervisor notices, not the
    // target self-escalating).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/escalate')
        ->assertStatus(403);

    // The lab supervisor escalates.
    $supervisor = criticalStaff($org, $facility, $supervisorUser, 'Lab Supervisor');
    Identity::assign($supervisorUser, 'lab_supervisor', $org, $facility);
    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/escalate')
        ->assertOk()
        ->assertJsonPath('data.status', 'escalated')
        ->assertJsonPath('data.escalatedByStaffId', $supervisor->getKey())
        ->assertJsonPath('data.escalatedAt', $event->refresh()->escalated_at->toIso8601String());

    $fresh = $event->refresh();
    expect($fresh->status)->toBe(CriticalValueEvent::STATUS_ESCALATED)
        ->and($fresh->escalated_by_staff_id)->toBe($supervisor->getKey())
        ->and($fresh->escalated_at)->not->toBeNull()
        ->and($fresh->lock_version)->toBe(1);

    // Escalating again → 409 (only triggered can be escalated).
    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/escalate')
        ->assertStatus(409);

    expect(AuditEvent::query()->where('action', 'critical_value.escalated')->count())->toBe(1);
});

it('closes an escalated critical value by acknowledgment (escalation stays loud until a human closes it)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();
    $supervisorUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [criticalTest($org, $facility, 'CBC', 'CBC')]];
    $chain = criticalOrderWithResults($this, $setup);

    $event = CriticalValueEvent::query()->where('lab_order_item_id', $chain['criticalItemId'])->firstOrFail();

    $supervisor = criticalStaff($org, $facility, $supervisorUser, 'Lab Supervisor');
    Identity::assign($supervisorUser, 'lab_supervisor', $org, $facility);
    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/escalate')
        ->assertOk();

    // After escalation the ordering clinician still acknowledges.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/acknowledge')
        ->assertOk()
        ->assertJsonPath('data.status', 'acknowledged');

    expect($event->refresh()->status)->toBe(CriticalValueEvent::STATUS_ACKNOWLEDGED)
        ->and($event->acknowledged_by_staff_id)->toBe($chain['doctorStaffId'])
        ->and($event->acknowledged_at)->not->toBeNull();
});

it('refuses double acknowledgment and a second open event for the same item', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [criticalTest($org, $facility, 'CBC', 'CBC')]];
    $chain = criticalOrderWithResults($this, $setup);

    $event = CriticalValueEvent::query()->where('lab_order_item_id', $chain['criticalItemId'])->firstOrFail();

    // While the event is still OPEN, the partial unique refuses a second
    // open event for the same item (a repeated trigger is a no-op at the
    // DB layer — one open escalation per result). The attempt runs INSIDE
    // the nested transaction (the established pattern): the constraint
    // violation propagates through Laravel's savepoint handler, which rolls
    // back to the savepoint and rethrows — the outer RefreshDatabase
    // transaction stays usable for the follow-up HTTP assertions.
    expect(fn () => DB::transaction(fn () => DB::table('critical_value_events')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'lab_order_item_id' => $chain['criticalItemId'],
        'patient_id' => $chain['patientId'],
        'encounter_id' => $chain['encounterId'],
        'target_staff_id' => $chain['doctorStaffId'],
        'status' => CriticalValueEvent::STATUS_TRIGGERED,
        'detected_by_staff_id' => $chain['techStaffId'],
        'detected_at' => now(),
        'lock_version' => 0,
    ])))->toThrow(QueryException::class);

    // Acknowledgment succeeds; double acknowledgment → 409 (already
    // acknowledged is terminal).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/acknowledge')
        ->assertOk();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/acknowledge')
        ->assertStatus(409);
});

it('wins the concurrent acknowledgment race via the compare-and-swap', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [criticalTest($org, $facility, 'CBC', 'CBC')]];
    $chain = criticalOrderWithResults($this, $setup);

    $event = CriticalValueEvent::query()->where('lab_order_item_id', $chain['criticalItemId'])->firstOrFail();

    // The winner commits atomically — the exact CAS the controller runs.
    $winner = DB::table('critical_value_events')
        ->where('id', $event->getKey())
        ->whereIn('status', [CriticalValueEvent::STATUS_TRIGGERED, CriticalValueEvent::STATUS_ESCALATED])
        ->where('lock_version', $event->lock_version)
        ->update([
            'status' => CriticalValueEvent::STATUS_ACKNOWLEDGED,
            'acknowledged_by_staff_id' => $chain['doctorStaffId'],
            'acknowledged_at' => now(),
            'lock_version' => $event->lock_version + 1,
        ]);

    expect($winner)->toBe(1);

    // A stale contender affects zero rows.
    $loser = DB::table('critical_value_events')
        ->where('id', $event->getKey())
        ->whereIn('status', [CriticalValueEvent::STATUS_TRIGGERED, CriticalValueEvent::STATUS_ESCALATED])
        ->where('lock_version', $event->lock_version)
        ->update([
            'status' => CriticalValueEvent::STATUS_ACKNOWLEDGED,
            'acknowledged_by_staff_id' => $chain['techStaffId'],
            'acknowledged_at' => now(),
            'lock_version' => $event->lock_version + 1,
        ]);

    expect($loser)->toBe(0);

    // The losing HTTP request — arriving after the winner committed — fails
    // safely with CONFLICT and changes nothing.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/acknowledge')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(CriticalValueEvent::query()->findOrFail($event->getKey())->acknowledged_by_staff_id)->toBe($chain['doctorStaffId']);

    // The winner was a direct DB CAS (this test bypasses the controller), so
    // no acknowledged audit event exists — the losing HTTP request produced
    // none either (a rejected transition never audits).
    expect(AuditEvent::query()->where('action', 'critical_value.acknowledged')->count())->toBe(0);
});

it('enforces RBAC: unauthenticated, lab-technician, and nurse cannot act on critical events', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [criticalTest($org, $facility, 'CBC', 'CBC')]];
    $chain = criticalOrderWithResults($this, $setup);

    $event = CriticalValueEvent::query()->where('lab_order_item_id', $chain['criticalItemId'])->firstOrFail();

    // Unauthenticated → 401.
    $this->flushHeaders();
    $this->postJson('/api/v1/critical-value-events/'.$event->getKey().'/acknowledge')
        ->assertStatus(401);
    $this->postJson('/api/v1/critical-value-events/'.$event->getKey().'/escalate')
        ->assertStatus(401);

    // The lab technician can see the queue (lab:view) but cannot act.
    $this->withToken(Identity::tokenFor($techUser))
        ->getJson('/api/v1/critical-value-events')
        ->assertOk()
        ->assertJsonCount(1, 'data');
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/escalate')
        ->assertStatus(403);

    // A nurse cannot escalate (no lab:escalate) and cannot acknowledge (not
    // the target).
    $nurseUser = Identity::user();
    criticalStaff($org, $facility, $nurseUser, 'Staff Nurse');
    Identity::assign($nurseUser, 'nurse', $org, $facility);
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/escalate')
        ->assertStatus(403);
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/acknowledge')
        ->assertStatus(403);

    // The event is untouched by every attack.
    expect($event->refresh()->status)->toBe(CriticalValueEvent::STATUS_TRIGGERED);
});

it('enforces cross-tenant and cross-facility isolation for the critical-value surface', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $doctorAUser = Identity::user();
    $techAUser = Identity::user();
    $setupA = [$orgA, $facilityA, $doctorAUser, $techAUser, [criticalTest($orgA, $facilityA, 'CBC', 'CBC')]];
    $chainA = criticalOrderWithResults($this, $setupA);

    $eventA = CriticalValueEvent::query()->where('lab_order_item_id', $chainA['criticalItemId'])->firstOrFail();

    // Tenant-B doctor attacking tenant A's event: read 404, writes 403.
    $doctorBUser = Identity::user();
    criticalDoctor($orgB, $facilityB, $doctorBUser);
    Identity::assign($doctorBUser, 'doctor', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($doctorBUser))
        ->postJson('/api/v1/critical-value-events/'.$eventA->getKey().'/acknowledge')
        ->assertStatus(403);

    // Tenant-B list sees zero events.
    $this->withToken(Identity::tokenFor($doctorBUser))
        ->getJson('/api/v1/critical-value-events')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // A tenant-A user in another facility also cannot see or touch it.
    $facilityA2 = Identity::facility($orgA);
    $doctorA2User = Identity::user();
    criticalDoctor($orgA, $facilityA2, $doctorA2User);
    Identity::assign($doctorA2User, 'doctor', $orgA, $facilityA2);
    $this->withToken(Identity::tokenFor($doctorA2User))
        ->postJson('/api/v1/critical-value-events/'.$eventA->getKey().'/acknowledge')
        ->assertStatus(403);
    $this->withToken(Identity::tokenFor($doctorA2User))
        ->getJson('/api/v1/critical-value-events')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Tenant A's data is untouched.
    expect($eventA->refresh()->status)->toBe(CriticalValueEvent::STATUS_TRIGGERED)
        ->and(CriticalValueEvent::query()->count())->toBe(1);
});

it('keeps result values and patient identifiers out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $setup = [$org, $facility, $doctorUser, $techUser, [criticalTest($org, $facility, 'CBC', 'CBC')]];
    $chain = criticalOrderWithResults($this, $setup);

    $event = CriticalValueEvent::query()->where('lab_order_item_id', $chain['criticalItemId'])->firstOrFail();
    $patient = Patient::query()->findOrFail($chain['patientId']);
    $patientName = $patient->full_name;

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/critical-value-events/'.$event->getKey().'/acknowledge')
        ->assertOk();

    // No result value, no patient name, no test name in ANY audit payload.
    foreach (AuditEvent::query()->whereIn('action', ['critical_value.triggered', 'critical_value.acknowledged'])->get() as $audit) {
        $encoded = json_encode($audit->payload);
        expect($encoded)->not->toContain('18.9')
            ->and($encoded)->not->toContain($patientName)
            ->and($encoded)->not->toContain('CBC');
    }

    // Facts are present.
    $ack = AuditEvent::query()->where('action', 'critical_value.acknowledged')->firstOrFail();
    expect($ack->payload)
        ->toHaveKey('itemId', $chain['criticalItemId'])
        ->toHaveKey('acknowledgedByStaffId', $chain['doctorStaffId']);
});

it('orders the escalation queue oldest-first', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    // Two orders with critical results — second one detected later. Each
    // order needs its OWN doctor/tech users (uq_staff_tenant_active_user —
    // one active staff record per tenant+user).
    $doctor2User = Identity::user();
    $tech2User = Identity::user();

    $setup1 = [$org, $facility, $doctorUser, $techUser, [criticalTest($org, $facility, 'CBC', 'CBC')]];
    $chain1 = criticalOrderWithResults($this, $setup1);
    $event1 = CriticalValueEvent::query()->where('lab_order_item_id', $chain1['criticalItemId'])->firstOrFail();

    $setup2 = [$org, $facility, $doctor2User, $tech2User, [criticalTest($org, $facility, 'GLU', 'Glucose')]];
    $chain2 = criticalOrderWithResults($this, $setup2);
    $event2 = CriticalValueEvent::query()->where('lab_order_item_id', $chain2['criticalItemId'])->firstOrFail();

    // Force event1 to be detected earlier.
    DB::table('critical_value_events')->where('id', $event1->getKey())->update(['detected_at' => now()->subHour()]);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/critical-value-events')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $event1->getKey())
        ->assertJsonPath('data.1.id', $event2->getKey());
});
