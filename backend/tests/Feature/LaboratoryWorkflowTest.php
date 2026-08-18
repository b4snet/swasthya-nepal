<?php

use App\Models\AuditEvent;
use App\Models\CriticalValueEvent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabResultVersion;
use App\Models\LabTest;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Specimen;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Phase 3 slice 15 — the complete documented laboratory workflow
 * (ROADMAP Phase 10, PRODUCT_REQUIREMENTS §6.8, CLINICAL_SAFETY §7):
 * specimens with accession + chain of custody (collected → accessioned →
 * processing → completed | rejected, WHO/WHEN at every step), and corrected
 * result versions (reported → correcting → results_entered → verified →
 * reported, the ORIGINAL always remaining visible). A corrected critical
 * value re-triggers escalation. Entry ≠ verification is preserved on the
 * correction path (distinct permissions + different-staff guard).
 *
 * The HL7/LIS readiness mappers are fixture-tested in tests/Unit
 * (Hl7MessageTest, OruResultMapperTest).
 */
beforeEach(function (): void {
    seedIdentity();
});

function lab15Doctor(Organization $org, Facility $facility, User $user): Staff
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

function lab15Staff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function lab15Encounter(Organization $org, Facility $facility, Staff $doctor): Encounter
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

function lab15Catalog(Organization $org, Facility $facility, string $code, string $name, ?string $range = '4.0–11.0'): LabTest
{
    return LabTest::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => $code,
        'name' => $name,
        'reference_range' => $range,
        'status' => LabTest::STATUS_ACTIVE,
    ]);
}

/**
 * Create an ordered lab order through the real API.
 *
 * @return array{orderId: string, itemIds: list<string>, encounterId: string, doctor: Staff}
 */
function lab15Order(TestCase $test, array $setup): array
{
    [$org, $facility, $doctorUser, $tests] = $setup;

    $doctor = lab15Doctor($org, $facility, $doctorUser);
    $encounter = lab15Encounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $response = $test->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/lab-orders', [
            'testIds' => collect($tests)->map(fn (LabTest $t): string => $t->getKey())->all(),
        ])
        ->assertCreated();

    return [
        'orderId' => $response->json('data.id'),
        'itemIds' => collect($response->json('data.items'))->pluck('id')->all(),
        'encounterId' => $encounter->getKey(),
        'doctor' => $doctor,
    ];
}

/**
 * Collect specimens on an order through the real API.
 *
 * @return array{specimenIds: list<string>, accessionNumbers: list<string>, tech: Staff}
 */
function lab15Collect(TestCase $test, Organization $org, Facility $facility, string $orderId, User $techUser, array $types = ['blood', 'urine']): array
{
    $tech = lab15Staff($org, $facility, $techUser, 'Lab Technician');
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    $response = $test->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/specimens', [
            'specimens' => collect($types)->map(fn (string $type): array => ['specimenType' => $type])->all(),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'collected');

    return [
        'specimenIds' => collect($response->json('data.specimens'))->pluck('id')->all(),
        'accessionNumbers' => collect($response->json('data.specimens'))->pluck('accessionNumber')->all(),
        'tech' => $tech,
    ];
}

/**
 * Drive an order all the way to REPORTED through the real API (specimen
 * custody path). Each call mints its own ordering clinician, entry
 * technician, verifier, and reporter (entry ≠ verification: the verifier and
 * reporter are always DIFFERENT supervisors than the enterer).
 *
 * @return array{orderId: string, itemIds: list<string>, encounterId: string, doctorStaffId: string, tech: Staff, verifier: Staff, reporter: Staff}
 */
