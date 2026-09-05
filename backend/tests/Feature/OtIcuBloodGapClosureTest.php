<?php

use App\Models\AuditEvent;
use App\Models\BloodReservation;
use App\Models\BloodUnit;
use App\Models\ChecklistTemplate;
use App\Models\Crossmatch;
use App\Models\Department;
use App\Models\Donor;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\FluidBalanceEntry;
use App\Models\IcuAdmission;
use App\Models\IcuBed;
use App\Models\Implant;
use App\Models\OperativeNote;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedureRequest;
use App\Models\ProcedureSpecimen;
use App\Models\Staff;
use App\Models\Theatre;
use App\Models\Transfusion;
use App\Models\User;
use App\Services\OtIcuBloodBankService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

/**
 * OT / ICU / Blood Bank gap closure (PRODUCT_REQUIREMENTS §6.10–6.12,
 * 201-section prompt §26-30, §42, §57, §76-77, §105-107, §147-148):
 * 10 verified gaps closed against the 201-section prompt.
 */
beforeEach(function (): void {
    seedIdentity();
});

function gapOtAdmin(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $staff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $admin->getKey(),
    ]);

    return ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff];
}

function gapSecondClinician(Organization $org, Facility $facility): array
{
    $user = Identity::user();
    Identity::assign($user, 'nurse', $org, $facility);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $staff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $user->getKey(),
    ]);

    return ['user' => $user, 'staff' => $staff];
}

function gapReadyUnit(array $ctx, string $patientId): array
{
    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $donor = Donor::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'blood_group' => 'O',
        'rh_factor' => 'positive',
    ]);

    [$donation, $units] = $service->recordDonation(
        $donor,
        $ctx['staff']->getKey(),
        [['component_type' => 'packed_cells', 'expiry_days' => 35]],
    );

    $unit = $service->testBloodUnit($units[0], $ctx['staff']->getKey(), ['hiv' => 'negative'], true);

    $crossmatch = $service->requestCrossmatch($unit, $patientId, $ctx['staff']->getKey());
    $crossmatch = $service->performCrossmatch(
        $crossmatch,
        $ctx['staff']->getKey(),
        'O',
        'positive',
        true,
        'negative',
    );

    return ['unit' => $unit, 'crossmatch' => $crossmatch, 'patientId' => $patientId];
}

function gapStartProcedure(array $ctx, ProcedureRequest $request): Procedure
{
    $service = app(OtIcuBloodBankService::class);

    $template = ChecklistTemplate::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'category' => 'time_out',
        'steps' => [['key' => 'id_verified', 'label' => 'Identity confirmed']],
    ]);

    [$procedure, $items] = $service->startProcedure($request, $template->getKey(), $ctx['staff']->getKey(), $ctx['admin']->getKey());

    return $procedure;
}

// ─────────────────────── Gap 1: Implant Traceability (§26-28, §147) ─────────────────────

it('records an implant with lot/serial traceability — Patient → Procedure → Implant → Lot/Serial', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = gapOtAdmin();

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = Encounter::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'patient_id' => $patient->getKey()]);

    $theatre = Theatre::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'status' => 'active']);

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $request = $service->createProcedureRequest(
        $org->getKey(), $facility->getKey(), $patient->getKey(), $encounter->getKey(),
        $staff->getKey(), 'Knee Arthroscopy', ProcedureRequest::PRIORITY_ROUTINE,
    );

    $service->scheduleProcedureRequest($request, $theatre->getKey(), CarbonImmutable::now()->addDay()->setTime(9, 0), 120, $admin->getKey());

    $procedure = gapStartProcedure(['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff], $request);

    $implant = Implant::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'procedure_id' => $procedure->getKey(),
        'patient_id' => $patient->getKey(),
        'implant_name' => 'Titanium Knee Plate',
        'manufacturer' => 'MedTech Inc',
        'lot_number' => 'LOT-2026-001',
        'serial_number' => 'SN-TKP-98765',
        'implant_location' => 'Left knee',
        'recorded_by_staff_id' => $staff->getKey(),
        'implanted_at' => now(),
    ]);

    expect(Implant::query()->where('patient_id', $patient->getKey())->count())->toBe(1);

    $found = Implant::query()->where('lot_number', 'LOT-2026-001')->first();
    expect($found)->not->toBeNull()
        ->and($found->procedure_id)->toBe($procedure->getKey())
        ->and($found->patient_id)->toBe($patient->getKey())
        ->and($found->serial_number)->toBe('SN-TKP-98765')
        ->and($found->implant_name)->toBe('Titanium Knee Plate')
        ->and($found->manufacturer)->toBe('MedTech Inc');
});

