<?php

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\FollowUp;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 slice 10 — follow-up reminders (PRODUCT_REQUIREMENTS §5.4,
 * DATABASE.md §3.37/§3.17a): a planned follow-up carries ONE in-app
 * appointment_reminder notification for its patient, created atomically with
 * the plan (no silent automation — the reminder surfaces via GET). The
 * partial unique (tenant_id, follow_up_id) makes re-triggers and concurrent
 * triggers database-level no-ops: idempotent replay returns the existing
 * reminder without duplicating it or re-auditing.
 */
beforeEach(function (): void {
    seedIdentity();
});

function reminderDoctor(Organization $org, Facility $facility, User $user): Staff
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

function reminderEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
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

function reminderPlan(Organization $org, Facility $facility, Encounter $encounter, array $overrides = []): FollowUp
{
    return FollowUp::factory()->create(array_merge([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->getKey(),
        'provider_staff_id' => $encounter->provider_staff_id,
    ], $overrides));
}

it('creates the in-app reminder atomically with the follow-up plan', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = reminderDoctor($org, $facility, $doctorUser);
    $encounter = reminderEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/follow-ups', [
            'followUpType' => 'return_visit',
            'plannedAt' => now()->addDays(5)->setTime(10, 0)->toISOString(),
        ])
        ->assertStatus(201);

    $followUp = FollowUp::query()->firstOrFail();
    $reminder = Notification::query()->where('follow_up_id', $followUp->getKey())->firstOrFail();

    expect(Notification::query()->count())->toBe(1)
        ->and($reminder->type)->toBe(Notification::TYPE_APPOINTMENT_REMINDER)
        ->and($reminder->channel)->toBe(Notification::CHANNEL_IN_APP)
        ->and($reminder->patient_id)->toBe($followUp->patient_id)
        ->and($reminder->status)->toBe(Notification::STATUS_SENT)
        ->and($reminder->sensitive)->toBeTrue()
        ->and($reminder->payload['followUpId'])->toBe($followUp->getKey());

    // Facts-only audit for the reminder creation.
    $event = AuditEvent::query()->where('action', 'follow_up.reminder_created')->firstOrFail();
    expect($event->payload)
        ->toHaveKey('followUpId', $followUp->getKey())
        ->toHaveKey('patientId', $followUp->patient_id)
        ->toHaveKey('channel', Notification::CHANNEL_IN_APP)
        ->toHaveKey('plannedAt');
});

it('reads the reminder for the care team (followup:view)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = reminderDoctor($org, $facility, $doctorUser);
    $encounter = reminderEncounter($org, $facility, $doctor);
    $followUp = reminderPlan($org, $facility, $encounter);
    $reminder = Notification::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $encounter->patient_id,
        'follow_up_id' => $followUp->getKey(),
    ]);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->getJson('/api/v1/follow-ups/'.$followUp->getKey().'/reminder')
        ->assertOk()
        ->assertJsonPath('data.id', $reminder->getKey())
        ->assertJsonPath('data.followUpId', $followUp->getKey())
        ->assertJsonPath('data.patientId', $encounter->patient_id)
        ->assertJsonPath('data.type', Notification::TYPE_APPOINTMENT_REMINDER)
        ->assertJsonPath('data.channel', Notification::CHANNEL_IN_APP)
        ->assertJsonPath('data.status', Notification::STATUS_SENT)
        ->assertJsonPath('data.sensitive', true);
});

it('triggers a reminder on demand and replays idempotently (one row, one audit)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = reminderDoctor($org, $facility, $doctorUser);
    $encounter = reminderEncounter($org, $facility, $doctor);
    $followUp = reminderPlan($org, $facility, $encounter);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/remind')
        ->assertOk()
        ->assertJsonPath('data.followUpId', $followUp->getKey());

    expect(Notification::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'follow_up.reminder_created')->count())->toBe(1);

    // Idempotent replay: same reminder returned, no duplicate, no re-audit.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/remind')
        ->assertOk();

    expect(Notification::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'follow_up.reminder_created')->count())->toBe(1);
});

it('refuses a second reminder at the database level (partial unique)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = reminderDoctor($org, $facility, $doctorUser);
    $encounter = reminderEncounter($org, $facility, $doctor);
    $followUp = reminderPlan($org, $facility, $encounter);
    Notification::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $encounter->patient_id,
        'follow_up_id' => $followUp->getKey(),
    ]);

    // The unique index exists and a second insert for the same plan fails —
    // the race backstop behind the idempotent service path.
    $index = DB::connection('pgsql')->selectOne(
        "select indexname from pg_indexes where schemaname = 'public' and tablename = 'notifications' and indexname = 'uq_notifications_tenant_follow_up'"
    );
    expect($index)->not->toBeNull();

    // Established savepoint pattern (AuthSubjectBindingTest): the transaction
    // wraps INSIDE the expect closure so the violation propagates and rolls
    // back cleanly.
    expect(function () use ($org, $followUp): void {
        DB::transaction(function () use ($org, $followUp): void {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $org->getKey(),
                'patient_id' => $followUp->patient_id,
                'follow_up_id' => $followUp->getKey(),
                'type' => Notification::TYPE_APPOINTMENT_REMINDER,
                'channel' => Notification::CHANNEL_IN_APP,
                'payload' => '{}',
                'status' => Notification::STATUS_SENT,
                'sensitive' => true,
            ]);
        });
    })->toThrow(QueryException::class);

    expect(Notification::query()->count())->toBe(1);
});

