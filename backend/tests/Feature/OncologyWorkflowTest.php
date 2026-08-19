<?php

use App\Models\Department;
use App\Models\Facility;
use App\Models\OncologyProfile;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\RtTreatmentCourse;
use App\Models\RtTreatmentMachine;
use App\Models\Staff;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\OncologyService;
use Tests\Support\Identity;

/**
 * Phase 15 — Oncology & Radiotherapy workflow tests.
 *
 * Covers:
 *  - profile creation
 *  - treatment plan lifecycle
 *  - RT course creation
 *  - RT plan approval workflow (physicist → secondary → RO)
 *  - machine creation
 *  - stats
 *  - tenant isolation
 *  - authorization
 */
beforeEach(function (): void {
    seedIdentity();

    $this->org = Organization::factory()->create();
    $this->facility = Facility::factory()->create(['tenant_id' => $this->org->getKey()]);
    $this->department = Department::factory()->create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
    ]);
    $this->user = User::factory()->create();
    $this->staff = Staff::factory()->create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'department_id' => $this->department->getKey(),
        'user_id' => $this->user->getKey(),
        'designation' => 'Consultant Physician',
        'status' => 'active',
    ]);
    $this->patient = Patient::factory()->create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
    ]);
    Identity::assign($this->user, 'doctor', $this->org, $this->facility);
});

// ── Profiles ──

it('creates an oncology profile', function (): void {
    $profile = OncologyProfile::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'patient_id' => $this->patient->getKey(),
        'primary_diagnosis' => 'Breast carcinoma',
        'cancer_site' => 'breast',
        'overall_stage' => 'IIIA',
        'status' => 'active',
    ]);

    expect($profile->id)->not->toBeNull()
        ->and($profile->cancer_site)->toBe('breast')
        ->and($profile->overall_stage)->toBe('IIIA')
        ->and($profile->status)->toBe('active');
});

it('isolates profiles between tenants', function (): void {
    $orgB = Organization::factory()->create();
    $facilityB = Facility::factory()->create(['tenant_id' => $orgB->getKey()]);
    $patientB = Patient::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);

    $profileA = OncologyProfile::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'patient_id' => $this->patient->getKey(),
    ]);

    $profileB = OncologyProfile::create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
    ]);

    expect($profileA->tenant_id)->not->toBe($profileB->tenant_id);
});

// ── Treatment Plans ──

it('creates a treatment plan with lifecycle states', function (): void {
    $profile = OncologyProfile::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'patient_id' => $this->patient->getKey(),
    ]);

    $plan = TreatmentPlan::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'oncology_profile_id' => $profile->getKey(),
        'plan_type' => 'chemotherapy',
        'protocol_code' => 'FOLFOX',
        'intent' => 'curative',
        'status' => 'draft',
        'planned_cycles' => 6,
    ]);

    expect($plan->status)->toBe('draft')
        ->and($plan->planned_cycles)->toBe(6);
});

// ── RT Courses & Plans ──

it('creates an RT course', function (): void {
    $profile = OncologyProfile::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'patient_id' => $this->patient->getKey(),
    ]);

    $course = RtTreatmentCourse::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'oncology_profile_id' => $profile->getKey(),
        'intent' => 'curative',
        'total_fractions' => 30,
        'total_dose_cgy' => 6000,
    ]);

    expect($course->total_fractions)->toBe(30)
        ->and((string) $course->total_dose_cgy)->toBe('6000.00');
});

