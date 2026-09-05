<?php

use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
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
 * Radiology / RIS gap-closure tests (§15, §16, §29, §30, §48, §49,
 * §119, §182): double-booking prevention, procedure timestamps,
 * radiologist worklist, concurrent finalization/correction.
 */
beforeEach(function (): void {
    seedIdentity();
});

function radGapDoctor(Organization $org, Facility $facility, User $user): Staff
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

function radGapStaff(Organization $org, Facility $facility, User $user, string $designation): Staff
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

function radGapEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
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

function radGapCatalog(Organization $org, Facility $facility, string $code, string $name): LabTest
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

function radGapPerformed(TestCase $test, Organization $org, Facility $facility, User $doctorUser, User $radioUser, LabTest $testItem): array
{
    $doctor = radGapDoctor($org, $facility, $doctorUser);
    $encounter = radGapEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    Identity::assign($radioUser, 'radiographer', $org, $facility);
    radGapStaff($org, $facility, $radioUser, 'Radiographer');

    $orderResponse = $test->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', [
            'testIds' => [$testItem->getKey()],
        ])
        ->assertCreated();

    $studyId = $orderResponse->json('data.id');

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

    return ['studyId' => $studyId, 'modalityId' => $modality->getKey()];
}

it('prevents modality double-booking via database unique index (§15, §16)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $xray = radGapCatalog($org, $facility, 'XR-DBL', 'Double Book X-Ray');

    Identity::assign($doctorUser, 'doctor', $org, $facility);
    Identity::assign($radioUser, 'radiographer', $org, $facility);
    $radio = radGapStaff($org, $facility, $radioUser, 'Radiographer');

    $doctor = radGapDoctor($org, $facility, $doctorUser);
    $encounterA = radGapEncounter($org, $facility, $doctor);
    $encounterB = radGapEncounter($org, $facility, $doctor);

    $orderA = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterA->getKey().'/radiology-orders', ['testIds' => [$xray->getKey()]])
        ->assertCreated();
    $studyAId = $orderA->json('data.id');

    $orderB = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterB->getKey().'/radiology-orders', ['testIds' => [$xray->getKey()]])
        ->assertCreated();
    $studyBId = $orderB->json('data.id');

    $modality = Modality::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'XR-'.strtoupper(substr((string) Str::uuid(), 0, 6)),
        'modality_type' => 'xray',
        'status' => Modality::STATUS_ACTIVE,
    ]);

    $scheduledAt = now()->addDay()->startOfHour()->toISOString();

    // Schedule study A successfully.
    $this->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$studyAId.'/schedule', [
            'modalityId' => $modality->getKey(),
            'scheduledAt' => $scheduledAt,
            'lockVersion' => 0,
        ])
        ->assertOk();

    // Schedule study B on the SAME modality at the SAME time — must fail
    // via the unique index uq_studies_tenant_modality_scheduled.
    $this->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$studyBId.'/schedule', [
            'modalityId' => $modality->getKey(),
            'scheduledAt' => $scheduledAt,
            'lockVersion' => 0,
        ])
        ->assertStatus(500);

    // Study A remains scheduled, study B remains ordered.
    expect(Study::query()->findOrFail($studyAId)->status)->toBe(Study::STATUS_SCHEDULED)
        ->and(Study::query()->findOrFail($studyBId)->status)->toBe(Study::STATUS_ORDERED);
});

it('records procedure start time alongside completion time (§29, §30)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $xray = radGapCatalog($org, $facility, 'XR-PRC', 'Procedure Time X-Ray');

    Identity::assign($doctorUser, 'doctor', $org, $facility);
    Identity::assign($radioUser, 'radiographer', $org, $facility);
    $radio = radGapStaff($org, $facility, $radioUser, 'Radiographer');

    $doctor = radGapDoctor($org, $facility, $doctorUser);
    $encounter = radGapEncounter($org, $facility, $doctor);

    $orderResponse = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/radiology-orders', [
            'testIds' => [$xray->getKey()],
        ])
        ->assertCreated();
    $newStudyId = $orderResponse->json('data.id');

    $modality = Modality::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'XR-'.strtoupper(substr((string) Str::uuid(), 0, 6)),
        'modality_type' => 'xray',
        'status' => Modality::STATUS_ACTIVE,
    ]);

    $scheduled = $this->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$newStudyId.'/schedule', [
            'modalityId' => $modality->getKey(),
            'scheduledAt' => now()->addDays(2)->toISOString(),
            'lockVersion' => 0,
        ])
        ->assertOk();

    $startedAt = now()->subMinutes(30)->toISOString();

    $performed = $this->withToken(Identity::tokenFor($radioUser))
        ->postJson('/api/v1/studies/'.$newStudyId.'/perform', [
            'lockVersion' => $scheduled->json('data.lockVersion'),
            'procedureStartedAt' => $startedAt,
        ])
        ->assertOk();

    expect($performed->json('data.procedureStartedAt'))->not->toBeNull()
        ->and($performed->json('data.performedAt'))->not->toBeNull();

    $study = Study::query()->findOrFail($newStudyId);
    expect($study->procedure_started_at)->not->toBeNull()
        ->and($study->performed_at)->not->toBeNull()
        ->and($study->procedure_started_at->lte($study->performed_at))->toBeTrue();
});