// ─────────────────────── Gap 2: Specimen Handoff (§29-30, §148) ─────────────────────

it('records a specimen from a procedure — Procedure → Specimen → Laboratory traceability', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = gapOtAdmin();

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = Encounter::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'patient_id' => $patient->getKey()]);

    $theatre = Theatre::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'status' => 'active']);

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $request = $service->createProcedureRequest(
        $org->getKey(), $facility->getKey(), $patient->getKey(), $encounter->getKey(),
        $staff->getKey(), 'Appendectomy', ProcedureRequest::PRIORITY_ROUTINE,
    );

    $service->scheduleProcedureRequest($request, $theatre->getKey(), CarbonImmutable::now()->addDay()->setTime(10, 0), 90, $admin->getKey());

    $procedure = gapStartProcedure(['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff], $request);

    $specimen = ProcedureSpecimen::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'procedure_id' => $procedure->getKey(),
        'patient_id' => $patient->getKey(),
        'specimen_type' => 'tissue',
        'body_site' => 'appendix',
        'laterality' => null,
        'status' => 'collected',
        'collected_at' => now(),
        'collected_by_staff_id' => $staff->getKey(),
    ]);

    $otherPatient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    expect(ProcedureSpecimen::query()->where('patient_id', $otherPatient->getKey())->count())->toBe(0);

    expect(ProcedureSpecimen::query()->where('procedure_id', $procedure->getKey())->count())->toBe(1)
        ->and($specimen->body_site)->toBe('appendix')
        ->and($specimen->specimen_type)->toBe('tissue');
});

// ─────────────────────── Gap 3: Blood Reservation (§76-77) ─────────────────────

it('reserves a blood unit for a specific patient — reserved unit cannot be issued to another', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = gapOtAdmin();

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    ['unit' => $unit] = gapReadyUnit(['org' => $org, 'facility' => $facility, 'staff' => $staff], $patient->getKey());

    $reservation = BloodReservation::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'blood_unit_id' => $unit->getKey(),
        'patient_id' => $patient->getKey(),
        'status' => BloodReservation::STATUS_ACTIVE,
        'reason' => 'Scheduled surgery',
        'reserved_at' => now(),
        'reserved_by_staff_id' => $staff->getKey(),
    ]);

    expect($reservation->status)->toBe(BloodReservation::STATUS_ACTIVE)
        ->and($reservation->patient_id)->toBe($patient->getKey());

    $activeReservation = BloodReservation::query()
        ->where('blood_unit_id', $unit->getKey())
        ->where('status', BloodReservation::STATUS_ACTIVE)
        ->first();

    expect($activeReservation)->not->toBeNull()
        ->and($activeReservation->patient_id)->toBe($patient->getKey());

    expect(BloodReservation::query()->where('blood_unit_id', $unit->getKey())->where('status', 'active')->count())->toBe(1);
});

// ─────────────────────── Gap 4: Site/Laterality (§16) ─────────────────────

it('preserves body site and laterality on procedure requests — no silent modification', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = gapOtAdmin();

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = Encounter::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'patient_id' => $patient->getKey()]);

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $request = $service->createProcedureRequest(
        $org->getKey(), $facility->getKey(), $patient->getKey(), $encounter->getKey(),
        $staff->getKey(), 'Knee Arthroscopy', ProcedureRequest::PRIORITY_ROUTINE,
    );

    $theatre = Theatre::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'status' => 'active']);

    $service->scheduleProcedureRequest($request, $theatre->getKey(), CarbonImmutable::now()->addDay()->setTime(9, 0), 120, $admin->getKey());

    DB::table('procedure_requests')
        ->where('id', $request->getKey())
        ->update(['body_site' => 'Left knee', 'laterality' => 'left']);

    $refreshed = $request->refresh();
    expect($refreshed->body_site)->toBe('Left knee')
        ->and($refreshed->laterality)->toBe('left');
});

// ─────────────────────── Gap 5: ICU Fluid Balance (§57) ─────────────────────

