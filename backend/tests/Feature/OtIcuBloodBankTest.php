<?php

use App\Models\AuditEvent;
use App\Models\BloodUnit;
use App\Models\ChecklistTemplate;
use App\Models\Crossmatch;
use App\Models\Department;
use App\Models\Donor;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\IcuAdmission;
use App\Models\IcuAlert;
use App\Models\IcuBed;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProcedureRequest;
use App\Models\ReactionReport;
use App\Models\RecoveryRecord;
use App\Models\Staff;
use App\Models\Theatre;
use App\Models\Transfusion;
use App\Models\User;
use App\Models\WarningScore;
use App\Services\OtIcuBloodBankService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Tests\Support\Identity;

/**
 * Phase 3 slice 20 — OT, ICU, and Blood Bank (ROADMAP Phase 16, PRODUCT
 * REQUIREMENTS §6.10–6.12, DATABASE.md §3.48–3.50).
 *
 * Life-critical safety proofs:
 *   - OT scheduling conflict detection (two cases on one theatre refused);
 *     checklist compliance gates case closure.
 *   - ICU observation schedules ENFORCED: a missed observation escalates
 *     (missed-observation is an incident by design); warning scores are
 *     COMPUTED and escalations/threshold breaches open alerts that MUST be
 *     acknowledged (who, when).
 *   - Blood-issue safety: expired or untested units are NEVER issuable;
 *     issue requires a compatible crossmatch; transfusion requires DUAL
 *     verification (starter ≠ verifier) and completion is refused until
 *     verified; wrong-unit transfusion is refused.
 *   - Donor personal data never appears in audit payloads.
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * A hospital-admin identity (facility-scoped, holds every ot:*, icu:* and
 * bloodbank:* permission) with a linked staff profile.
 *
 * @return array{org: Organization, facility: Facility, admin: User, staff: Staff}
 */
function otAdmin(): array
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

/**
 * A second staff member with a linked user in the same facility — the DUAL
 * verifier / second observer for dual-verification proofs.
 *
 * @return array{user: User, staff: Staff}
 */
function otSecondClinician(Organization $org, Facility $facility): array
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

/**
 * A second tenant + facility (the isolation target).
 *
 * @return array{org: Organization, facility: Facility}
 */
function otOtherTenant(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);

    return ['org' => $org, 'facility' => $facility];
}

/**
 * Build a fully tested, compatible blood unit ready for issue to a patient.
 *
 * @return array{unit: BloodUnit, crossmatch: Crossmatch, patientId: string}
 */
function otReadyUnit(array $ctx, string $patientId, array $attributes = []): array
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

// ─────────────────────────────── Operating Theatre ───────────────────────────────

it('manages the theatre catalog with RBAC', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin] = otAdmin();

    // Unauthenticated → 401.
    $this->postJson('/api/v1/theatres', [])->assertUnauthorized();

    // Receptionist (no ot:schedule) → 403.
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);
    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/theatres', ['code' => 'OT-1', 'name' => 'Main Theatre'])
        ->assertForbidden();

    // Admin creates and lists.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/theatres', ['code' => 'OT-1', 'name' => 'Main Theatre'])
        ->assertCreated()
        ->assertJsonPath('data.code', 'OT-1');

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/theatres')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Main Theatre');
});

it('schedules a procedure request with conflict detection — two cases on one theatre refused', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();

    $theatre = Theatre::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = Encounter::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'patient_id' => $patient->getKey()]);

    $createRequest = fn (): string => $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/procedure-requests', [
            'patientId' => $patient->getKey(),
            'encounterId' => $encounter->getKey(),
            'procedureName' => 'Cholecystectomy',
            'priority' => 'routine',
        ])
        ->assertCreated()
        ->json('data.id');

    $firstId = $createRequest();
    $secondId = $createRequest();

    $scheduledAt = now()->addDays(1)->setTime(9, 0)->toIso8601String();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/procedure-requests/{$firstId}/schedule", [
            'theatreId' => $theatre->getKey(),
            'scheduledAt' => $scheduledAt,
            'durationMinutes' => 120,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled');

    // Second case in the SAME theatre, OVERLAPPING window → 409 conflict.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/procedure-requests/{$secondId}/schedule", [
            'theatreId' => $theatre->getKey(),
            'scheduledAt' => $scheduledAt,
            'durationMinutes' => 90,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // A NON-overlapping window on the same theatre succeeds.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/procedure-requests/{$secondId}/schedule", [
            'theatreId' => $theatre->getKey(),
            'scheduledAt' => now()->addDays(1)->setTime(13, 0)->toIso8601String(),
            'durationMinutes' => 90,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'scheduled');
});

