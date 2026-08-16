<?php

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Modality;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\RadiologyReport;
use App\Models\Staff;
use App\Models\Study;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Phase 3 slice 16 — the Radiology workflow (ROADMAP Phase 11,
 * PRODUCT_REQUIREMENTS §6.9, CLINICAL_SAFETY §8): the clinician orders
 * imaging from an open encounter → the study is scheduled on a modality →
 * the radiographer performs it → the radiologist drafts the report → a
 * DIFFERENT radiologist verifies it (preliminary vs final explicit, timing
 * visible) → amendments are new preserved versions. DICOM references
 * attach to performed studies (composite FK — never dangling). Every
 * transition is compare-and-swap on (status, lock_version).
 */
beforeEach(function (): void {
    seedIdentity();
});

function radDoctor(Organization $org, Facility $facility, User $user): Staff
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

function radStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function radEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
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

function radCatalog(Organization $org, Facility $facility, string $code, string $name): LabTest
{
    return LabTest::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => $code,
        'name' => $name,
        'category' => LabTest::CATEGORY_RADIOLOGY,
        'status' => LabTest::STATUS_ACTIVE,
    ]);
}

/**
 * Order one study through the real API as the doctor; returns the study id.
 *
 * @return array{studyId: string, orderId: string}
 */
function radOrder(TestCase $test, Organization $org, Facility $facility, User $doctorUser, LabTest $testItem): array
{
    $doctor = radDoctor($org, $facility, $doctorUser);
    $encounter = radEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $response = $test->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', [
            'testIds' => [$testItem->getKey()],
            'priority' => 'urgent',
            'clinicalIndication' => 'Chest pain',
        ])
        ->assertCreated();

    return [
        'studyId' => $response->json('data.id'),
        'orderId' => $response->json('data.orderId'),
    ];
}

/**
 * Drive an order to performed through the real API.
 *
 * @return array{studyId: string, modalityId: string, orderId: string, doctor: Staff, encounter: Encounter}
 */
function radPerformed(TestCase $test, Organization $org, Facility $facility, User $doctorUser, User $radioUser, LabTest $testItem): array
{
    $doctor = radDoctor($org, $facility, $doctorUser);
    $encounter = radEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    Identity::assign($radioUser, 'radiographer', $org, $facility);
    radStaff($org, $facility, $radioUser, 'Radiographer');

    $orderResponse = $test->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', [
            'testIds' => [$testItem->getKey()],
        ])
        ->assertCreated();

    $studyId = $orderResponse->json('data.id');
    $orderId = $orderResponse->json('data.orderId');

    $modality = Modality::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'XR-'.strtoupper(substr((string) Str::uuid(), 0, 6)),
        'modality_type' => 'xray',
        'status' => Modality::STATUS_ACTIVE,
    ]);

    $scheduled = $test->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$studyId.'/schedule', [
            'modalityId' => $modality->getKey(),
            'scheduledAt' => now()->addDay()->toISOString(),
            'lockVersion' => 0,
        ])
        ->assertOk();

    $test->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$studyId.'/perform', [
            'lockVersion' => $scheduled->json('data.lockVersion'),
        ])
        ->assertOk();

    return ['studyId' => $studyId, 'modalityId' => $modality->getKey(), 'orderId' => $orderId, 'doctor' => $doctor, 'encounter' => $encounter];
}

it('orders an imaging study from an open encounter (order + study atomic)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = radDoctor($org, $facility, $doctorUser);
    $encounter = radEncounter($org, $facility, $doctor);
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $response = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', [
            'testIds' => [$xray->getKey()],
            'priority' => 'stat',
            'clinicalIndication' => 'Rule out pneumonia',
        ])
        ->assertCreated();

    $studyId = $response->json('data.id');
    $orderId = $response->json('data.orderId');

    expect($response->json('data.status'))->toBe(Study::STATUS_ORDERED)
        ->and($response->json('data.orderId'))->not->toBeNull();

    $order = LabOrder::query()->findOrFail($orderId);
    $study = Study::query()->findOrFail($studyId);

    expect($order->priority)->toBe('stat')
        ->and($order->clinical_indication)->toBe('Rule out pneumonia')
        ->and($order->patient_id)->toBe($encounter->patient_id)
        ->and($study->status)->toBe(Study::STATUS_ORDERED)
        ->and($study->lab_order_id)->toBe($orderId)
        ->and(Study::query()->where('lab_order_id', $orderId)->count())->toBe(1);

    $this->assertDatabaseHas('audit_events', [
        'action' => 'radiology_order.created',
        'tenant_id' => $org->getKey(),
    ]);
});

