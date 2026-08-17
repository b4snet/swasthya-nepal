<?php

use App\Models\AuditEvent;
use App\Models\CdssCheckResult;
use App\Models\CdssRule;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Identity;

/**
 * Phase 21 — CDSS (ROADMAP Phase 21, CLINICAL_SAFETY.md §6, §9,
 * AI_RULES.md §6–7).
 *
 * Proves: knowledge-base-driven allergy / drug-drug interaction / dose
 * checks (rules are versioned and pinned — a rule is never edited in
 * place; activation supersedes); raised alerts are persisted and remain
 * OPEN until a prescriber OVERRIDES with a mandatory, audited reason
 * (never a silent dismiss); the check mutates nothing clinical; fail-open
 * loud degradation (a check that cannot run is reported degraded, never a
 * silent pass and never a block); pathway evaluation is advisory only;
 * tenant/facility isolation (read 404 / write 403 convention); RLS; and
 * PHI-safe audit payloads (facts and ids only — never names or free-text
 * alert messages).
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * @return array{org: Organization, facility: Facility, admin: User, adminStaff: Staff, department: Department, doctor: User, doctorStaff: Staff, receptionist: User, receptionistStaff: Staff}
 */
function cdssCtx(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $adminStaff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $admin->getKey(),
    ]);

    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org, $facility);
    $doctorStaff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $doctor->getKey(),
    ]);

    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);
    $receptionistStaff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $receptionist->getKey(),
    ]);

    return [
        'org' => $org,
        'facility' => $facility,
        'admin' => $admin,
        'adminStaff' => $adminStaff,
        'department' => $department,
        'doctor' => $doctor,
        'doctorStaff' => $doctorStaff,
        'receptionist' => $receptionist,
        'receptionistStaff' => $receptionistStaff,
    ];
}

function cdssPatient(array $ctx): Patient
{
    return Patient::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
    ]);
}

function cdssMedication(array $ctx, string $generic = 'Amoxicillin'): Medication
{
    return Medication::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'generic_name' => $generic,
    ]);
}

function cdssRule(array $ctx, string $type, array $spec, string $code, string $severity = 'major', int $version = 1, string $status = CdssRule::STATUS_ACTIVE): CdssRule
{
    return CdssRule::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'rule_type' => $type,
        'code' => $code,
        'severity' => $type === CdssRule::TYPE_PATHWAY ? null : $severity,
        'spec' => $spec,
        'version' => $version,
        'status' => $status,
    ]);
}

function cdssActivePrescription(array $ctx, Patient $patient, Medication $medication, string $dose = '500 mg'): Prescription
{
    $prescription = Prescription::query()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'patient_id' => $patient->getKey(),
        'encounter_id' => null,
        'prescriber_staff_id' => $ctx['doctorStaff']->getKey(),
        'status' => Prescription::STATUS_ACTIVE,
        'lock_version' => 0,
        'created_by' => $ctx['doctorStaff']->getKey(),
    ]);

    PrescriptionLine::query()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'prescription_id' => $prescription->getKey(),
        'medication_id' => $medication->getKey(),
        'dose' => $dose,
        'route' => 'oral',
        'frequency' => 'once daily',
        'status' => PrescriptionLine::STATUS_ORDERED,
        'line_no' => 1,
        'created_by' => $ctx['doctorStaff']->getKey(),
    ]);

    return $prescription;
}

describe('CDSS authentication and authorization', function (): void {
    it('denies unauthenticated access to the check and rules surfaces', function (): void {
        $this->postJson('/api/v1/cdss/checks/prescription', [])->assertStatus(401);
        $this->getJson('/api/v1/cdss/rules')->assertStatus(401);
        $this->postJson('/api/v1/cdss/rules', [])->assertStatus(401);
        $this->postJson('/api/v1/ai/features', [])->assertStatus(401);
    });

    it('denies a role without cdss:view (receptionist) on the check surface', function (): void {
        $ctx = cdssCtx();
        $patient = cdssPatient($ctx);

        $this->withToken(Identity::tokenFor($ctx['receptionist']))
            ->postJson('/api/v1/cdss/checks/prescription', [
                'patientId' => $patient->getKey(),
                'lines' => [['medicationId' => cdssMedication($ctx)->getKey(), 'dose' => '500 mg', 'route' => 'oral', 'frequency' => 'once daily']],
            ])
            ->assertStatus(403);
    });

    it('denies a role without cdss:manage (doctor) on the rules surface', function (): void {
        $ctx = cdssCtx();

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/cdss/rules', [
                'ruleType' => CdssRule::TYPE_DOSE,
                'code' => 'DOSE-1',
                'name' => 'Dose check',
                'severity' => 'major',
                'spec' => ['medication_id' => null, 'max_daily_mg' => 4000],
            ])
            ->assertStatus(403);
    });
});

