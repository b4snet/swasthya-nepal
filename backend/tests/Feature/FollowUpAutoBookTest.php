<?php

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use Tests\Support\Identity;

/**
 * Phase 3 slice 9 — appointment auto-creation from follow-up plans
 * (PRODUCT_REQUIREMENTS §6.7, DATABASE.md §3.17a): the plan BECOMES the
 * booking — the appointment is created from the follow-up plan (patient,
 * provider, facility, planned time) and linked to it in one atomic step, so
 * no separately-booked appointment is needed. The slot races on the
 * provider-start unique index (one live booking per provider × start), and
 * the plan's own transition (planned → booked) is a compare-and-swap — two
 * plans for the same provider and start can never both book.
 */
beforeEach(function (): void {
    seedIdentity();
});

function followUpAutoDoctor(Organization $org, Facility $facility, User $user): Staff
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

function followUpAutoEncounter(Organization $org, Facility $facility, Staff $doctor): Encounter
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

function followUpAutoPlan(Organization $org, Facility $facility, Encounter $encounter, array $overrides = []): FollowUp
{
    return FollowUp::factory()->create(array_merge([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $encounter->patient_id,
        'encounter_id' => $encounter->getKey(),
        'provider_staff_id' => $encounter->provider_staff_id,
    ], $overrides));
}

it('auto-books a planned follow-up: the plan becomes the appointment', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = followUpAutoDoctor($org, $facility, $doctorUser);
    $encounter = followUpAutoEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $plannedAt = now()->addDays(5)->setTime(10, 0);
    $followUp = followUpAutoPlan($org, $facility, $encounter, ['planned_at' => $plannedAt]);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/auto-book')
        ->assertOk()
        ->assertJsonPath('data.followUp.status', FollowUp::STATUS_BOOKED)
        ->assertJsonPath('data.followUp.bookedAppointmentId', fn (mixed $v) => is_string($v))
        ->assertJsonPath('data.appointment.patientId', $encounter->patient_id)
        ->assertJsonPath('data.appointment.providerStaffId', $doctor->getKey())
        ->assertJsonPath('data.appointment.appointmentType', 'follow_up')
        ->assertJsonPath('data.appointment.source', 'follow_up')
        ->assertJsonPath('data.appointment.status', Appointment::STATUS_BOOKED)
        ->assertJsonPath('data.appointment.startsAt', $plannedAt->toIso8601String())
        ->assertJsonPath('data.appointment.endsAt', $plannedAt->copy()->addMinutes(15)->toIso8601String());

    // Exactly one appointment, linked to the plan, same patient/facility/provider.
    $appointment = Appointment::query()->where('id', $followUp->fresh()->booked_appointment_id)->firstOrFail();
    expect(Appointment::query()->count())->toBe(1)
        ->and($appointment->facility_id)->toBe($facility->getKey())
        ->and($appointment->patient_id)->toBe($encounter->patient_id)
        ->and($appointment->provider_staff_id)->toBe($doctor->getKey())
        ->and($appointment->service_id)->toBeNull()
        ->and($followUp->refresh()->status)->toBe(FollowUp::STATUS_BOOKED)
        ->and($followUp->lock_version)->toBe(1);

    // Both resources audited — facts only.
    expect(AuditEvent::query()->where('action', 'appointment.booked')->where('resource_type', 'appointment')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'follow_up.booked')->count())->toBe(1);
});

it('maps a teleconsult follow-up to a teleconsult appointment', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = followUpAutoDoctor($org, $facility, $doctorUser);
    $encounter = followUpAutoEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $followUp = followUpAutoPlan($org, $facility, $encounter, ['follow_up_type' => FollowUp::TYPE_TELECONSULT]);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/auto-book')
        ->assertOk()
        ->assertJsonPath('data.appointment.appointmentType', 'teleconsult');
});

it('refuses a second auto-book: the plan is booked once, one appointment', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = followUpAutoDoctor($org, $facility, $doctorUser);
    $encounter = followUpAutoEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $followUp = followUpAutoPlan($org, $facility, $encounter);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/auto-book')
        ->assertOk();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/auto-book')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(Appointment::query()->count())->toBe(1)
        ->and(FollowUp::query()->findOrFail($followUp->getKey())->status)->toBe(FollowUp::STATUS_BOOKED);
});

it('refuses auto-book from cancelled or completed states', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = followUpAutoDoctor($org, $facility, $doctorUser);
    $encounter = followUpAutoEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $cancelled = followUpAutoPlan($org, $facility, $encounter, ['status' => FollowUp::STATUS_CANCELLED, 'cancel_reason' => 'No longer needed']);
    $completed = followUpAutoPlan($org, $facility, $encounter, ['status' => FollowUp::STATUS_COMPLETED]);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$cancelled->getKey().'/auto-book')
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$completed->getKey().'/auto-book')
        ->assertStatus(409);

    expect(Appointment::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'follow_up.booked')->count())->toBe(0);
});