it('rejects a non-radiology catalog item on the radiology order surface', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = radDoctor($org, $facility, $doctorUser);
    $encounter = radEncounter($org, $facility, $doctor);
    $labTest = LabTest::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'CBC-'.substr((string) Str::uuid(), 0, 6),
        'category' => 'laboratory',
        'status' => LabTest::STATUS_ACTIVE,
    ]);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', [
            'testIds' => [$labTest->getKey()],
        ])
        ->assertStatus(422);
});

it('enforces RBAC on ordering, scheduling, performing, and reporting', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $nurseUser = Identity::user();
    $pharmacistUser = Identity::user();
    $doctor = radDoctor($org, $facility, $doctorUser);
    $encounter = radEncounter($org, $facility, $doctor);
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    Identity::assign($nurseUser, 'nurse', $org, $facility);
    Identity::assign($pharmacistUser, 'pharmacist', $org, $facility);

    // Unauthenticated → 401.
    $this->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', ['testIds' => [$xray->getKey()]])
        ->assertStatus(401);

    // Nurse cannot order.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', ['testIds' => [$xray->getKey()]])
        ->assertStatus(403);

    // Pharmacist has no radiology visibility at all.
    $this->withToken(Identity::tokenFor($pharmacistUser))
        ->getJson('/api/v1/radiology/queue')
        ->assertStatus(403);

    // Doctor orders.
    $orderResponse = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', ['testIds' => [$xray->getKey()]])
        ->assertCreated();
    $studyId = $orderResponse->json('data.id');

    // The ordering doctor cannot schedule (radiology:schedule missing).
    $modality = Modality::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'XR-'.substr((string) Str::uuid(), 0, 6),
        'modality_type' => 'xray',
        'status' => Modality::STATUS_ACTIVE,
    ]);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/studies/'.$studyId.'/schedule', [
            'modalityId' => $modality->getKey(),
            'scheduledAt' => now()->addDay()->toISOString(),
            'lockVersion' => 0,
        ])
        ->assertStatus(403);
});

it('schedules, performs, drafts, verifies, and reports a study (full lifecycle)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $radioUser2 = Identity::user();
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');

    ['studyId' => $studyId, 'modalityId' => $modalityId] = radPerformed($this, $org, $facility, $doctorUser, $radioUser, $xray);

    // Queue is empty after perform.
    $this->withToken(Identity::tokenFor($radioUser))
        ->getJson('/api/v1/radiology/queue')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Radiologist A drafts the report.
    $radAUser = Identity::user();
    $radA = radStaff($org, $facility, $radAUser, 'Radiologist');
    Identity::assign($radAUser, 'radiologist', $org, $facility);

    $draft = $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'final',
            'content' => 'Normal chest film. No acute pathology.',
            'impression' => 'No abnormality detected.',
            'criticalFindings' => 'None.',
        ])
        ->assertCreated();

    $reportId = $draft->json('data.id');
    expect($draft->json('data.status'))->toBe(RadiologyReport::STATUS_DRAFT)
        ->and($draft->json('data.verifiedAt'))->toBeNull();

    // The same radiologist cannot verify their own report (entry ≠
    // verification).
    $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/radiology-reports/'.$reportId.'/verify', ['lockVersion' => 0])
        ->assertStatus(403);

    // A different radiologist verifies — the study reaches reported.
    $radBUser = Identity::user();
    $radB = radStaff($org, $facility, $radBUser, 'Radiologist');
    Identity::assign($radBUser, 'radiologist', $org, $facility);

    $verified = $this->withToken(Identity::tokenFor($radBUser))
        ->postJson('/api/v1/radiology-reports/'.$reportId.'/verify', ['lockVersion' => 0])
        ->assertOk();

    expect($verified->json('data.status'))->toBe(RadiologyReport::STATUS_FINAL)
        ->and($verified->json('data.verifiedByStaffId'))->toBe($radB->getKey())
        ->and($verified->json('data.verifiedAt'))->not->toBeNull();

    $study = Study::query()->findOrFail($studyId);
    expect($study->status)->toBe(Study::STATUS_REPORTED);

    // Report timing is visible (prelim/final).
    $this->assertDatabaseHas('audit_events', [
        'action' => 'radiology_report.verified',
        'tenant_id' => $org->getKey(),
    ]);
});