describe('CDSS knowledge base versioning', function (): void {
    it('stores a draft v1 rule and activates it (superseding on new version)', function (): void {
        $ctx = cdssCtx();

        $stored = $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/cdss/rules', [
                'ruleType' => CdssRule::TYPE_INTERACTION,
                'code' => 'INT-WAR-MET',
                'name' => 'Warfarin + Metronidazole',
                'severity' => 'major',
                'spec' => ['medication_a_id' => null, 'medication_b_id' => null, 'action' => 'Monitor INR'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->json('data');

        $rule = CdssRule::query()->findOrFail($stored['id']);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/cdss/rules/{$rule->getKey()}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        // A change is a NEW version — never an in-place edit.
        $stored2 = $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/cdss/rules', [
                'ruleType' => CdssRule::TYPE_INTERACTION,
                'code' => 'INT-WAR-MET',
                'name' => 'Warfarin + Metronidazole (v2)',
                'severity' => 'contraindicated',
                'spec' => ['medication_a_id' => null, 'medication_b_id' => null, 'action' => 'Avoid combination'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.version', 2)
            ->json('data');

        $rule2 = CdssRule::query()->findOrFail($stored2['id']);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/cdss/rules/{$rule2->getKey()}/activate")
            ->assertStatus(200);

        // Exactly one ACTIVE version per code — the DB backstop.
        expect(CdssRule::query()->where('code', 'INT-WAR-MET')->where('status', 'active')->count())->toBe(1);
        expect($rule->refresh()->status)->toBe('superseded');
        expect($rule2->refresh()->status)->toBe('active');
    });

    it('rejects a duplicate registry function and an invalid rule type', function (): void {
        $ctx = cdssCtx();
        $med = cdssMedication($ctx);

        cdssRule($ctx, CdssRule::TYPE_ALLERGEN, ['medication_id' => $med->getKey(), 'allergen_class' => 'penicillin'], 'ALL-PEN');

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/cdss/rules', [
                'ruleType' => 'not-a-type',
                'code' => 'X',
                'name' => 'X',
                'spec' => [],
            ])
            ->assertStatus(422);
    });
});

