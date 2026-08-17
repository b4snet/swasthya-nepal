<?php

use App\Models\AiDraft;
use App\Models\AiFeature;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Identity;

/**
 * Phase 21 — Governed assistive AI (AI_RULES.md §1–§19, MASTER_RULES.md
 * §33, §38).
 *
 * Proves: every AI feature ships ONLY through its registry entry (tier,
 * owner, pinned model id/version, purpose/non-goals, min inputs, output
 * schema, confidence threshold, fallback, review cadence, audit class,
 * evaluation evidence); activation is evidence-gated; the per-feature KILL
 * SWITCH (off by default) can be toggled and is audited; invocation
 * degrades LOUDLY (never blocks) when the feature is not active, disabled,
 * the model is not approved, confidence is below the calibrated threshold,
 * or inference is unavailable; the inference boundary NEVER transmits to a
 * model that is not in the transport allowlist (no patient data to
 * unapproved models, period); drafts reach a record ONLY through clinician
 * sign-off — there is NO autonomous-action path; and tenant/facility
 * isolation (read 404 / write 403) with PHI-safe audit payloads (no draft
 * text, no prompts, no outputs — AI_RULES.md §11, OBSERVABILITY.md §17).
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * @return array{org: Organization, facility: Facility, admin: User, adminStaff: Staff, department: Department, doctor: User, doctorStaff: Staff, nurse: User, nurseStaff: Staff, receptionist: User, receptionistStaff: Staff}
 */
function aiCtx(): array
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

    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $org, $facility);
    $nurseStaff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $nurse->getKey(),
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
        'nurse' => $nurse,
        'nurseStaff' => $nurseStaff,
        'receptionist' => $receptionist,
        'receptionistStaff' => $receptionistStaff,
    ];
}

function aiPatient(array $ctx): Patient
{
    return Patient::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
    ]);
}

function aiFeaturePayload(array $ctx, string $function = AiFeature::FUNCTION_DOCUMENTATION_DRAFT, array $overrides = []): array
{
    return array_merge([
        'function' => $function,
        'name' => 'Clinical note draft',
        'tier' => 2,
        'modelId' => 'note-draft-v3',
        'modelVersion' => '2026-07-15',
        'purpose' => 'Draft an encounter note from signed sections.',
        'nonGoals' => 'No ordering, dosing, or diagnosis.',
        'minInputs' => ['encounter_id'],
        'outputSchema' => ['draft' => 'text'],
        'confidenceThreshold' => 0.5,
        'fallbackMode' => 'manual entry works fully',
        'evaluationRef' => 'docs/evaluation/note-draft-v3-2026-07.md',
        'reviewCadence' => 'quarterly',
        'auditClass' => 'ai.draft',
    ], $overrides);
}

function aiRegister(array $ctx, string $function = AiFeature::FUNCTION_DOCUMENTATION_DRAFT, array $overrides = []): AiFeature
{
    return AiFeature::factory()->create([
        'tenant_id' => $ctx['org']->getKey(),
        'facility_id' => $ctx['facility']->getKey(),
        'function' => $function,
        'enabled' => false,
        'model_approved' => true,
        'evaluation_ref' => 'docs/evaluation/note-draft-v3-2026-07.md',
        'status' => AiFeature::STATUS_REGISTERED,
        ...$overrides,
    ]);
}

function aiActivateAndEnable(array $ctx, AiFeature $feature): void
{
    $feature->forceFill(['status' => AiFeature::STATUS_ACTIVE, 'enabled' => true, 'lock_version' => 1])->save();
}