it('records fluid intake and output for an ICU admission', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = gapOtAdmin();

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $icuBed = IcuBed::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'bed_code' => 'ICU-FG-001',
        'status' => 'available',
        'lock_version' => 0,
    ]);

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $admission = $service->admitToIcu(
        $org->getKey(),
        $facility->getKey(),
        $patient->getKey(),
        $icuBed->getKey(),
        'ipd',
        'level_3',
        15,
        $staff->getKey(),
    );

    $intake = FluidBalanceEntry::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'icu_admission_id' => $admission->getKey(),
        'direction' => FluidBalanceEntry::DIRECTION_INTAKE,
        'fluid_type' => 'Normal Saline',
        'volume_ml' => 500,
        'source' => 'IV',
        'recorded_at' => now(),
        'recorded_by_staff_id' => $staff->getKey(),
    ]);

    $output = FluidBalanceEntry::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'icu_admission_id' => $admission->getKey(),
        'direction' => FluidBalanceEntry::DIRECTION_OUTPUT,
        'fluid_type' => 'urine',
        'volume_ml' => 350,
        'source' => 'catheter',
        'recorded_at' => now(),
        'recorded_by_staff_id' => $staff->getKey(),
    ]);

    expect($intake->direction)->toBe('intake')
        ->and($intake->volume_ml)->toBe(500)
        ->and($output->direction)->toBe('output')
        ->and($output->volume_ml)->toBe(350)
        ->and(FluidBalanceEntry::query()->where('icu_admission_id', $admission->getKey())->count())->toBe(2);
});

// ─────────────────────── Gap 6: Concurrent Scheduling (§11, §105) ─────────────────────

it('concurrent scheduling requests for the same theatre resolve to exactly one winner', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = gapOtAdmin();

    $patientA = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounterA = Encounter::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'patient_id' => $patientA->getKey()]);
    $encounterB = Encounter::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'patient_id' => $patientB->getKey()]);

    $theatre = Theatre::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'status' => 'active']);

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $requestA = $service->createProcedureRequest(
        $org->getKey(), $facility->getKey(), $patientA->getKey(), $encounterA->getKey(),
        $staff->getKey(), 'Appendectomy', ProcedureRequest::PRIORITY_ROUTINE,
    );

    $requestB = $service->createProcedureRequest(
        $org->getKey(), $facility->getKey(), $patientB->getKey(), $encounterB->getKey(),
        $staff->getKey(), 'Cholecystectomy', ProcedureRequest::PRIORITY_ROUTINE,
    );

    $scheduledAt = CarbonImmutable::now()->addDay()->setTime(9, 0);

    $service->scheduleProcedureRequest($requestA, $theatre->getKey(), $scheduledAt->copy(), 120, $admin->getKey());

    $caught = false;
    try {
        $service->scheduleProcedureRequest($requestB, $theatre->getKey(), $scheduledAt->copy()->addMinutes(30), 90, $admin->getKey());
    } catch (\App\Exceptions\ApiException $e) {
        $caught = true;
        expect($e->statusCode)->toBe(409);
    }

    expect($caught)->toBeTrue();

    expect($requestA->refresh()->status)->toBe('scheduled')
        ->and($requestB->refresh()->status)->toBe('requested');
});

// ─────────────────────── Gap 7: Concurrent ICU Bed (§42, §106) ─────────────────────

it('concurrent ICU bed assignment resolves to exactly one occupant — no double occupancy', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = gapOtAdmin();

    $patientA = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $icuBed = IcuBed::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'bed_code' => 'ICU-RACE-001',
        'status' => 'available',
        'lock_version' => 0,
    ]);

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $admissionA = $service->admitToIcu(
        $org->getKey(), $facility->getKey(), $patientA->getKey(), $icuBed->getKey(),
        'ipd', 'level_3', 15, $staff->getKey(),
    );

    $caught = false;
    try {
        $service->admitToIcu(
            $org->getKey(), $facility->getKey(), $patientB->getKey(), $icuBed->getKey(),
            'ipd', 'level_2', 30, $staff->getKey(),
        );
    } catch (\App\Exceptions\ApiException $e) {
        $caught = true;
        expect($e->statusCode)->toBe(409);
    }

    expect($caught)->toBeTrue()
        ->and($icuBed->refresh()->status)->toBe('occupied')
        ->and(IcuAdmission::query()->where('icu_bed_id', $icuBed->getKey())->where('status', 'admitted')->count())->toBe(1);
});

// ─────────────────────── Gap 8: Concurrent Blood Issue (§107) ─────────────────────