describe('CDSS knowledge checks', function (): void {
    it('flags an allergy: patient allergic to penicillin + allergen rule on the medication', function (): void {
        $ctx = cdssCtx();
        $patient = cdssPatient($ctx);
        $med = cdssMedication($ctx, 'Amoxicillin');

        PatientAllergy::factory()->create([
            'tenant_id' => $ctx['org']->getKey(),
            'facility_id' => $ctx['facility']->getKey(),
            'patient_id' => $patient->getKey(),
            'allergen' => 'Penicillin',
            'allergen_class' => 'penicillin',
            'status' => PatientAllergy::STATUS_ACTIVE,
        ]);

        cdssRule($ctx, CdssRule::TYPE_ALLERGEN, ['medication_id' => $med->getKey(), 'allergen_class' => 'penicillin', 'action' => 'Do not prescribe.'], 'ALL-PEN');

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/cdss/checks/prescription', [
                'patientId' => $patient->getKey(),
                'lines' => [['medicationId' => $med->getKey(), 'dose' => '500 mg', 'route' => 'oral', 'frequency' => 'once daily']],
            ])
            ->assertStatus(200)
            ->assertJsonPath('meta.degraded', false)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.alertType', 'allergy')
            ->assertJsonPath('data.0.severity', 'major')
            ->assertJsonPath('data.0.code', 'ALL-PEN');

        $this->assertDatabaseHas('cdss_check_results', [
            'tenant_id' => $ctx['org']->getKey(),
            'patient_id' => $patient->getKey(),
            'alert_type' => 'allergy',
            'rule_code' => 'ALL-PEN',
            'status' => CdssCheckResult::STATUS_OPEN,
        ]);
    });

    it('flags a drug-drug interaction against an active prescription and within the batch', function (): void {
        $ctx = cdssCtx();
        $patient = cdssPatient($ctx);
        $medA = cdssMedication($ctx, 'Warfarin');
        $medB = cdssMedication($ctx, 'Metronidazole');

        // The patient already takes B on an ACTIVE prescription.
        cdssActivePrescription($ctx, $patient, $medB, '400 mg');
        cdssRule($ctx, CdssRule::TYPE_INTERACTION, ['medication_a_id' => $medA->getKey(), 'medication_b_id' => $medB->getKey(), 'action' => 'Monitor INR'], 'INT-WAR-MET');

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/cdss/checks/prescription', [
                'patientId' => $patient->getKey(),
                'lines' => [['medicationId' => $medA->getKey(), 'dose' => '5 mg', 'route' => 'oral', 'frequency' => 'once daily']],
            ])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.alertType', 'interaction');
    });

    it('flags a dose above the knowledge-base maximum', function (): void {
        $ctx = cdssCtx();
        $patient = cdssPatient($ctx);
        $med = cdssMedication($ctx, 'Paracetamol');

        cdssRule($ctx, CdssRule::TYPE_DOSE, ['medication_id' => $med->getKey(), 'max_daily_mg' => 4000, 'min_daily_mg' => 0], 'DOSE-PCM');

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/cdss/checks/prescription', [
                'patientId' => $patient->getKey(),
                'lines' => [['medicationId' => $med->getKey(), 'dose' => '5000 mg', 'route' => 'oral', 'frequency' => 'once daily']],
            ])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.alertType', 'dose')
            ->assertJsonPath('data.0.code', 'DOSE-PCM');

        // A compliant dose raises nothing.
        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/cdss/checks/prescription', [
                'patientId' => $patient->getKey(),
                'lines' => [['medicationId' => $med->getKey(), 'dose' => '500 mg', 'route' => 'oral', 'frequency' => 'once daily']],
            ])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('raises nothing when no rule matches, and the check mutates nothing clinical', function (): void {
        $ctx = cdssCtx();
        $patient = cdssPatient($ctx);
        $med = cdssMedication($ctx, 'Metformin');

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/cdss/checks/prescription', [
                'patientId' => $patient->getKey(),
                'lines' => [['medicationId' => $med->getKey(), 'dose' => '500 mg', 'route' => 'oral', 'frequency' => 'once daily']],
            ])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.degraded', false);

        expect(Prescription::query()->count())->toBe(0);
        expect(PrescriptionLine::query()->count())->toBe(0);
    });

    it('fails open loudly when the knowledge base cannot be evaluated', function (): void {
        $ctx = cdssCtx();
        $patient = cdssPatient($ctx);
        $med = cdssMedication($ctx);

        // Force the KB unavailable by dropping the rules table for this
        // test (RefreshDatabase recreates it for every test).
        Schema::drop('cdss_rules');

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/cdss/checks/prescription', [
                'patientId' => $patient->getKey(),
                'lines' => [['medicationId' => $med->getKey(), 'dose' => '500 mg', 'route' => 'oral', 'frequency' => 'once daily']],
            ])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.degraded', true);

        // Care is never blocked: no prescription rows were created either.
        expect(Prescription::query()->count())->toBe(0);
    });

    it('records an audited override with a mandatory reason and blocks a second override (CAS)', function (): void {
        $ctx = cdssCtx();
        $patient = cdssPatient($ctx);
        $med = cdssMedication($ctx, 'Amoxicillin');

        PatientAllergy::factory()->create([
            'tenant_id' => $ctx['org']->getKey(),
            'facility_id' => $ctx['facility']->getKey(),
            'patient_id' => $patient->getKey(),
            'allergen' => 'Penicillin',
            'allergen_class' => 'penicillin',
            'status' => PatientAllergy::STATUS_ACTIVE,
        ]);

        cdssRule($ctx, CdssRule::TYPE_ALLERGEN, ['medication_id' => $med->getKey(), 'allergen_class' => 'penicillin'], 'ALL-PEN');

        $check = $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/cdss/checks/prescription', [
                'patientId' => $patient->getKey(),
                'lines' => [['medicationId' => $med->getKey(), 'dose' => '500 mg', 'route' => 'oral', 'frequency' => 'once daily']],
            ])
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $result = CdssCheckResult::query()->findOrFail($check['id']);

        // A reason is MANDATORY.
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/cdss/checks/{$result->getKey()}/override", ['reason' => ''])
            ->assertStatus(422);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/cdss/checks/{$result->getKey()}/override", ['reason' => 'Treated with allergy coverage under ID physician supervision.'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'overridden');

        $this->assertDatabaseHas('cdss_check_results', [
            'id' => $result->getKey(),
            'status' => 'overridden',
            'overridden_by' => $ctx['adminStaff']->getKey(),
        ]);

        // A second override attempt on the same result is a CAS conflict.
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/cdss/checks/{$result->getKey()}/override", ['reason' => 'Trying again.'])
            ->assertStatus(409);
    });

    it('evaluates a pathway rule only when its condition matches (advisory, nothing applied)', function (): void {
        $ctx = cdssCtx();
        $patient = cdssPatient($ctx);
        $pathway = cdssRule($ctx, CdssRule::TYPE_PATHWAY, [
            'condition' => ['diagnosis_code' => 'I10'],
            'suggestion' => 'Consider the hypertension pathway: baseline labs and BP log.',
        ], 'PW-HT');

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/cdss/pathways/{$pathway->getKey()}/evaluate", [
                'patientId' => $patient->getKey(),
                'context' => ['diagnosis_code' => 'I10'],
            ])
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.alertType', 'pathway');

        // Non-matching context → no suggestion.
        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/cdss/pathways/{$pathway->getKey()}/evaluate", [
                'patientId' => $patient->getKey(),
                'context' => ['diagnosis_code' => 'E11'],
            ])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });
});