it('enforces checklist compliance — a case cannot close with an incomplete surgical safety checklist', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();

    $template = ChecklistTemplate::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'category' => ChecklistTemplate::CATEGORY_TIME_OUT,
        'steps' => [
            ['key' => 'id_verified', 'label' => 'Patient identity confirmed'],
            ['key' => 'site_marked', 'label' => 'Surgical site marked'],
            ['key' => 'procedure_confirmed', 'label' => 'Procedure confirmed'],
        ],
    ]);

    $theatre = Theatre::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = Encounter::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'patient_id' => $patient->getKey()]);

    $requestId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/procedure-requests', [
            'patientId' => $patient->getKey(),
            'encounterId' => $encounter->getKey(),
            'procedureName' => 'Appendectomy',
        ])
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/procedure-requests/{$requestId}/schedule", [
            'theatreId' => $theatre->getKey(),
            'scheduledAt' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'durationMinutes' => 60,
        ])
        ->assertOk();

    $started = $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/procedure-requests/{$requestId}/start", [
            'checklistTemplateId' => $template->getKey(),
            'surgeonStaffId' => $staff->getKey(),
        ])
        ->assertCreated()
        ->json('data');

    $procedureId = $started['id'];
    expect(count($started['checklist']))->toBe(3);

    // Close with incomplete checklist → 422 VALIDATION_ERROR.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/procedures/{$procedureId}/close")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // Complete ALL checklist steps (each records who/when).
    foreach ($started['checklist'] as $item) {
        $this->withToken(Identity::tokenFor($admin))
            ->postJson("/api/v1/procedures/{$procedureId}/checklist/{$item['id']}/complete")
            ->assertOk()
            ->assertJsonPath('data.completedAt', fn ($v) => $v !== null);
    }

    // A double completion of a step → 409 LOCK_CONFLICT (zero rows affected).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/procedures/{$procedureId}/checklist/{$started['checklist'][0]['id']}/complete")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'LOCK_CONFLICT');

    // Compliance gate satisfied → close succeeds.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/procedures/{$procedureId}/close")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    // The request completes together with the procedure.
    $this->withToken(Identity::tokenFor($admin))
        ->getJson("/api/v1/procedures/{$procedureId}")
        ->assertOk()
        ->assertJsonPath('data.checklist', fn ($checklist) => collect($checklist)->every(fn ($c) => $c['completedAt'] !== null));

    $audit = AuditEvent::query()->where('action', 'procedure.closed')->latest('occurred_at')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->payload)->toHaveKey('patientId')
        ->and($audit->payload)->not->toHaveKey('procedureName');
});

it('documents team, anesthesia, events, and recovery through discharge', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = Encounter::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'patient_id' => $patient->getKey()]);
    $theatre = Theatre::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $template = ChecklistTemplate::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'steps' => [['key' => 'id_verified', 'label' => 'Identity confirmed']],
    ]);

    $request = $service->createProcedureRequest(
        $org->getKey(), $facility->getKey(), $patient->getKey(), $encounter->getKey(),
        $staff->getKey(), 'Hernia repair',
    );

    $request = $service->scheduleProcedureRequest(
        $request, $theatre->getKey(),
        CarbonImmutable::parse(now()->addDay()->setTime(11, 0)), 60,
    );

    [$procedure, $items] = $service->startProcedure($request, $template->getKey(), $staff->getKey(), $staff->getKey());

    $service->addTeamMember($procedure, $staff->getKey(), 'surgeon', null, $staff->getKey());
    $service->startAnesthesia($procedure, $staff->getKey(), 'general', null, null, $staff->getKey());
    $service->recordSurgicalEvent($procedure, 'incision', null, $staff->getKey(), null, $staff->getKey());

    foreach ($items as $item) {
        $service->completeChecklistItem($item, $staff->getKey());
    }

    $service->closeProcedure($procedure, $staff->getKey());

    $recovery = $service->admitToRecovery($procedure, $staff->getKey(), ['hr' => 78], null, $staff->getKey());
    $recovery = $service->dischargeRecovery($recovery, $staff->getKey());

    expect($recovery->status)->toBe(RecoveryRecord::STATUS_DISCHARGED)
        ->and($recovery->discharged_by_staff_id)->toBe($staff->getKey())
        ->and(Procedure::query()->findOrFail($procedure->getKey())->status)->toBe(Procedure::STATUS_COMPLETED)
        ->and(RecoveryRecord::query()->where('procedure_id', $procedure->getKey())->count())->toBe(1);
});