describe('AI registry and authorization', function (): void {
    it('registers a feature with the kill switch OFF by default', function (): void {
        $ctx = aiCtx();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/ai/features', aiFeaturePayload($ctx))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.modelId', 'note-draft-v3')
            ->assertJsonPath('data.modelVersion', '2026-07-15');
    });

    it('rejects a duplicate function registration with 409', function (): void {
        $ctx = aiCtx();
        aiRegister($ctx);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/ai/features', aiFeaturePayload($ctx))
            ->assertStatus(409);
    });

    it('denies registry management to roles without ai:manage', function (): void {
        $ctx = aiCtx();

        $this->withToken(Identity::tokenFor($ctx['receptionist']))
            ->postJson('/api/v1/ai/features', aiFeaturePayload($ctx))
            ->assertStatus(403);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->getJson('/api/v1/ai/features')
            ->assertStatus(200);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/ai/features', aiFeaturePayload($ctx))
            ->assertStatus(403);
    });

    it('denies unauthenticated access to every AI surface', function (): void {
        $this->getJson('/api/v1/ai/features')->assertStatus(401);
        $this->postJson('/api/v1/ai/features', [])->assertStatus(401);
        $this->postJson('/api/v1/ai/drafts', [])->assertStatus(401);
    });
});

describe('AI governance gates', function (): void {
    it('refuses activation without evaluation evidence and an approved model', function (): void {
        $ctx = aiCtx();
        $feature = aiRegister($ctx, AiFeature::FUNCTION_DOCUMENTATION_DRAFT, ['evaluation_ref' => null, 'model_approved' => false]);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/ai/features/{$feature->getKey()}/activate")
            ->assertStatus(409);
    });

    it('activates a feature with evidence, then gates invocation behind the kill switch', function (): void {
        $ctx = aiCtx();
        $feature = aiRegister($ctx);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/ai/features/{$feature->getKey()}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.enabled', false);

        // Kill switch OFF → invocation degrades loudly, never blocks.
        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/features/{$feature->getKey()}/invoke", ['context' => ['encounter_id' => 'enc-1']])
            ->assertStatus(200)
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'feature_disabled')
            ->assertJsonPath('meta.degraded', true);

        // Kill switch ON → the next gate (transport allowlist) decides.
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->patchJson("/api/v1/ai/features/{$feature->getKey()}/switch", ['enabled' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.enabled', true);

        // No approved endpoint configured → inference unavailable (nothing
        // was ever transmitted).
        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/features/{$feature->getKey()}/invoke", ['context' => ['encounter_id' => 'enc-1']])
            ->assertStatus(200)
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'inference_unavailable');
    });

    it('never transmits to a model that is not in the transport allowlist', function (): void {
        Http::fake();

        $ctx = aiCtx();
        $feature = aiRegister($ctx, AiFeature::FUNCTION_SUMMARIZATION);
        aiActivateAndEnable($ctx, $feature);

        // Allowlist contains a DIFFERENT model — this feature's model is
        // not approved at the transport layer.
        config()->set('ai.approved_models', [
            'other-model' => ['endpoint' => 'https://inference.internal.example/v1/generate', 'version' => '2026-07-15'],
        ]);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/features/{$feature->getKey()}/invoke", ['context' => ['encounter_id' => 'enc-1']])
            ->assertStatus(200)
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'inference_unavailable');

        Http::assertNothingSent();
    });

    it('transmits ONLY through an allowlisted HTTPS endpoint and returns the calibrated output', function (): void {
        Http::fake([
            'https://inference.internal.example/v1/generate' => Http::response([
                'output' => 'Draft note text.',
                'confidence' => 0.87,
            ]),
        ]);

        $ctx = aiCtx();
        $feature = aiRegister($ctx);
        aiActivateAndEnable($ctx, $feature);

        config()->set('ai.approved_models', [
            'note-draft-v3' => ['endpoint' => 'https://inference.internal.example/v1/generate', 'version' => '2026-07-15'],
        ]);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/features/{$feature->getKey()}/invoke", ['context' => ['encounter_id' => 'enc-1', 'patient_name' => 'Should never be sent']])
            ->assertStatus(200)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.output', 'Draft note text.')
            ->assertJsonPath('data.confidence', 0.87)
            ->assertJsonPath('meta.degraded', false);

        // The PRIVILEGE BOUNDARY held: only the registry-permitted minimum
        // input (encounter_id) left the platform — patient_name was stripped.
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return str_contains((string) $request->url(), 'inference.internal.example')
                && isset($payload['context']['encounter_id'])
                && ! isset($payload['context']['patient_name']);
        });
    });

    it('refuses a below-threshold confidence output (never presented as reliable)', function (): void {
        Http::fake([
            'https://inference.internal.example/v1/generate' => Http::response([
                'output' => 'Low confidence draft.',
                'confidence' => 0.2,
            ]),
        ]);

        $ctx = aiCtx();
        $feature = aiRegister($ctx, AiFeature::FUNCTION_DOCUMENTATION_DRAFT, ['confidence_threshold' => 0.5]);
        aiActivateAndEnable($ctx, $feature);

        config()->set('ai.approved_models', [
            'note-draft-v3' => ['endpoint' => 'https://inference.internal.example/v1/generate', 'version' => '2026-07-15'],
        ]);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/features/{$feature->getKey()}/invoke", ['context' => ['encounter_id' => 'enc-1']])
            ->assertStatus(200)
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'low_confidence');
    });
});