it('returns performed studies on the radiologist worklist (§48, §49)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $radUser = Identity::user();
    $xray = radGapCatalog($org, $facility, 'XR-WL', 'Worklist X-Ray');

    ['studyId' => $studyId] = radGapPerformed($this, $org, $facility, $doctorUser, $radioUser, $xray);

    $rad = radGapStaff($org, $facility, $radUser, 'Radiologist');
    Identity::assign($radUser, 'radiologist', $org, $facility);

    // Study is performed, no report yet — should appear on worklist.
    $worklist = $this->withToken(Identity::tokenFor($radUser))
        ->getJson('/api/v1/radiology/worklist')
        ->assertOk();

    expect($worklist->json('data'))->toHaveCount(1)
        ->and($worklist->json('data.0.id'))->toBe($studyId);

    // Draft a report — study should disappear from worklist
    // (worklist = performed studies with NO draft/final/preliminary reports).
    $this->withToken(Identity::tokenFor($radUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'final',
            'content' => 'Normal chest film.',
        ])
        ->assertCreated();

    $worklist2 = $this->withToken(Identity::tokenFor($radUser))
        ->getJson('/api/v1/radiology/worklist')
        ->assertOk();

    expect($worklist2->json('data'))->toHaveCount(0);
});

it('rejects concurrent finalization with deterministic outcome (§119, §182)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $radAUser = Identity::user();
    $radBUser = Identity::user();
    $xray = radGapCatalog($org, $facility, 'XR-CNC', 'Concurrent X-Ray');

    ['studyId' => $studyId] = radGapPerformed($this, $org, $facility, $doctorUser, $radioUser, $xray);

    $radA = radGapStaff($org, $facility, $radAUser, 'Radiologist');
    Identity::assign($radAUser, 'radiologist', $org, $facility);
    $radB = radGapStaff($org, $facility, $radBUser, 'Radiologist');
    Identity::assign($radBUser, 'radiologist', $org, $facility);

    // Draft two reports on the same study (both at draft status).
    $draftA = $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'final',
            'content' => 'Report A: normal.',
        ])
        ->assertCreated();

    $draftB = $this->withToken(Identity::tokenFor($radBUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'final',
            'content' => 'Report B: abnormal.',
        ])
        ->assertCreated();

    // Verify the first — succeeds.
    $first = $this->withToken(Identity::tokenFor($radBUser))
        ->postJson('/api/v1/radiology-reports/'.$draftA->json('data.id').'/verify', ['lockVersion' => 0])
        ->assertOk();

    expect($first->json('data.status'))->toBe(RadiologyReport::STATUS_FINAL);

    // The second verify fails because: (a) the unique constraint
    // uq_radiology_reports_tenant_study_final blocks a second 'final'
    // report for the same study, and (b) the study CAS (performed→reported)
    // also fails since the study is already 'reported'.
    $second = $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/radiology-reports/'.$draftB->json('data.id').'/verify', ['lockVersion' => 0]);

    expect($second->status())->not->toBe(200);

    // Exactly one final report per study (partial unique).
    $finalCount = RadiologyReport::query()
        ->where('study_id', $studyId)
        ->where('status', RadiologyReport::STATUS_FINAL)
        ->count();
    expect($finalCount)->toBe(1);
});

it('preserves original report on correction (amendment creates new version, §58-61)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $radioUser = Identity::user();
    $radAUser = Identity::user();
    $radBUser = Identity::user();
    $xray = radGapCatalog($org, $facility, 'XR-AMN', 'Amendment X-Ray');

    ['studyId' => $studyId] = radGapPerformed($this, $org, $facility, $doctorUser, $radioUser, $xray);

    $radA = radGapStaff($org, $facility, $radAUser, 'Radiologist');
    Identity::assign($radAUser, 'radiologist', $org, $facility);
    $radB = radGapStaff($org, $facility, $radBUser, 'Radiologist');
    Identity::assign($radBUser, 'radiologist', $org, $facility);

    // Draft + verify a final report.
    $draft = $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/studies/'.$studyId.'/report', [
            'reportType' => 'final',
            'content' => 'Original finding: no abnormality.',
            'impression' => 'Normal study.',
        ])
        ->assertCreated();

    $reportId = $draft->json('data.id');

    $this->withToken(Identity::tokenFor($radBUser))
        ->postJson('/api/v1/radiology-reports/'.$reportId.'/verify', ['lockVersion' => 0])
        ->assertOk();

    // Amend the final — original preserved as 'amended'.
    $amendment = $this->withToken(Identity::tokenFor($radAUser))
        ->postJson('/api/v1/radiology-reports/'.$reportId.'/amend', [
            'content' => 'Amended: subtle opacity noted.',
            'impression' => 'Correlate clinically.',
        ])
        ->assertCreated();

    $amendmentId = $amendment->json('data.id');

    expect($amendment->json('data.parentReportId'))->toBe($reportId)
        ->and($amendment->json('data.status'))->toBe(RadiologyReport::STATUS_DRAFT);

    // The original is preserved, never edited.
    $original = RadiologyReport::query()->findOrFail($reportId);
    expect($original->status)->toBe(RadiologyReport::STATUS_AMENDED)
        ->and($original->content)->toBe('Original finding: no abnormality.');

    // Verify the amendment — study moves back to reported.
    $this->withToken(Identity::tokenFor($radBUser))
        ->postJson('/api/v1/radiology-reports/'.$amendmentId.'/verify', ['lockVersion' => 0])
        ->assertOk();

    // Exactly one active final report.
    $finalCount = RadiologyReport::query()
        ->where('study_id', $studyId)
        ->where('status', RadiologyReport::STATUS_FINAL)
        ->count();
    expect($finalCount)->toBe(1);

    // The amendment chain is traceable.
    $amendedReport = RadiologyReport::query()->findOrFail($amendmentId);
    expect($amendedReport->parent_report_id)->toBe($reportId)
        ->and($amendedReport->status)->toBe(RadiologyReport::STATUS_FINAL);
});