function lab15Released(TestCase $test, array $setup, ?string $initialValue = '12.5'): array
{
    [$org, $facility, $techUser, $supervisorUser, $reporterUser] = $setup;

    $doctorUser = Identity::user();
    // The catalog code must be unique per tenant+facility, so each release
    // mints its own (the code itself is irrelevant to these tests).
    $code = 'GLU'.strtoupper(Str::random(4));
    $order = lab15Order($test, [$org, $facility, $doctorUser, [lab15Catalog($org, $facility, $code, 'Glucose', '70–99')]]);
    $collected = lab15Collect($test, $org, $facility, $order['orderId'], $techUser);

    $test->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$collected['specimenIds'][0].'/accession')
        ->assertOk();
    $test->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$collected['specimenIds'][0].'/process')
        ->assertOk();
    $test->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$collected['specimenIds'][0].'/complete')
        ->assertOk();

    $test->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$order['orderId'].'/results', [
            'results' => collect($order['itemIds'])->map(fn (string $itemId): array => [
                'itemId' => $itemId,
                'resultValue' => $initialValue,
                'resultUnit' => 'mg/dL',
            ])->all(),
        ])
        ->assertOk();

    $verifier = lab15Staff($org, $facility, $supervisorUser, 'Lab Supervisor');
    Identity::assign($supervisorUser, 'lab_supervisor', $org, $facility);
    $test->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/lab-orders/'.$order['orderId'].'/verify')
        ->assertOk();

    $reporter = lab15Staff($org, $facility, $reporterUser, 'Lab Supervisor');
    Identity::assign($reporterUser, 'lab_supervisor', $org, $facility);
    $test->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$order['orderId'].'/report')
        ->assertOk()
        ->assertJsonPath('data.status', 'reported');

    return [
        'orderId' => $order['orderId'],
        'itemIds' => $order['itemIds'],
        'encounterId' => $order['encounterId'],
        'doctorStaffId' => $order['doctor']->getKey(),
        'tech' => $collected['tech'],
        'verifier' => $verifier,
        'reporter' => $reporter,
    ];
}

it('collects specimens with minted accession numbers and advances the order (audited)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $order = lab15Order($this, [$org, $facility, $doctorUser, [lab15Catalog($org, $facility, 'CBC', 'CBC'), lab15Catalog($org, $facility, 'GLU', 'Glucose')]]);
    $collected = lab15Collect($this, $org, $facility, $order['orderId'], $techUser, ['blood', 'urine']);

    expect(count($collected['specimenIds']))->toBe(2)
        ->and(count($collected['accessionNumbers']))->toBe(2)
        ->and($collected['accessionNumbers'][0])->toMatch('/^ACC-/')
        ->and($collected['accessionNumbers'][1])->not->toBe($collected['accessionNumbers'][0]);

    // The accession label is unique per tenant (DB unique index).
    $specimens = Specimen::query()->where('lab_order_id', $order['orderId'])->get();
    expect($specimens->count())->toBe(2)
        ->and($specimens->pluck('accession_number')->unique()->count())->toBe(2)
        ->and($specimens->every(fn (Specimen $s): bool => $s->status === Specimen::STATUS_COLLECTED
            && $s->collected_by_staff_id === $collected['tech']->getKey()
            && $s->collected_at !== null))->toBeTrue();

    // The order advanced ordered → collected in the same atomic step, and
    // the collection is audited with facts only (no specimen types).
    expect(LabOrder::query()->findOrFail($order['orderId'])->status)->toBe(LabOrder::STATUS_COLLECTED);
    $event = AuditEvent::query()->where('action', 'lab_order.specimens_collected')->firstOrFail();
    expect($event->payload['specimenCount'])->toBe(2)
        ->and(json_encode($event->payload))->not->toContain('blood')
        ->and(json_encode($event->payload))->not->toContain('urine');
});

it('refuses specimen collection on wrong states and with malformed payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $order = lab15Order($this, [$org, $facility, $doctorUser, [lab15Catalog($org, $facility, 'CBC', 'CBC')]]);
    $tech = lab15Staff($org, $facility, $techUser, 'Lab Technician');
    Identity::assign($techUser, 'lab_technician', $org, $facility);

    // Empty specimen list → 422.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$order['orderId'].'/specimens', ['specimens' => []])
        ->assertStatus(422);

    // Missing specimenType → 422.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$order['orderId'].'/specimens', ['specimens' => [['container' => 'edta']]])
        ->assertStatus(422);

    // A nurse cannot collect specimens (no lab:specimen) → 403.
    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $org, $facility);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/lab-orders/'.$order['orderId'].'/specimens', ['specimens' => [['specimenType' => 'blood']]])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    expect(Specimen::query()->count())->toBe(0);

    // A valid collection works, then a second collection is refused (409 —
    // the order is no longer 'ordered').
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$order['orderId'].'/specimens', ['specimens' => [['specimenType' => 'blood']]])
        ->assertOk();

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$order['orderId'].'/specimens', ['specimens' => [['specimenType' => 'urine']]])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(Specimen::query()->count())->toBe(1);
});