// ─────────────────────────────────── ICU ───────────────────────────────────

it('admits a patient to the ICU with acuity-based bed assignment — a taken bed is refused', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();

    $bed = IcuBed::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'status' => IcuBed::STATUS_AVAILABLE,
    ]);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $patient2 = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/icu-admissions', [
            'patientId' => $patient->getKey(),
            'icuBedId' => $bed->getKey(),
            'source' => 'ipd',
            'acuity' => 'level_3',
            'observationIntervalMinutes' => 60,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'admitted')
        ->assertJsonPath('data.icuBedId', $bed->getKey());

    // The bed is now occupied — a second admission to the SAME bed → 409.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/icu-admissions', [
            'patientId' => $patient2->getKey(),
            'icuBedId' => $bed->getKey(),
            'source' => 'ipd',
            'acuity' => 'level_3',
            'observationIntervalMinutes' => 60,
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(IcuBed::query()->findOrFail($bed->getKey())->status)->toBe(IcuBed::STATUS_OCCUPIED);
});

it('enforces one open ICU admission per patient — the DB partial unique is the backstop', function () {
    ['org' => $org, 'facility' => $facility, 'staff' => $staff] = otAdmin();

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $bed1 = IcuBed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $bed2 = IcuBed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $service->admitToIcu($org->getKey(), $facility->getKey(), $patient->getKey(), $bed1->getKey(), 'ipd', 'level_3', 60, $staff->getKey());

    try {
        $service->admitToIcu($org->getKey(), $facility->getKey(), $patient->getKey(), $bed2->getKey(), 'ipd', 'level_3', 60, $staff->getKey());
        $this->fail('The second open ICU admission should have been refused.');
    } catch (QueryException $e) {
        expect(true)->toBeTrue(); // partial unique refused the second open admission
    }

    expect(IcuAdmission::query()->where('patient_id', $patient->getKey())->whereIn('status', ['admitted', 'transferred'])->count())->toBe(1);
});