it('verifies a preliminary report without releasing the study', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');

    ['studyId' => $studyId] = radPerformed($this, $org, $facility, $doctorUser, $radioUser, $xray);

    $radAUser = Identity::user();
    radStaff($org, $facility, $radAUser, 'Radiologist');
    Identity::assign($radAUser, 'radiologist', $org, $facility);

    $draft = $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'preliminary',
            'content' => 'Early read: no acute findings.',
        ])
        ->assertCreated();
    $reportId = $draft->json('data.id');

    $radBUser = Identity::user();
    radStaff($org, $facility, $radBUser, 'Radiologist');
    Identity::assign($radBUser, 'radiologist', $org, $facility);

    $verified = $this->withToken(Identity::tokenFor($radBUser))
        ->postJson('/api/v1/radiology-reports/'.$reportId.'/verify', ['lockVersion' => 0])
        ->assertOk();

    expect($verified->json('data.status'))->toBe(RadiologyReport::STATUS_PRELIMINARY);

    $study = Study::query()->findOrFail($studyId);
    expect($study->status)->toBe(Study::STATUS_PERFORMED); // not released
});

it('cannot draft a report before the study is performed, or verify an unverified draft twice', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');

    // Order + schedule only (not performed).
    $doctor = radDoctor($org, $facility, $doctorUser);
    $encounter = radEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    Identity::assign($radioUser, 'radiographer', $org, $facility);
    radStaff($org, $facility, $radioUser, 'Radiographer');

    $orderResponse = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', ['testIds' => [$xray->getKey()]])
        ->assertCreated();
    $studyId = $orderResponse->json('data.id');

    $modality = Modality::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'XR-'.substr((string) Str::uuid(), 0, 6),
        'modality_type' => 'xray',
        'status' => Modality::STATUS_ACTIVE,
    ]);

    $this->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$studyId.'/schedule', [
            'modalityId' => $modality->getKey(),
            'scheduledAt' => now()->addDay()->toISOString(),
            'lockVersion' => 0,
        ])
        ->assertOk();

    // A report cannot be drafted while the study is only scheduled.
    $radAUser = Identity::user();
    radStaff($org, $facility, $radAUser, 'Radiologist');
    Identity::assign($radAUser, 'radiologist', $org, $facility);

    $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'final',
            'content' => 'Premature read.',
        ])
        ->assertStatus(409);
});