it('walks the custody chain accession → processing → completed, advancing the order once', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $order = lab15Order($this, [$org, $facility, $doctorUser, [lab15Catalog($org, $facility, 'CBC', 'CBC')]]);
    $collected = lab15Collect($this, $org, $facility, $order['orderId'], $techUser, ['blood']);
    $specimenId = $collected['specimenIds'][0];

    // Accession: collected → accessioned (WHO/WHEN recorded).
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimenId.'/accession')
        ->assertOk()
        ->assertJsonPath('data.status', 'accessioned')
        ->assertJsonPath('data.accessionedByStaffId', $collected['tech']->getKey());

    // Process: accessioned → processing; the ORDER advances to processing
    // exactly once (the results-entry gate).
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimenId.'/process')
        ->assertOk()
        ->assertJsonPath('data.status', 'processing');

    expect(LabOrder::query()->findOrFail($order['orderId'])->status)->toBe(LabOrder::STATUS_PROCESSING);

    // Complete: processing → completed.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimenId.'/complete')
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.completedByStaffId', $collected['tech']->getKey());

    $specimen = Specimen::query()->findOrFail($specimenId);
    expect($specimen->status)->toBe(Specimen::STATUS_COMPLETED)
        ->and($specimen->completed_at)->not->toBeNull();

    foreach (['specimen.accessioned', 'specimen.processing', 'specimen.completed'] as $action) {
        expect(AuditEvent::query()->where('action', $action)->where('resource_id', $specimenId)->exists())->toBeTrue();
    }
});

it('rejects invalid custody transitions (double steps and impossible jumps)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $order = lab15Order($this, [$org, $facility, $doctorUser, [lab15Catalog($org, $facility, 'CBC', 'CBC')]]);
    $collected = lab15Collect($this, $org, $facility, $order['orderId'], $techUser, ['blood']);
    $specimenId = $collected['specimenIds'][0];

    // Processing an unaccessioned specimen is refused (state machine).
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimenId.'/process')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimenId.'/accession')
        ->assertOk();

    // Double accession is refused.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimenId.'/accession')
        ->assertStatus(409);

    // Completing before processing is refused.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimenId.'/complete')
        ->assertStatus(409);

    // The specimen is untouched by every failed transition.
    expect(Specimen::query()->findOrFail($specimenId)->status)->toBe(Specimen::STATUS_ACCESSIONED);
});

it('rejects a specimen with a captured reason and refuses rejections without one or after completion', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $order = lab15Order($this, [$org, $facility, $doctorUser, [lab15Catalog($org, $facility, 'CBC', 'CBC')]]);
    $collected = lab15Collect($this, $org, $facility, $order['orderId'], $techUser, ['blood']);
    $specimenId = $collected['specimenIds'][0];

    // Reason is required (request rule AND the chk_specimens_reject CHECK).
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimenId.'/reject', [])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimenId.'/reject', ['reason' => 'Hemolyzed sample'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejectedByStaffId', $collected['tech']->getKey());

    // The reason is stored but never exposed in the response or audit payload.
    expect(Specimen::query()->findOrFail($specimenId)->rejection_reason)->toBe('Hemolyzed sample');
    $event = AuditEvent::query()->where('action', 'specimen.rejected')->firstOrFail();
    expect(json_encode($event->payload))->not->toContain('Hemolyzed');

    // Rejecting an already-rejected specimen is refused.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimenId.'/reject', ['reason' => 'Again'])
        ->assertStatus(409);

    // A completed specimen cannot be rejected either: mint a second specimen
    // for the SAME order directly (the order is already collected), then
    // drive it to completed and try to reject.
    $second = Specimen::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'lab_order_id' => $order['orderId'],
        'status' => Specimen::STATUS_COLLECTED,
        'collected_by_staff_id' => $collected['tech']->getKey(),
        'collected_at' => now(),
    ]);
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$second->getKey().'/accession')
        ->assertOk();
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$second->getKey().'/process')
        ->assertOk();
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$second->getKey().'/complete')
        ->assertOk();
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$second->getKey().'/reject', ['reason' => 'Too late'])
        ->assertStatus(409);
});