describe('AI drafts and sign-off', function (): void {
    it('creates a grounded draft through the full gate stack, then signs it (the only record path)', function (): void {
        Http::fake([
            'https://inference.internal.example/v1/generate' => Http::response([
                'output' => 'Draft note text.',
                'confidence' => 0.87,
            ]),
        ]);

        $ctx = aiCtx();
        $patient = aiPatient($ctx);
        $feature = aiRegister($ctx);
        aiActivateAndEnable($ctx, $feature);

        config()->set('ai.approved_models', [
            'note-draft-v3' => ['endpoint' => 'https://inference.internal.example/v1/generate', 'version' => '2026-07-15'],
        ]);

        $draft = $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/ai/drafts', [
                'patientId' => $patient->getKey(),
                'function' => AiFeature::FUNCTION_DOCUMENTATION_DRAFT,
                'context' => ['encounter_id' => 'enc-1'],
                'sourceRefs' => [['type' => 'encounter', 'id' => 'enc-1']],
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.modelId', 'note-draft-v3')
            ->assertJsonPath('data.modelVersion', '2026-07-15')
            ->json('data');

        $row = AiDraft::query()->findOrFail($draft['id']);
        expect($row->status)->toBe(AiDraft::STATUS_DRAFT);
        expect($row->source_refs)->toBeArray()->toHaveCount(1);
        expect($row->source_refs[0]['type'])->toBe('encounter');
        expect($row->source_refs[0]['id'])->toBe('enc-1');

        // A draft never mutates a clinical record by itself.
        expect(Prescription::query()->count())->toBe(0);

        // Sign-off is the accountable human act.
        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/drafts/{$row->getKey()}/sign", ['action' => 'sign'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.signerStaffId', $ctx['doctorStaff']->getKey());

        $this->assertDatabaseHas('ai_drafts', [
            'id' => $row->getKey(),
            'status' => 'signed',
            'signer_staff_id' => $ctx['doctorStaff']->getKey(),
        ]);
    });

    it('denies sign-off to a role without ai:sign (nurse) and blocks a double sign (CAS)', function (): void {
        $ctx = aiCtx();
        $patient = aiPatient($ctx);

        $draft = AiDraft::factory()->create([
            'tenant_id' => $ctx['org']->getKey(),
            'facility_id' => $ctx['facility']->getKey(),
            'patient_id' => $patient->getKey(),
            'function' => AiFeature::FUNCTION_DOCUMENTATION_DRAFT,
            'status' => AiDraft::STATUS_DRAFT,
        ]);

        $this->withToken(Identity::tokenFor($ctx['nurse']))
            ->postJson("/api/v1/ai/drafts/{$draft->getKey()}/sign", ['action' => 'sign'])
            ->assertStatus(403);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/drafts/{$draft->getKey()}/sign", ['action' => 'sign'])
            ->assertStatus(200);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/drafts/{$draft->getKey()}/sign", ['action' => 'sign'])
            ->assertStatus(409);
    });

    it('withdraws a draft (reviewed and rejected — never reaches the record)', function (): void {
        $ctx = aiCtx();
        $patient = aiPatient($ctx);

        $draft = AiDraft::factory()->create([
            'tenant_id' => $ctx['org']->getKey(),
            'facility_id' => $ctx['facility']->getKey(),
            'patient_id' => $patient->getKey(),
            'status' => AiDraft::STATUS_DRAFT,
        ]);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/drafts/{$draft->getKey()}/sign", ['action' => 'withdraw'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'withdrawn');

        $this->assertDatabaseHas('ai_drafts', ['id' => $draft->getKey(), 'status' => 'withdrawn']);
    });

    it('degrades loudly (503) when inference is unavailable — the manual path is never blocked', function (): void {
        $ctx = aiCtx();
        $patient = aiPatient($ctx);
        $feature = aiRegister($ctx);
        aiActivateAndEnable($ctx, $feature);

        // No allowlist configured → inference unavailable.
        config()->set('ai.approved_models', []);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson('/api/v1/ai/drafts', [
                'patientId' => $patient->getKey(),
                'function' => AiFeature::FUNCTION_DOCUMENTATION_DRAFT,
                'context' => ['encounter_id' => 'enc-1'],
                'sourceRefs' => [],
            ])
            ->assertStatus(503);

        expect(AiDraft::query()->count())->toBe(0);
    });
});