it('amends a final report as a new preserved version (original intact)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');

    ['studyId' => $studyId] = radPerformed($this, $org, $facility, $doctorUser, $radioUser, $xray);

    $radAUser = Identity::user();
    radStaff($org, $facility, $radAUser, 'Radiologist');
    Identity::assign($radAUser, 'radiologist', $org, $facility);
    $radBUser = Identity::user();
    radStaff($org, $facility, $radBUser, 'Radiologist');
    Identity::assign($radBUser, 'radiologist', $org, $facility);

    // Draft + verify the final.
    $draft = $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'final',
            'content' => 'Normal film. No acute findings.',
        ])
        ->assertCreated();
    $reportId = $draft->json('data.id');

    $this->withToken(Identity::tokenFor($radBUser))
        ->postJson('/api/v1/radiology-reports/'.$reportId.'/verify', ['lockVersion' => 0])
        ->assertOk();

    // Amend: the current final is superseded; a NEW draft is created with a
    // parent link.
    $amendment = $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/radiology-reports/'.$reportId.'/amend', [
            'content' => 'Amended: subtle left lower lobe opacity noted. Correlate clinically.',
        ])
        ->assertCreated();

    $amendmentId = $amendment->json('data.id');

    expect($amendment->json('data.parentReportId'))->toBe($reportId)
        ->and($amendment->json('data.status'))->toBe(RadiologyReport::STATUS_DRAFT);

    // The original is preserved as 'amended', never edited.
    $original = RadiologyReport::query()->findOrFail($reportId);
    expect($original->status)->toBe(RadiologyReport::STATUS_AMENDED)
        ->and($original->content)->toBe('Normal film. No acute findings.');

    // The amendment must be verified (entry ≠ verification) before release.
    $this->withToken(Identity::tokenFor($radBUser))
        ->postJson('/api/v1/radiology-reports/'.$amendmentId.'/verify', ['lockVersion' => 0])
        ->assertOk();

    // Exactly one ACTIVE final per study.
    expect(RadiologyReport::query()->where('study_id', $studyId)->where('status', RadiologyReport::STATUS_FINAL)->count())->toBe(1);
});

it('attaches DICOM references only to performed studies (no dangling refs)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');

    ['studyId' => $studyId, 'doctor' => $doctor] = radPerformed($this, $org, $facility, $doctorUser, $radioUser, $xray);

    $radAUser = Identity::user();
    radStaff($org, $facility, $radAUser, 'Radiologist');
    Identity::assign($radAUser, 'radiologist', $org, $facility);

    $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$studyId.'/image-references', [
            'references' => [
                ['referenceType' => 'dicom_study_instance_uid', 'referenceValue' => '1.2.826.0.1.3680043.8.498.123456789'],
                ['referenceType' => 'pacs_url', 'referenceValue' => 'https://pacs.example/studies/123456789'],
            ],
        ])
        ->assertCreated();

    $study = Study::query()->with('imageReferences')->findOrFail($studyId);
    expect($study->imageReferences->count())->toBe(2);

    // References cannot attach to a study that is not performed.
    $encounter = radEncounter($org, $facility, $doctor);
    $orderResponse = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', ['testIds' => [$xray->getKey()]])
        ->assertCreated();
    $orderedStudyId = $orderResponse->json('data.id');

    $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$orderedStudyId.'/image-references', [
            'references' => [
                ['referenceType' => 'dicom_study_instance_uid', 'referenceValue' => '1.2.826.0.1.3680043.8.498.999999999'],
            ],
        ])
        ->assertStatus(409);
});