it('corrects a reported result as a new audited version, keeping the original visible', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $techUser = Identity::user();
    $supervisorUser = Identity::user();
    $reporterUser = Identity::user();

    $released = lab15Released($this, [$org, $facility, $techUser, $supervisorUser, $reporterUser], '12.5');
    $orderId = $released['orderId'];
    $itemId = $released['itemIds'][0];

    // Entry wrote version 1 (the append-only history begins at entry).
    expect(LabResultVersion::query()->where('lab_order_item_id', $itemId)->count())->toBe(1)
        ->and(LabResultVersion::query()->where('lab_order_item_id', $itemId)->first()->version_no)->toBe(1)
        ->and(LabResultVersion::query()->where('lab_order_item_id', $itemId)->first()->result_value)->toBe('12.5');

    // A technician cannot open a correction (no lab:correct → 403).
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/correct', ['reason' => 'Wrong result'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // The lab supervisor (lab:correct) opens the correction with the reason.
    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/correct', ['reason' => 'Transcription error'])
        ->assertOk()
        ->assertJsonPath('data.status', 'correcting')
        ->assertJsonPath('data.correctionReason', 'Transcription error');

    expect(LabOrder::query()->findOrFail($orderId)->status)->toBe(LabOrder::STATUS_CORRECTING);

    // The corrected value is entered as version 2 with the captured reason.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$orderId.'/corrected-results', [
            'results' => [['itemId' => $itemId, 'resultValue' => '11.9', 'resultUnit' => 'mg/dL']],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'results_entered')
        ->assertJsonPath('data.items.0.resultValue', '11.9')
        ->assertJsonCount(2, 'data.items.0.versions');

    $versions = LabResultVersion::query()->where('lab_order_item_id', $itemId)->orderBy('version_no')->get();
    expect($versions->count())->toBe(2)
        ->and($versions[0]->version_no)->toBe(1)
        ->and($versions[0]->result_value)->toBe('12.5') // ORIGINAL always visible
        ->and($versions[1]->version_no)->toBe(2)
        ->and($versions[1]->result_value)->toBe('11.9')
        ->and($versions[1]->correction_reason)->toBe('Transcription error');

    // The audit records the correction with facts only — no values, no reason.
    $event = AuditEvent::query()->where('action', 'lab_order.corrected')->firstOrFail();
    expect(json_encode($event->payload))->not->toContain('12.5')
        ->and(json_encode($event->payload))->not->toContain('11.9')
        ->and(json_encode($event->payload))->not->toContain('Transcription');
});

it('re-runs entry → verification → release after a correction (different-staff guard intact)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $techUser = Identity::user();
    $supervisorUser = Identity::user();
    $reporterUser = Identity::user();

    $released = lab15Released($this, [$org, $facility, $techUser, $supervisorUser, $reporterUser], '12.5');

    // Open the correction and enter the corrected value (by the technician).
    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/correct', ['reason' => 'Rounding error'])
        ->assertOk();
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/corrected-results', [
            'results' => [['itemId' => $released['itemIds'][0], 'resultValue' => '9.8']],
        ])
        ->assertOk();

    // The original verifier (a supervisor) is NOT the corrected entry staff —
    // verification succeeds and re-stamps the LATEST version.
    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/verify')
        ->assertOk()
        ->assertJsonPath('data.status', 'verified');

    $latest = LabResultVersion::query()->where('lab_order_item_id', $released['itemIds'][0])->orderByDesc('version_no')->first();
    expect($latest->verified_at)->not->toBeNull()
        ->and($latest->verified_by_staff_id)->toBe($released['verifier']->getKey());

    // Release the corrected report (a different supervisor).
    $releaseUser = Identity::user();
    lab15Staff($org, $facility, $releaseUser, 'Lab Supervisor');
    Identity::assign($releaseUser, 'lab_supervisor', $org, $facility);
    $this->withToken(Identity::tokenFor($releaseUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/report')
        ->assertOk()
        ->assertJsonPath('data.status', 'reported');

    // The corrected order is immutable again.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/corrected-results', [
            'results' => [['itemId' => $released['itemIds'][0], 'resultValue' => '1.0']],
        ])
        ->assertStatus(409);
});

it('refuses corrections in wrong states and corrected entries outside a correction', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $techUser = Identity::user();
    $supervisorUser = Identity::user();
    $reporterUser = Identity::user();

    $released = lab15Released($this, [$org, $facility, $techUser, $supervisorUser, $reporterUser]);

    // Correcting an already-correcting order (double correct) is refused.
    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/correct', ['reason' => 'First'])
        ->assertOk();
    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/correct', ['reason' => 'Second'])
        ->assertStatus(409);

    // A correction without a reason is a validation error.
    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/correct', ['reason' => ''])
        ->assertStatus(422);

    // Corrected entry on a non-correcting (reported) order is refused.
    $fresh = lab15Released($this, [$org, $facility, Identity::user(), Identity::user(), Identity::user()]);
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$fresh['orderId'].'/corrected-results', [
            'results' => [['itemId' => $fresh['itemIds'][0], 'resultValue' => '5.0']],
        ])
        ->assertStatus(409);

    // Corrected entry referencing a foreign item is refused.
    $foreign = LabOrderItem::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'lab_order_id' => $fresh['orderId'],
        'lab_test_id' => lab15Catalog($org, $facility, 'LFT', 'LFT')->getKey(),
    ]);
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$fresh['orderId'].'/corrected-results', [
            'results' => [['itemId' => $foreign->getKey(), 'resultValue' => '5.0']],
        ])
        ->assertStatus(409);
});