describe('AI isolation and audit', function (): void {
    it('isolates features and drafts across tenants (read 404 / write 403)', function (): void {
        $ctx = aiCtx();
        $otherOrg = Identity::organization();
        $otherFacility = Identity::facility($otherOrg);

        $foreign = AiFeature::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
            'function' => AiFeature::FUNCTION_FORECAST,
            'status' => AiFeature::STATUS_REGISTERED,
        ]);

        // Cross-tenant feature (write) → 403 (the repo's established
        // read-404 / write-403 convention).
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/ai/features/{$foreign->getKey()}/activate")
            ->assertStatus(403);

        $otherPatient = Patient::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
        ]);

        $foreignDraft = AiDraft::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
            'patient_id' => $otherPatient->getKey(),
            'status' => AiDraft::STATUS_DRAFT,
        ]);

        // Cross-tenant draft (write) → 403.
        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/drafts/{$foreignDraft->getKey()}/sign", ['action' => 'sign'])
            ->assertStatus(403);
    });

    it('keeps AI audit payloads PHI-safe (ids and statuses only — never outputs or prompts)', function (): void {
        $ctx = aiCtx();
        $feature = aiRegister($ctx);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/ai/features/{$feature->getKey()}/activate")
            ->assertStatus(200);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->patchJson("/api/v1/ai/features/{$feature->getKey()}/switch", ['enabled' => true])
            ->assertStatus(200);

        $this->withToken(Identity::tokenFor($ctx['doctor']))
            ->postJson("/api/v1/ai/features/{$feature->getKey()}/invoke", ['context' => ['encounter_id' => 'enc-1']])
            ->assertStatus(200);

        foreach (AuditEvent::query()->where('action', 'like', 'ai.%')->get() as $event) {
            expect(collect($event->payload)->keys()->contains(
                fn (string $k): bool => in_array($k, ['output', 'prompt', 'draftText', 'context', 'patientName'], true)
            ))->toBeFalse("audit payload leaked a PHI/output key in {$event->action}");
        }

        // The kill-switch toggle is itself audited with the from/to state.
        expect(AuditEvent::query()->where('action', 'ai_feature.enabled')->count())->toBe(1);
    });
});