it('never double-books a provider slot: two plans at the same start, one winner', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = followUpAutoDoctor($org, $facility, $doctorUser);
    $encounter = followUpAutoEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    // Two DIFFERENT patients' follow-ups, same provider, same planned time.
    $plannedAt = now()->addDays(4)->setTime(11, 30);
    $planA = followUpAutoPlan($org, $facility, $encounter, ['planned_at' => $plannedAt]);

    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $encounterB = Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patientB->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'status' => Encounter::STATUS_OPEN,
    ]);
    $planB = followUpAutoPlan($org, $facility, $encounterB, ['planned_at' => $plannedAt]);

    // The first auto-book wins the slot…
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$planA->getKey().'/auto-book')
        ->assertOk();

    // …the second collides on the provider-start unique index and is
    // refused — the plan stays planned, no orphan appointment exists.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$planB->getKey().'/auto-book')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    expect(Appointment::query()->count())->toBe(1)
        ->and(FollowUp::query()->findOrFail($planA->getKey())->status)->toBe(FollowUp::STATUS_BOOKED)
        ->and(FollowUp::query()->findOrFail($planB->getKey())->status)->toBe(FollowUp::STATUS_PLANNED);
});

it('enforces RBAC: follow-up manage holders only, unauthenticated 401', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = followUpAutoDoctor($org, $facility, $doctorUser);
    $encounter = followUpAutoEncounter($org, $facility, $doctor);
    $followUp = followUpAutoPlan($org, $facility, $encounter);

    // Unauthenticated → 401 (flush the doctor's token from the fixtures).
    $this->flushHeaders();
    $this->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/auto-book')
        ->assertStatus(401);

    // A nurse holds followup:view only — auto-booking needs followup:manage.
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $org, $facility);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/auto-book')
        ->assertStatus(403);

    // The doctor (followup:manage) succeeds.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/auto-book')
        ->assertOk();

    expect(Appointment::query()->count())->toBe(1);
});

it('enforces cross-tenant and cross-facility isolation for auto-booking', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $doctorA = Identity::user();
    $doctorAStaff = followUpAutoDoctor($orgA, $facilityA, $doctorA);
    $encounterA = followUpAutoEncounter($orgA, $facilityA, $doctorAStaff);
    $followUpA = followUpAutoPlan($orgA, $facilityA, $encounterA);
    Identity::assign($doctorA, 'doctor', $orgA, $facilityA);

    // Tenant-B doctor attacks tenant A's plan: read 404, write 403, and no
    // appointment is ever created.
    $doctorB = Identity::user();
    followUpAutoDoctor($orgB, $facilityB, $doctorB);
    Identity::assign($doctorB, 'doctor', $orgB, $facilityB);

    $this->withToken(Identity::tokenFor($doctorB))
        ->postJson('/api/v1/follow-ups/'.$followUpA->getKey().'/auto-book')
        ->assertStatus(403);

    // Cross-facility within the same tenant: facility-B doctor (tenant A).
    $facilityA2 = Identity::facility($orgA);
    $doctorA2 = Identity::user();
    followUpAutoDoctor($orgA, $facilityA2, $doctorA2);
    Identity::assign($doctorA2, 'doctor', $orgA, $facilityA2);

    $this->withToken(Identity::tokenFor($doctorA2))
        ->postJson('/api/v1/follow-ups/'.$followUpA->getKey().'/auto-book')
        ->assertStatus(403);

    // Tenant A's data is untouched.
    expect(Appointment::query()->count())->toBe(0)
        ->and(FollowUp::query()->findOrFail($followUpA->getKey())->status)->toBe(FollowUp::STATUS_PLANNED)
        ->and(AuditEvent::query()->where('action', 'follow_up.booked')->count())->toBe(0);
});

it('keeps patient identifiers and plan reasons out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $doctor = followUpAutoDoctor($org, $facility, $doctorUser);
    $encounter = followUpAutoEncounter($org, $facility, $doctor);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $patientName = Patient::query()->findOrFail($encounter->patient_id)->full_name;
    $followUp = followUpAutoPlan($org, $facility, $encounter, ['reason' => 'Sensitive follow-up reason about the patient prognosis']);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/follow-ups/'.$followUp->getKey().'/auto-book')
        ->assertOk();

    foreach (AuditEvent::query()->whereIn('action', ['appointment.booked', 'follow_up.booked'])->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain($patientName)
            ->and($encoded)->not->toContain('Sensitive follow-up reason')
            ->and($encoded)->not->toContain('prognosis');
    }

    // Facts are present.
    $booked = AuditEvent::query()->where('action', 'follow_up.booked')->firstOrFail();
    expect($booked->payload)->toHaveKey('appointmentId');
});