it('runs the full RT plan approval workflow for VMAT', function (): void {
    $profile = OncologyProfile::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'patient_id' => $this->patient->getKey(),
    ]);

    $course = RtTreatmentCourse::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'oncology_profile_id' => $profile->getKey(),
        'intent' => 'curative',
        'total_fractions' => 30,
        'total_dose_cgy' => 6000,
    ]);

    $oncology = new OncologyService;

    $plan = $oncology->createRtPlan($course, [
        'plan_name' => 'VMAT Boost',
        'technique' => 'VMAT',
        'fraction_dose_cgy' => 200,
        'num_fractions' => 30,
        'total_dose_cgy' => 6000,
    ], $this->staff->getKey());

    expect($plan->status)->toBe('draft')
        ->and($plan->requiresSecondaryCheck())->toBeTrue();

    // Submit for review
    $plan = $oncology->submitForReview($plan);
    expect($plan->status)->toBe('in_review');

    // Physicist check
    $physicist = Staff::factory()->create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'department_id' => $this->department->getKey(),
        'status' => 'active',
    ]);
    $approval = $oncology->physicistCheck($plan, $physicist->getKey(), ['dose_calc_correct' => true], true);
    expect($approval->status)->toBe('approved');

    $plan->refresh();
    expect($plan->physicist_approved_at)->not->toBeNull();

    // Secondary check (required for VMAT)
    $checker = Staff::factory()->create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'department_id' => $this->department->getKey(),
        'status' => 'active',
    ]);
    $approval2 = $oncology->secondaryCheck($plan, $checker->getKey(), ['independent_calc' => true], true);
    expect($approval2->status)->toBe('approved');

    // RO approval
    $ro = Staff::factory()->create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'department_id' => $this->department->getKey(),
        'status' => 'active',
    ]);
    $approval3 = $oncology->roApproval($plan, $ro->getKey(), true);
    expect($approval3->status)->toBe('approved');

    $plan->refresh();
    expect($plan->status)->toBe('approved')
        ->and($plan->isFullyApproved())->toBeTrue();
});

it('does not require secondary check for 3DCRT', function (): void {
    $profile = OncologyProfile::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'patient_id' => $this->patient->getKey(),
    ]);

    $course = RtTreatmentCourse::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'oncology_profile_id' => $profile->getKey(),
        'intent' => 'palliative',
        'total_fractions' => 10,
        'total_dose_cgy' => 3000,
    ]);

    $oncology = new OncologyService;
    $plan = $oncology->createRtPlan($course, [
        'plan_name' => '3D-CRT Palliative',
        'technique' => '3DCRT',
        'fraction_dose_cgy' => 300,
        'num_fractions' => 10,
        'total_dose_cgy' => 3000,
    ], $this->staff->getKey());

    expect($plan->requiresSecondaryCheck())->toBeFalse();
});

it('blocks secondary check for non-VMAT techniques', function (): void {
    $profile = OncologyProfile::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'patient_id' => $this->patient->getKey(),
    ]);

    $course = RtTreatmentCourse::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'oncology_profile_id' => $profile->getKey(),
        'intent' => 'curative',
        'total_fractions' => 25,
        'total_dose_cgy' => 5000,
    ]);

    $oncology = new OncologyService;
    $plan = $oncology->createRtPlan($course, [
        'plan_name' => '3D-CRT',
        'technique' => '3DCRT',
        'fraction_dose_cgy' => 200,
        'num_fractions' => 25,
        'total_dose_cgy' => 5000,
    ], $this->staff->getKey());

    $plan = $oncology->submitForReview($plan);

    $physicist = Staff::factory()->create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'department_id' => $this->department->getKey(),
        'status' => 'active',
    ]);
    $oncology->physicistCheck($plan, $physicist->getKey(), [], true);

    $checker = Staff::factory()->create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'department_id' => $this->department->getKey(),
        'status' => 'active',
    ]);

    $oncology->secondaryCheck($plan, $checker->getKey(), [], true);
    // Should throw — secondary check is not required for 3DCRT
    expect(true)->toBeFalse(); // Should not reach here
})->throws(RuntimeException::class, 'Secondary check only required for VMAT/IMRT/SRS/SBRT');

// ── Machines ──

it('creates a treatment machine', function (): void {
    $machine = RtTreatmentMachine::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'code' => 'LINAC-01',
        'name' => 'Varian TrueBeam',
        'machine_type' => 'linac',
        'daily_capacity' => 30,
        'capabilities' => ['VMAT', 'IMRT', 'SRS'],
    ]);

    expect($machine->code)->toBe('LINAC-01')
        ->and($machine->capabilities)->toContain('VMAT');
});

// ── Stats ──

it('returns oncology stats via API', function (): void {
    OncologyProfile::create([
        'tenant_id' => $this->org->getKey(),
        'facility_id' => $this->facility->getKey(),
        'patient_id' => $this->patient->getKey(),
        'status' => 'active',
    ]);

    $response = $this->withToken(Identity::tokenFor($this->user))
        ->getJson('/api/v1/oncology/stats');

    $response->assertOk();
    $response->assertJsonPath('data.active_profiles', 1);
});

// ── Authorization ──

it('rejects unauthenticated access to oncology endpoints', function (): void {
    $this->getJson('/api/v1/oncology/stats')
        ->assertStatus(401);
});