describe('CDSS isolation and audit', function (): void {
    it('isolates checks across tenants: another tenant\'s patient is 404', function (): void {
        $ctx = cdssCtx();
        $otherOrg = Identity::organization();
        $otherFacility = Identity::facility($otherOrg);
        $otherPatient = Patient::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
        ]);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/cdss/checks/prescription', [
                'patientId' => $otherPatient->getKey(),
                'lines' => [['medicationId' => cdssMedication($ctx)->getKey(), 'dose' => '500 mg', 'route' => 'oral', 'frequency' => 'once daily']],
            ])
            ->assertStatus(404);
    });

    it('denies overriding another tenant\'s check result (write → 403)', function (): void {
        $ctx = cdssCtx();
        $otherOrg = Identity::organization();
        $otherFacility = Identity::facility($otherOrg);
        $otherPatient = Patient::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
        ]);

        $foreign = CdssCheckResult::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
            'patient_id' => $otherPatient->getKey(),
        ]);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/cdss/checks/{$foreign->getKey()}/override", ['reason' => 'Any reason here.'])
            ->assertStatus(403);
    });

    it('keeps CDSS audit payloads PHI-safe (facts and ids only)', function (): void {
        $ctx = cdssCtx();
        $patient = cdssPatient($ctx);
        $med = cdssMedication($ctx, 'Amoxicillin');

        PatientAllergy::factory()->create([
            'tenant_id' => $ctx['org']->getKey(),
            'facility_id' => $ctx['facility']->getKey(),
            'patient_id' => $patient->getKey(),
            'allergen' => 'Penicillin',
            'allergen_class' => 'penicillin',
            'status' => PatientAllergy::STATUS_ACTIVE,
        ]);

        cdssRule($ctx, CdssRule::TYPE_ALLERGEN, ['medication_id' => $med->getKey(), 'allergen_class' => 'penicillin'], 'ALL-PEN');

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/cdss/checks/prescription', [
                'patientId' => $patient->getKey(),
                'lines' => [['medicationId' => $med->getKey(), 'dose' => '500 mg', 'route' => 'oral', 'frequency' => 'once daily']],
            ])
            ->assertStatus(200);

        foreach (AuditEvent::query()->where('action', 'like', 'cdss.%')->get() as $event) {
            expect(collect($event->payload)->keys()->contains(
                fn (string $k): bool => in_array($k, ['patientName', 'allergen', 'message', 'clinicalNotes', 'diagnosis'], true)
            ))->toBeFalse("audit payload leaked a PHI key in {$event->action}");
        }
    });
});