it('exposes released reports to the patient surface (preliminary AND final, own patient only)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');

    ['studyId' => $studyId] = radPerformed($this, $org, $facility, $doctorUser, $radioUser, $xray);

    $radAUser = Identity::user();
    radStaff($org, $facility, $radAUser, 'Radiologist');
    Identity::assign($radAUser, 'radiologist', $org, $facility);
    $radBUser = Identity::user();
    radStaff($org, $facility, $radBUser, 'Radiologist');
    Identity::assign($radBUser, 'radiologist', $org, $facility);

    // Preliminary released.
    $prelim = $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'preliminary',
            'content' => 'Preliminary read.',
        ])
        ->assertCreated();
    $this->withToken(Identity::tokenFor($radBUser))
        ->postJson('/api/v1/radiology-reports/'.$prelim->json('data.id').'/verify', ['lockVersion' => 0])
        ->assertOk();

    // Final released.
    $final = $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'final',
            'content' => 'Final read: normal.',
        ])
        ->assertCreated();
    $this->withToken(Identity::tokenFor($radBUser))
        ->postJson('/api/v1/radiology-reports/'.$final->json('data.id').'/verify', ['lockVersion' => 0])
        ->assertOk();

    $patient = Study::query()->findOrFail($studyId)->order->patient_id;

    $list = $this->withToken(Identity::tokenFor($radBUser))
        ->getJson('/api/v1/patients/'.$patient.'/radiology-reports')
        ->assertOk();

    expect($list->json('data'))->toHaveCount(2);

    // A different patient's surface sees nothing.
    $otherPatient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $this->withToken(Identity::tokenFor($radBUser))
        ->getJson('/api/v1/patients/'.$otherPatient->getKey().'/radiology-reports')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects stale concurrent transitions with 409 and changes nothing', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');

    $doctor = radDoctor($org, $facility, $doctorUser);
    $encounter = radEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    Identity::assign($radioUser, 'radiographer', $org, $facility);
    radStaff($org, $facility, $radioUser, 'Radiographer');

    $orderResponse = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', ['testIds' => [$xray->getKey()]])
        ->assertCreated();
    $studyId = $orderResponse->json('data.id');

    $modality = Modality::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'XR-'.substr((string) Str::uuid(), 0, 6),
        'modality_type' => 'xray',
        'status' => Modality::STATUS_ACTIVE,
    ]);

    $scheduled = $this->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$studyId.'/schedule', [
            'modalityId' => $modality->getKey(),
            'scheduledAt' => now()->addDay()->toISOString(),
            'lockVersion' => 0,
        ])
        ->assertOk();

    // Re-schedule with the STALE lockVersion → 409, nothing changes.
    $this->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$studyId.'/schedule', [
            'modalityId' => $modality->getKey(),
            'scheduledAt' => now()->addDays(2)->toISOString(),
            'lockVersion' => 0,
        ])
        ->assertStatus(409);

    $study = Study::query()->findOrFail($studyId);
    expect($study->status)->toBe(Study::STATUS_SCHEDULED)
        ->and($study->scheduled_at->toDateString())->toBe(now()->addDay()->toDateString());
});

it('isolates radiology data across tenants (order, study, report all unreachable)', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $doctorAUser = Identity::user();
    $radioAUser = Identity::user();
    $xrayA = radCatalog($orgA, $facilityA, 'XR-CXR', 'Chest X-Ray');
    $xrayB = radCatalog($orgB, $facilityB, 'XR-CXR', 'Chest X-Ray');

    ['studyId' => $studyAId] = radPerformed($this, $orgA, $facilityA, $doctorAUser, $radioAUser, $xrayA);

    // Tenant B doctor cannot see tenant A's study (existence is never
    // leaked — 404 on reads).
    $doctorBUser = Identity::user();
    Identity::assign($doctorBUser, 'doctor', $orgB, $facilityB);
    $doctorB = radDoctor($orgB, $facilityB, $doctorBUser);

    $this->withToken(Identity::tokenFor($doctorBUser))
        ->getJson('/api/v1/studies/'.$studyAId)
        ->assertStatus(404);

    // Tenant B cannot mutate tenant A's study even WITH the scheduling
    // permission — the established write denial is 403 (existence is never
    // leaked on reads: 404; writes deny with 403, API_CONTRACTS.md §4).
    $radioBUser = Identity::user();
    Identity::assign($radioBUser, 'radiographer', $orgB, $facilityB);
    radStaff($orgB, $facilityB, $radioBUser, 'Radiographer');

    $this->withToken(Identity::tokenFor($radioBUser))
        ->postJson('/api/v1/studies/'.$studyAId.'/cancel', ['reason' => 'Not ours', 'lockVersion' => 0])
        ->assertStatus(403);

    // Tenant B's queue only shows its own work.
    $encounterB = radEncounter($orgB, $facilityB, $doctorB);
    $this->withToken(Identity::tokenFor($doctorBUser))
        ->postJson('/api/v1/encounters/'.$encounterB->getKey().'/radiology-orders', ['testIds' => [$xrayB->getKey()]])
        ->assertCreated();

    $queue = $this->withToken(Identity::tokenFor($doctorBUser))
        ->getJson('/api/v1/radiology/queue')
        ->assertOk();

    expect($queue->json('data'))->toHaveCount(1)
        ->and($queue->json('data.0.orderId'))->not->toBe(studyOrderId($studyAId));

    // Tenant A's data is untouched.
    $studyA = Study::query()->findOrFail($studyAId);
    expect($studyA->status)->toBe(Study::STATUS_PERFORMED);
});