it('refuses to remind a completed follow-up (409) without side effects', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = reminderDoctor($org, $facility, $doctorUser);
    $encounter = reminderEncounter($org, $facility, $doctor);
    $followUp = reminderPlan($org, $facility, $encounter, ['status' => FollowUp::STATUS_COMPLETED]);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/remind')
        ->assertStatus(409);

    expect(Notification::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'follow_up.reminder_created')->count())->toBe(0);
});

it('enforces authorization: view-only nurse can read but not trigger; no followup role is denied', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = reminderDoctor($org, $facility, $doctorUser);
    $encounter = reminderEncounter($org, $facility, $doctor);
    $followUp = reminderPlan($org, $facility, $encounter);
    Notification::factory()->create([
        'tenant_id' => $org->getKey(),
        'patient_id' => $encounter->patient_id,
        'follow_up_id' => $followUp->getKey(),
    ]);

    $nurse = Identity::user();
    reminderDoctor($org, $facility, $nurse);
    Identity::assign($nurse, 'nurse', $org, $facility);

    // followup:view → read allowed.
    $this->withToken(Identity::tokenFor($nurse))
        ->getJson('/api/v1/follow-ups/'.$followUp->getKey().'/reminder')
        ->assertOk();

    // No followup:manage → trigger denied.
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/remind')
        ->assertStatus(403);

    // No follow-up permission at all (pharmacist) → read denied.
    $pharmacist = Identity::user();
    reminderDoctor($org, $facility, $pharmacist);
    Identity::assign($pharmacist, 'pharmacist', $org, $facility);
    $this->withToken(Identity::tokenFor($pharmacist))
        ->getJson('/api/v1/follow-ups/'.$followUp->getKey().'/reminder')
        ->assertStatus(403);

    expect(Notification::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'follow_up.reminder_created')->count())->toBe(0);
});

it('requires authentication (401)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = reminderDoctor($org, $facility, $doctorUser);
    $encounter = reminderEncounter($org, $facility, $doctor);
    $followUp = reminderPlan($org, $facility, $encounter);

    // Flush any inherited bearer header so the request is genuinely
    // unauthenticated (established pattern).
    $this->flushHeaders();
    $this->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/remind')->assertStatus(401);
});

it('enforces cross-tenant isolation: no existence leak, data untouched', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $doctorAUser = Identity::user();
    $doctorA = reminderDoctor($orgA, $facilityA, $doctorAUser);
    $encounterA = reminderEncounter($orgA, $facilityA, $doctorA);
    $followUpA = reminderPlan($orgA, $facilityA, $encounterA);

    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $doctorB = Identity::user();
    reminderDoctor($orgB, $facilityB, $doctorB);
    Identity::assign($doctorB, 'doctor', $orgB, $facilityB);

    // Read is invisible (404); write is denied (403); nothing is created.
    $this->withToken(Identity::tokenFor($doctorB))
        ->getJson('/api/v1/follow-ups/'.$followUpA->getKey().'/reminder')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($doctorB))
        ->postJson('/api/v1/follow-ups/'.$followUpA->getKey().'/remind')
        ->assertStatus(403);

    expect(Notification::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'follow_up.reminder_created')->count())->toBe(0);
});

it('enforces cross-facility isolation within the tenant (404 read / 403 write)', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org);
    $doctorAUser = Identity::user();
    $doctorA = reminderDoctor($org, $facilityA, $doctorAUser);
    $encounterA = reminderEncounter($org, $facilityA, $doctorA);
    $followUpA = reminderPlan($org, $facilityA, $encounterA);

    $facilityB = Identity::facility($org);
    $doctorB = Identity::user();
    reminderDoctor($org, $facilityB, $doctorB);
    Identity::assign($doctorB, 'doctor', $org, $facilityB);

    $this->withToken(Identity::tokenFor($doctorB))
        ->getJson('/api/v1/follow-ups/'.$followUpA->getKey().'/reminder')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($doctorB))
        ->postJson('/api/v1/follow-ups/'.$followUpA->getKey().'/remind')
        ->assertStatus(403);

    expect(Notification::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'follow_up.reminder_created')->count())->toBe(0);
});

it('keeps patient identifiers and plan reasons out of audit and notification payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = reminderDoctor($org, $facility, $doctorUser);
    $encounter = reminderEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $patient = Patient::query()->findOrFail($encounter->patient_id);
    $patientName = $patient->full_name;

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounter->getKey().'/follow-ups', [
            'followUpType' => 'return_visit',
            'plannedAt' => now()->addDays(5)->setTime(10, 0)->toISOString(),
            'reason' => 'Review the nausea episode with '.$patientName.' and adjust therapy.',
        ])
        ->assertStatus(201);

    // Neither the audit trail nor the notification payload carries the
    // patient name, the reason text, or any free text.
    foreach (AuditEvent::query()->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain($patientName)
            ->and($encoded)->not->toContain('nausea')
            ->and($encoded)->not->toContain('Review the');
    }

    $notification = Notification::query()->firstOrFail();
    expect(json_encode($notification->payload))->not->toContain($patientName)
        ->and(json_encode($notification->payload))->not->toContain('nausea');

    // Facts are present in the reminder audit event.
    $event = AuditEvent::query()->where('action', 'follow_up.reminder_created')->firstOrFail();
    expect($event->payload)
        ->toHaveKey('followUpId')
        ->toHaveKey('patientId', $encounter->patient_id)
        ->toHaveKey('channel', Notification::CHANNEL_IN_APP)
        ->toHaveKey('plannedAt');
});