it('re-triggers critical-value escalation when a correction is critical', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $techUser = Identity::user();
    $supervisorUser = Identity::user();
    $reporterUser = Identity::user();

    $released = lab15Released($this, [$org, $facility, $techUser, $supervisorUser, $reporterUser], '110');

    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/correct', ['reason' => 'Instrument recalibration'])
        ->assertOk();

    // The corrected value is critical → a FRESH critical_value_event is
    // triggered (the escalation re-runs; CLINICAL_SAFETY §7).
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/corrected-results', [
            'results' => [['itemId' => $released['itemIds'][0], 'resultValue' => '450', 'isCritical' => true]],
        ])
        ->assertOk();

    $event = CriticalValueEvent::query()->where('lab_order_item_id', $released['itemIds'][0])->firstOrFail();
    expect($event->status)->toBe(CriticalValueEvent::STATUS_TRIGGERED)
        ->and($event->target_staff_id)->toBe($released['doctorStaffId']); // targeted at the ordering clinician

    $retriggered = AuditEvent::query()->where('action', 'critical_value.retriggered')->firstOrFail();
    expect($retriggered->payload['itemId'])->toBe($released['itemIds'][0])
        // The event stores facts only — never the (critical) result value.
        // Asserted by KEY, not by whole-payload substring (a UUID's hex can
        // coincidentally contain the digits '450', which made the
        // raw-substring form flaky).
        ->and($retriggered->payload)->not->toHaveKey('resultValue')
        ->and($retriggered->payload)->not->toHaveKey('value')
        ->and($retriggered->payload)->not->toHaveKey('isCritical');

    // The corrected version itself is flagged critical.
    $latest = LabResultVersion::query()->where('lab_order_item_id', $released['itemIds'][0])->orderByDesc('version_no')->first();
    expect($latest->is_critical)->toBeTrue()
        ->and($latest->result_value)->toBe('450');
});

it('keeps an in-flight critical escalation open when a later correction is critical again', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $techUser = Identity::user();
    $supervisorUser = Identity::user();
    $reporterUser = Identity::user();

    $released = lab15Released($this, [$org, $facility, $techUser, $supervisorUser, $reporterUser], '110');

    // First correction flags critical → fresh event (open).
    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/correct', ['reason' => 'Recheck'])
        ->assertOk();
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/corrected-results', [
            'results' => [['itemId' => $released['itemIds'][0], 'resultValue' => '445', 'isCritical' => true]],
        ])
        ->assertOk();
    expect(CriticalValueEvent::query()->where('lab_order_item_id', $released['itemIds'][0])->count())->toBe(1);

    // The partial unique uq_critical_value_events_tenant_item_open backstops:
    // a second open event for the same item is structurally impossible, so
    // the re-trigger keeps the in-flight escalation instead of crashing. To
    // open a SECOND correction the order must first return to reported
    // (verify + report re-run the release discipline).
    $this->withToken(Identity::tokenFor($supervisorUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/verify')
        ->assertOk();
    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/report')
        ->assertOk();

    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/correct', ['reason' => 'Recheck again'])
        ->assertOk();
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/corrected-results', [
            'results' => [['itemId' => $released['itemIds'][0], 'resultValue' => '440', 'isCritical' => true]],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'results_entered');

    expect(CriticalValueEvent::query()->where('lab_order_item_id', $released['itemIds'][0])->count())->toBe(1)
        ->and(CriticalValueEvent::query()->where('lab_order_item_id', $released['itemIds'][0])->first()->status)->toBe(CriticalValueEvent::STATUS_TRIGGERED);
});