it('computes warning scores and opens escalation alerts that must be acknowledged', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $bed = IcuBed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $admission = $service->admitToIcu($org->getKey(), $facility->getKey(), $patient->getKey(), $bed->getKey(), 'ipd', 'level_3', 60, $staff->getKey());

    // Healthy observation → low score, no alerts.
    [$set1, $score1] = $service->recordObservation(
        $admission,
        $staff->getKey(),
        ['respiratory_rate' => 16, 'spo2' => 98, 'heart_rate' => 72, 'sbp' => 118, 'temperature_c' => 36.8],
    );
    expect($score1->score_total)->toBe(0)
        ->and($score1->severity)->toBe(WarningScore::SEVERITY_LOW)
        ->and(WarningScore::query()->where('observation_set_id', $set1->getKey())->exists())->toBeTrue();

    // Deteriorating observation → score jumps (escalation) → alert opened.
    [$set2, $score2, $alerts] = $service->recordObservation(
        $admission->refresh(),
        $staff->getKey(),
        ['respiratory_rate' => 28, 'spo2' => 82, 'heart_rate' => 132, 'sbp' => 85, 'temperature_c' => 35.0],
    );
    expect($score2->score_total)->toBeGreaterThanOrEqual(10)
        ->and($score2->severity)->toBe(WarningScore::SEVERITY_EMERGENCY)
        ->and(collect($alerts)->contains(fn (IcuAlert $a): bool => $a->alert_type === IcuAlert::TYPE_SCORE_ESCALATION))->toBeTrue()
        ->and(collect($alerts)->contains(fn (IcuAlert $a): bool => $a->alert_type === IcuAlert::TYPE_THRESHOLD_BREACH))->toBeTrue();

    $alert = IcuAlert::query()->where('icu_admission_id', $admission->getKey())->where('alert_type', IcuAlert::TYPE_SCORE_ESCALATION)->firstOrFail();

    // The alert is audited on acknowledgment (WHO saw it, WHEN).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/icu-alerts/{$alert->getKey()}/acknowledge")
        ->assertOk()
        ->assertJsonPath('data.status', 'acknowledged')
        ->assertJsonPath('data.acknowledgedAt', fn ($v) => $v !== null);

    $audit = AuditEvent::query()->where('action', 'icu_alert.acknowledged')->latest('occurred_at')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->payload)->toMatchArray(['alertType' => 'score_escalation', 'icuAdmissionId' => $admission->getKey()]);

    // A double acknowledgment → 409 LOCK_CONFLICT.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/icu-alerts/{$alert->getKey()}/acknowledge")
        ->assertStatus(409);
});

it('escalates a MISSED observation — recording after the scheduled due time is an incident by design', function () {
    ['org' => $org, 'facility' => $facility, 'staff' => $staff] = otAdmin();

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $bed = IcuBed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $admission = $service->admitToIcu($org->getKey(), $facility->getKey(), $patient->getKey(), $bed->getKey(), 'ipd', 'level_3', 60, $staff->getKey());

    // Record an observation LATE: 90 minutes after admission (due was +60).
    [$set, $score, $alerts] = $service->recordObservation(
        $admission,
        $staff->getKey(),
        ['heart_rate' => 80],
        null,
        CarbonImmutable::parse(now()->addMinutes(90)),
    );

    $missed = collect($alerts)->first(fn (IcuAlert $a): bool => $a->alert_type === IcuAlert::TYPE_MISSED_OBSERVATION);
    expect($missed)->not->toBeNull()
        ->and($missed->status)->toBe(IcuAlert::STATUS_OPEN)
        ->and($missed->message)->toContain('after the scheduled due time');

    // The schedule advances from the ACTUAL observation time.
    expect($admission->refresh()->next_observation_due_at->greaterThan(CarbonImmutable::parse(now()->addMinutes(90))))->toBeTrue();
});

it('releases the ICU bed on step-down with handover documentation', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $bed = IcuBed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $admission = $service->admitToIcu($org->getKey(), $facility->getKey(), $patient->getKey(), $bed->getKey(), 'ipd', 'level_3', 60, $staff->getKey());

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/icu-admissions/{$admission->getKey()}/transfer", [
            'handoverNotes' => 'Stable, step-down to general ward.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'transferred');

    expect(IcuBed::query()->findOrFail($bed->getKey())->status)->toBe(IcuBed::STATUS_AVAILABLE);

    // Documentation (daily goal) is stored but never in audit payloads.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/icu-admissions/{$admission->getKey()}/notes", [
            'noteType' => 'daily_goal',
            'content' => 'Maintain SpO2 above 94%.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.noteType', 'daily_goal');

    $audit = AuditEvent::query()->where('action', 'icu_documentation.created')->latest('occurred_at')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->payload)->toMatchArray(['noteType' => 'daily_goal'])
        ->and($audit->payload)->not->toHaveKey('content');
});

// ─────────────────────────────── Blood Bank ───────────────────────────────