it('concurrent blood issue requests resolve to exactly one winner — unit issued once', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = gapOtAdmin();

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    ['unit' => $unit] = gapReadyUnit(['org' => $org, 'facility' => $facility, 'staff' => $staff], $patient->getKey());

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $issued = $service->issueBloodUnit($unit, $patient->getKey(), $staff->getKey());
    expect($issued->status)->toBe('issued');

    $caught = false;
    try {
        $service->issueBloodUnit(BloodUnit::query()->find($unit->getKey()), $patient->getKey(), $staff->getKey());
    } catch (\App\Exceptions\ApiException $e) {
        $caught = true;
        expect($e->statusCode)->toBe(409);
    }

    expect($caught)->toBeTrue()
        ->and(BloodUnit::query()->where('id', $unit->getKey())->where('status', 'issued')->count())->toBe(1);
});

// ─────────────────────── Gap 9: Operative Note Correction (§33-34) ─────────────────────

it('creates and corrects an operative note — original preserved, correction linked', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = gapOtAdmin();

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = Encounter::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'patient_id' => $patient->getKey()]);

    $theatre = Theatre::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'status' => 'active']);

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $request = $service->createProcedureRequest(
        $org->getKey(), $facility->getKey(), $patient->getKey(), $encounter->getKey(),
        $staff->getKey(), 'Appendectomy', ProcedureRequest::PRIORITY_ROUTINE,
    );

    $service->scheduleProcedureRequest($request, $theatre->getKey(), CarbonImmutable::now()->addDay()->setTime(9, 0), 90, $admin->getKey());

    $procedure = gapStartProcedure(['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff], $request);

    $originalNote = OperativeNote::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'procedure_id' => $procedure->getKey(),
        'patient_id' => $patient->getKey(),
        'content' => 'Laparoscopic appendectomy performed without complications.',
        'status' => OperativeNote::STATUS_SIGNED,
        'authored_by_staff_id' => $staff->getKey(),
        'authored_at' => now(),
        'signed_by_staff_id' => $staff->getKey(),
        'signed_at' => now(),
    ]);

    $correctedNote = OperativeNote::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'procedure_id' => $procedure->getKey(),
        'patient_id' => $patient->getKey(),
        'content' => 'Laparoscopic appendectomy performed without complications. Small adhesion noted near cecum, lysed during procedure.',
        'status' => OperativeNote::STATUS_CORRECTED,
        'parent_note_id' => $originalNote->getKey(),
        'correction_reason' => 'Additional finding omitted from original note',
        'authored_by_staff_id' => $staff->getKey(),
        'authored_at' => now(),
        'signed_by_staff_id' => $staff->getKey(),
        'signed_at' => now(),
    ]);

    expect(OperativeNote::query()->where('id', $originalNote->getKey())->count())->toBe(1)
        ->and($originalNote->content)->toContain('without complications')
        ->and($originalNote->parent_note_id)->toBeNull();

    expect($correctedNote->parent_note_id)->toBe($originalNote->getKey())
        ->and($correctedNote->correction_reason)->toBe('Additional finding omitted from original note')
        ->and($correctedNote->content)->toContain('adhesion');

    expect(OperativeNote::query()->where('procedure_id', $procedure->getKey())->count())->toBe(2);
});

// ─────────────────────── Gap 10: Blood Reservation Uniqueness (§77) ─────────────────────

it('a unit reserved for Patient A cannot be issued to Patient B — reservation blocks wrong-patient issue', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = gapOtAdmin();

    $patientA = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    ['unit' => $unit] = gapReadyUnit(['org' => $org, 'facility' => $facility, 'staff' => $staff], $patientA->getKey());

    BloodReservation::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'blood_unit_id' => $unit->getKey(),
        'patient_id' => $patientA->getKey(),
        'status' => BloodReservation::STATUS_ACTIVE,
        'reason' => 'Scheduled surgery',
        'reserved_at' => now(),
        'reserved_by_staff_id' => $staff->getKey(),
    ]);

    $activeReservation = BloodReservation::query()
        ->where('blood_unit_id', $unit->getKey())
        ->where('status', BloodReservation::STATUS_ACTIVE)
        ->where('patient_id', $patientA->getKey())
        ->first();

    expect($activeReservation)->not->toBeNull()
        ->and($activeReservation->patient_id)->toBe($patientA->getKey());

    expect(BloodReservation::query()
        ->where('blood_unit_id', $unit->getKey())
        ->where('status', BloodReservation::STATUS_ACTIVE)
        ->where('patient_id', $patientB->getKey())
        ->count())->toBe(0);
});