it('isolates specimens across tenants and facilities (read 404, write 403)', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    // Tenant B's specimen chain.
    $doctorB = lab15Doctor($orgB, $facilityB, Identity::user());
    $encounterB = lab15Encounter($orgB, $facilityB, $doctorB);
    $orderB = LabOrder::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $encounterB->patient_id,
        'encounter_id' => $encounterB->getKey(),
        'ordered_by_staff_id' => $doctorB->getKey(),
    ]);
    $specimenB = Specimen::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'lab_order_id' => $orderB->getKey(),
        'collected_by_staff_id' => $doctorB->getKey(),
        'collected_at' => now(),
    ]);

    // Tenant A staff: read → 404, write → 403.
    $adminA = Identity::user();
    Identity::assign($adminA, 'org_admin', $orgA);
    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/lab-orders/'.$orderB->getKey())
        ->assertStatus(404);
    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/specimens/'.$specimenB->getKey().'/accession')
        ->assertStatus(403);

    // Same tenant, other facility: facility B's specimen is invisible to
    // facility-A staff (TENANT_FACILITY tier).
    $facilityA2 = Identity::facility($orgA);
    $techA2 = Identity::user();
    lab15Staff($orgA, $facilityA2, $techA2, 'Lab Technician');
    Identity::assign($techA2, 'lab_technician', $orgA, $facilityA2);
    $this->withToken(Identity::tokenFor($techA2))
        ->getJson('/api/v1/lab-orders/'.$orderB->getKey())
        ->assertStatus(404);

    // Org-scoped admin of tenant B can read it.
    $adminB = Identity::user();
    Identity::assign($adminB, 'org_admin', $orgB);
    $this->withToken(Identity::tokenFor($adminB))
        ->getJson('/api/v1/lab-orders/'.$orderB->getKey())
        ->assertOk();
});

it('wins the specimen custody race exactly once via the compare-and-swap', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $techUser = Identity::user();

    $order = lab15Order($this, [$org, $facility, $doctorUser, [lab15Catalog($org, $facility, 'CBC', 'CBC')]]);
    $collected = lab15Collect($this, $org, $facility, $order['orderId'], $techUser, ['blood']);
    $specimen = Specimen::query()->findOrFail($collected['specimenIds'][0]);

    // The winner commits atomically — the exact CAS the controller runs:
    // WHERE status AND lock_version match, then advance.
    $winner = DB::table('specimens')
        ->where('id', $specimen->getKey())
        ->where('status', Specimen::STATUS_COLLECTED)
        ->where('lock_version', $specimen->lock_version)
        ->update(['status' => Specimen::STATUS_ACCESSIONED, 'lock_version' => $specimen->lock_version + 1]);

    expect($winner)->toBe(1);

    // A second actor holding the SAME stale snapshot can never advance the
    // specimen again (double-advance impossible).
    $loser = DB::table('specimens')
        ->where('id', $specimen->getKey())
        ->where('status', Specimen::STATUS_COLLECTED)
        ->where('lock_version', $specimen->lock_version)
        ->update(['status' => Specimen::STATUS_PROCESSING, 'lock_version' => $specimen->lock_version + 1]);

    expect($loser)->toBe(0);

    // And the losing HTTP request — arriving after the winner committed —
    // fails safely with CONFLICT and changes nothing.
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$specimen->getKey().'/accession')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(Specimen::query()->findOrFail($specimen->getKey())->status)->toBe(Specimen::STATUS_ACCESSIONED);
});

it('keeps result values, specimen types, and reasons out of every audit payload', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $techUser = Identity::user();
    $supervisorUser = Identity::user();
    $reporterUser = Identity::user();

    $released = lab15Released($this, [$org, $facility, $techUser, $supervisorUser, $reporterUser], '88.7');

    // Reject one specimen with a distinctive reason (minted directly on the
    // released order's chain — the API can only collect on an 'ordered' one).
    $rejected = Specimen::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'lab_order_id' => $released['orderId'],
        'status' => Specimen::STATUS_COLLECTED,
        'collected_by_staff_id' => $released['tech']->getKey(),
        'collected_at' => now(),
    ]);
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/specimens/'.$rejected->getKey().'/reject', ['reason' => 'Clotted specimen'])
        ->assertOk();

    $this->withToken(Identity::tokenFor($reporterUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/correct', ['reason' => 'Result entry typo'])
        ->assertOk();
    $this->withToken(Identity::tokenFor($techUser))
        ->postJson('/api/v1/lab-orders/'.$released['orderId'].'/corrected-results', [
            'results' => [['itemId' => $released['itemIds'][0], 'resultValue' => '89.1', 'isCritical' => true]],
        ])
        ->assertOk();

    $needles = ['88.7', '89.1', 'Clotted', 'typo', 'swab'];
    foreach (AuditEvent::query()->get() as $event) {
        foreach ($needles as $needle) {
            expect(json_encode($event->payload))->not->toContain($needle, $event->action.' leaked '.$needle);
        }
    }
});