it('registers a donor with PHI-safe audit payloads', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin] = otAdmin();

    // Unauthenticated → 401.
    $this->postJson('/api/v1/donors', [])->assertUnauthorized();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/donors', [
            'donorNumber' => 'DN-1001',
            'fullName' => 'Ramesh Sharma',
            'dateOfBirth' => '1985-04-12',
            'sex' => 'male',
            'bloodGroup' => 'O',
            'rhFactor' => 'positive',
            'phone' => '+977-98XXXXXXXX',
        ])
        ->assertCreated()
        ->assertJsonPath('data.donorNumber', 'DN-1001');

    // The audit payload carries facts only — never the donor's name or DOB.
    $audit = AuditEvent::query()->where('action', 'donor.registered')->latest('occurred_at')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->payload)->toMatchArray(['donorNumber' => 'DN-1001', 'bloodGroup' => 'O'])
        ->and($audit->payload)->not->toHaveKey('fullName')
        ->and($audit->payload)->not->toHaveKey('dateOfBirth')
        ->and($audit->payload)->not->toHaveKey('phone');
});

it('processes a donation into componentized units — quarantined until tested, failed screening discards', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $donor = Donor::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'blood_group' => 'A',
        'rh_factor' => 'negative',
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/donors/{$donor->getKey()}/donations", [
            'phlebotomistStaffId' => $staff->getKey(),
            'components' => [
                ['componentType' => 'packed_cells', 'expiryDays' => 35],
                ['componentType' => 'plasma', 'expiryDays' => 365],
            ],
        ])
        ->assertCreated()
        ->assertJsonCount(2, 'data.units');

    $units = BloodUnit::query()->where('donation_id', $donor->donations()->latest()->value('id'))->get();
    expect($units)->toHaveCount(2)
        ->and($units->every(fn (BloodUnit $u): bool => $u->status === BloodUnit::STATUS_QUARANTINED))->toBeTrue()
        ->and($units->every(fn (BloodUnit $u): bool => $u->tested === false))->toBeTrue();

    // Passing screening → available.
    $passing = $units->firstWhere('component_type', 'packed_cells');
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$passing->getKey()}/test", [
            'suitable' => true,
            'testResults' => ['hiv' => 'negative', 'hbv' => 'negative'],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'available')
        ->assertJsonPath('data.tested', true);

    // Failing screening → discarded (never issuable).
    $failing = $units->firstWhere('component_type', 'plasma');
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$failing->getKey()}/test", [
            'suitable' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'discarded');

    // A double test → 409 LOCK_CONFLICT (zero rows affected).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$passing->getKey()}/test", ['suitable' => true])
        ->assertStatus(409);
});

it('never issues an expired or untested unit — issue requires a compatible crossmatch', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $otherPatient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    // Untested unit (quarantined) → issue refused.
    $donor = Donor::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'blood_group' => 'O', 'rh_factor' => 'positive']);
    [, $untestedUnits] = $service->recordDonation($donor, $staff->getKey(), [['component_type' => 'packed_cells', 'expiry_days' => 35]]);
    $untested = $untestedUnits[0];

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$untested->getKey()}/issue", ['patientId' => $patient->getKey()])
        ->assertStatus(409);

    // Tested unit, no crossmatch → issue refused.
    $tested = $service->testBloodUnit($untested, $staff->getKey(), [], true);
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$tested->getKey()}/issue", ['patientId' => $patient->getKey()])
        ->assertStatus(409);

    // Incompatible crossmatch (one per unit+patient pair) → issue refused.
    $crossmatch = $service->requestCrossmatch($tested, $patient->getKey(), $staff->getKey());
    $service->performCrossmatch($crossmatch, $staff->getKey(), 'B', 'positive', false, 'negative');
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$tested->getKey()}/issue", ['patientId' => $patient->getKey()])
        ->assertStatus(409);

    // Compatible crossmatch (a fresh unit+patient pair) → issue succeeds.
    $compatible = $service->requestCrossmatch($tested, $otherPatient->getKey(), $staff->getKey());
    $service->performCrossmatch($compatible, $staff->getKey(), 'O', 'positive', true, 'negative');
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$tested->getKey()}/issue", ['patientId' => $otherPatient->getKey()])
        ->assertOk()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.issuedToPatientId', $otherPatient->getKey());

    // EXPIRED unit → issue refused even with a compatible crossmatch.
    $expiredDonor = Donor::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'blood_group' => 'O', 'rh_factor' => 'positive']);
    [, $expiredUnits] = $service->recordDonation($expiredDonor, $staff->getKey(), [['component_type' => 'packed_cells', 'expiry_days' => 35]]);
    $expired = $service->testBloodUnit($expiredUnits[0], $staff->getKey(), [], true);
    // Expire the unit honestly: collected 40 days ago, expired 5 days ago
    // (expiry > collected, both past — the CHECK stays satisfied).
    BloodUnit::query()->where('id', $expired->getKey())->update([
        'collected_at' => now()->subDays(40),
        'expiry_at' => now()->subDays(5),
    ]);
    $expired->refresh();
    $cm = $service->requestCrossmatch($expired, $patient->getKey(), $staff->getKey());
    $service->performCrossmatch($cm, $staff->getKey(), 'O', 'positive', true, 'negative');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$expired->getKey()}/issue", ['patientId' => $patient->getKey()])
        ->assertStatus(409);
});