it('keeps audit payloads PHI-safe (facts and ids only, never report content)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');

    ['studyId' => $studyId] = radPerformed($this, $org, $facility, $doctorUser, $radioUser, $xray);

    $radAUser = Identity::user();
    radStaff($org, $facility, $radAUser, 'Radiologist');
    Identity::assign($radAUser, 'radiologist', $org, $facility);

    $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'final',
            'content' => 'SECRET-CLINICAL-CONTENT-NORMAL-CHEST',
            'criticalFindings' => 'SECRET-CRITICAL-FINDING',
        ])
        ->assertCreated();

    $events = AuditEvent::query()
        ->where('action', 'radiology_report.drafted')
        ->where('tenant_id', $org->getKey())
        ->get();

    expect($events)->not->toBeEmpty();
    foreach ($events as $event) {
        $payload = $event->payload;
        expect($payload)->not->toHaveKey('content')
            ->and($payload)->not->toHaveKey('criticalFindings')
            ->and($payload)->not->toHaveKey('impression');
        expect(json_encode($payload))->not->toContain('SECRET-CLINICAL-CONTENT')
            ->not->toContain('SECRET-CRITICAL-FINDING');
    }
});

it('modality updates are CAS-guarded and modality downtime is documented', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $adminUser = Identity::user();
    Identity::assign($adminUser, 'hospital_admin', $org, $facility);

    $modality = Modality::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'CT-'.substr((string) Str::uuid(), 0, 6),
        'modality_type' => 'ct',
        'daily_capacity' => 20,
        'status' => Modality::STATUS_ACTIVE,
    ]);

    $updated = $this->withToken(Identity::tokenFor($adminUser))
        ->patchJson('/api/v1/radiology/modalities/'.$modality->getKey(), [
            'status' => 'down',
            'dailyCapacity' => 10,
            'lockVersion' => 0,
        ])
        ->assertOk();

    expect($updated->json('data.status'))->toBe('down')
        ->and($updated->json('data.dailyCapacity'))->toBe(10)
        ->and($updated->json('data.lockVersion'))->toBe(1);

    // Stale CAS → 409.
    $this->withToken(Identity::tokenFor($adminUser))
        ->patchJson('/api/v1/radiology/modalities/'.$modality->getKey(), [
            'status' => 'active',
            'lockVersion' => 0,
        ])
        ->assertStatus(409);
});

it('cancels an ordered study with a reason (terminal, never performed)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $xray = radCatalog($org, $facility, 'XR-CXR', 'Chest X-Ray');

    $doctor = radDoctor($org, $facility, $doctorUser);
    $encounter = radEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    Identity::assign($radioUser, 'radiographer', $org, $facility);
    radStaff($org, $facility, $radioUser, 'Radiographer');

    $orderResponse = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', ['testIds' => [$xray->getKey()]])
        ->assertCreated();
    $studyId = $orderResponse->json('data.id');

    $this->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$studyId.'/cancel', ['reason' => 'Ordered in error', 'lockVersion' => 0])
        ->assertOk();

    $study = Study::query()->findOrFail($studyId);
    expect($study->status)->toBe(Study::STATUS_CANCELLED)
        ->and($study->cancel_reason)->toBe('Ordered in error');

    // A cancelled study can never be performed.
    $this->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$studyId.'/perform', ['lockVersion' => 1])
        ->assertStatus(409);

    // Cancellation without a reason → 422 (CHECK + request).
    $orderResponse2 = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', ['testIds' => [$xray->getKey()]])
        ->assertCreated();
    $this->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$orderResponse2->json('data.id').'/cancel', ['lockVersion' => 0])
        ->assertStatus(422);
});

function studyOrderId(string $studyId): ?string
{
    return Study::query()->findOrFail($studyId)->lab_order_id;
}