it('enforces DUAL verification — the verifier must differ from the starter and completion waits for verification', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();
    ['user' => $nurseUser, 'staff' => $nurseStaff] = otSecondClinician($org, $facility);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounter = Encounter::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'patient_id' => $patient->getKey()]);

    $ready = otReadyUnit(['org' => $org, 'facility' => $facility, 'staff' => $staff], $patient->getKey());

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);
    $issued = $service->issueBloodUnit($ready['unit'], $patient->getKey(), $staff->getKey());

    // Start the transfusion as the admin's staff.
    $started = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/transfusions', [
            'bloodUnitId' => $issued->getKey(),
            'patientId' => $patient->getKey(),
            'crossmatchId' => $ready['crossmatch']->getKey(),
            'encounterId' => $encounter->getKey(),
        ])
        ->assertCreated()
        ->json('data');

    $transfusionId = $started['id'];

    // The SAME staff cannot verify their own transfusion (dual verification).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/transfusions/{$transfusionId}/verify")
        ->assertStatus(409);

    // Completion is refused BEFORE verification.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/transfusions/{$transfusionId}/complete", ['volumeTransfusedMl' => 350])
        ->assertStatus(409);

    // A DIFFERENT staff member verifies (the nurse).
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson("/api/v1/transfusions/{$transfusionId}/verify")
        ->assertOk()
        ->assertJsonPath('data.status', 'started')
        ->assertJsonPath('data.verifiedAt', fn ($v) => $v !== null);

    // A double verification → 409 LOCK_CONFLICT.
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson("/api/v1/transfusions/{$transfusionId}/verify")
        ->assertStatus(409);

    // Now completion succeeds and the unit becomes transfused.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/transfusions/{$transfusionId}/complete", ['volumeTransfusedMl' => 350])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect(BloodUnit::query()->findOrFail($issued->getKey())->status)->toBe(BloodUnit::STATUS_TRANSFUSED);

    $audit = AuditEvent::query()->where('action', 'transfusion.completed')->latest('occurred_at')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->payload)->toMatchArray(['unitId' => $issued->getKey(), 'volumeTransfusedMl' => 350]);
});

it('refuses a wrong-patient transfusion and reports reactions with a stop', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $otherPatient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $ready = otReadyUnit(['org' => $org, 'facility' => $facility, 'staff' => $staff], $patient->getKey());
    $issued = $service->issueBloodUnit($ready['unit'], $patient->getKey(), $staff->getKey());

    // Attempt to transfuse the issued unit into a DIFFERENT patient → refused.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/transfusions', [
            'bloodUnitId' => $issued->getKey(),
            'patientId' => $otherPatient->getKey(),
            'crossmatchId' => $ready['crossmatch']->getKey(),
        ])
        ->assertStatus(409);

    // Correct patient → start, then stop on a reaction, report it.
    $transfusionId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/transfusions', [
            'bloodUnitId' => $issued->getKey(),
            'patientId' => $patient->getKey(),
            'crossmatchId' => $ready['crossmatch']->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/transfusions/{$transfusionId}/reaction", [
            'severity' => 'moderate',
            'symptoms' => ['fever', 'chills'],
            'actionTaken' => 'Stopped transfusion, notified clinician.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.severity', 'moderate');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/transfusions/{$transfusionId}/stop", [
            'volumeTransfusedMl' => 120,
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'stopped');

    expect(ReactionReport::query()->where('transfusion_id', $transfusionId)->exists())->toBeTrue()
        ->and(Transfusion::query()->findOrFail($transfusionId)->volume_transfused_ml)->toBe(120)
        // A stopped unit cannot be reused — it stays issued.
        ->and(BloodUnit::query()->findOrFail($issued->getKey())->status)->toBe(BloodUnit::STATUS_ISSUED);
});

it('discards a unit with reason — terminal, never reusable', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = otAdmin();

    /** @var OtIcuBloodBankService $service */
    $service = app(OtIcuBloodBankService::class);

    $donor = Donor::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    [, $units] = $service->recordDonation($donor, $staff->getKey(), [['component_type' => 'whole_blood', 'expiry_days' => 35]]);
    $unit = $service->testBloodUnit($units[0], $staff->getKey(), [], true);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$unit->getKey()}/discard", ['reason' => 'Expired in storage'])
        ->assertOk()
        ->assertJsonPath('data.status', 'discarded');

    // Discarding an already-discarded unit → 409.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$unit->getKey()}/discard", ['reason' => 'Again'])
        ->assertStatus(409);

    // The unit can never be issued again.
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/blood-units/{$unit->getKey()}/issue", ['patientId' => $patient->getKey()])
        ->assertStatus(409);
});

// ─────────────────────────────── Isolation ───────────────────────────────

it('isolates OT/ICU/blood data across tenants — reads and writes are refused', function () {
    ['org' => $orgA, 'facility' => $facilityA, 'admin' => $adminA, 'staff' => $staffA] = otAdmin();
    ['org' => $orgB, 'facility' => $facilityB] = otOtherTenant();
    $adminB = Identity::user();
    Identity::assign($adminB, 'hospital_admin', $orgB, $facilityB);

    // Tenant A's theatre, ICU bed, and blood unit.
    $theatreA = Theatre::factory()->create(['tenant_id' => $orgA->getKey(), 'facility_id' => $facilityA->getKey()]);
    $bedA = IcuBed::factory()->create(['tenant_id' => $orgA->getKey(), 'facility_id' => $facilityA->getKey()]);
    $donorA = Donor::factory()->create(['tenant_id' => $orgA->getKey(), 'facility_id' => $facilityA->getKey()]);

    // Tenant A's theatre list does not include anything of tenant B's.
    $this->withToken(Identity::tokenFor($adminB))
        ->getJson('/api/v1/theatres')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Cross-tenant WRITE via route-bound id → the model resolves in tenant A
    // but AccessCheck denies (403) — tenant B cannot act on tenant A rows.
    $patientB = Patient::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);

    $this->withToken(Identity::tokenFor($adminB))
        ->postJson("/api/v1/donors/{$donorA->getKey()}/donations", [
            'phlebotomistStaffId' => $staffA->getKey(),
            'components' => [['componentType' => 'whole_blood', 'expiryDays' => 35]],
        ])
        ->assertForbidden();

    // A body-supplied foreign id resolves INSIDE the actor's tenant scope —
    // tenant A's bed does not exist for tenant B → 404 (no existence leak).
    $this->withToken(Identity::tokenFor($adminB))
        ->postJson('/api/v1/icu-admissions', [
            'patientId' => $patientB->getKey(),
            'icuBedId' => $bedA->getKey(),
            'source' => 'ipd',
            'acuity' => 'level_3',
            'observationIntervalMinutes' => 60,
        ])
        ->assertNotFound();

    // Tenant B cannot schedule tenant A's procedure request.
    $requestA = ProcedureRequest::factory()->create(['tenant_id' => $orgA->getKey(), 'facility_id' => $facilityA->getKey()]);
    $this->withToken(Identity::tokenFor($adminB))
        ->postJson("/api/v1/procedure-requests/{$requestA->getKey()}/schedule", [
            'theatreId' => $theatreA->getKey(),
            'scheduledAt' => now()->addDay()->toIso8601String(),
            'durationMinutes' => 60,
        ])
        ->assertForbidden();
});
