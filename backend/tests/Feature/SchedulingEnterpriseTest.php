<?php

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\ResourceBooking;
use App\Models\ScheduleTemplate;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Scheduling enterprise hardening — status state machine, no-show, rescheduling,
 * service-duration-aware availability, resource booking exclusion constraint.
 */
beforeEach(function (): void {
    seedIdentity();
});

function makeSchedule(Organization $org, Facility $facility, Staff $doctor, ?string $date = null): string
{
    $date ??= CarbonImmutable::parse('next monday')->toDateString();
    ScheduleTemplate::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $doctor->getKey(),
        'day_of_week' => (int) CarbonImmutable::parse($date)->format('w'),
        'starts_at' => '09:00',
        'ends_at' => '11:00',
        'slot_minutes' => 30,
        'valid_from' => today()->toDateString(),
        'status' => 'active',
    ]);

    return $date;
}

// ── Status state machine ──────────────────────────────────────────────

it('enforces valid status transitions and rejects invalid ones', function () {
    $appointment = new Appointment;
    $appointment->status = Appointment::STATUS_BOOKED;

    // Valid: booked → checked_in
    expect($appointment->canTransitionTo(Appointment::STATUS_CHECKED_IN))->toBeTrue();
    expect($appointment->canTransitionTo(Appointment::STATUS_CANCELLED))->toBeTrue();
    expect($appointment->canTransitionTo(Appointment::STATUS_NO_SHOW))->toBeTrue();

    // Invalid: booked → completed
    expect($appointment->canTransitionTo(Appointment::STATUS_COMPLETED))->toBeFalse();
    expect($appointment->canTransitionTo(Appointment::STATUS_IN_CONSULTATION))->toBeFalse();

    // Valid: checked_in → in_consultation
    $appointment->transitionTo(Appointment::STATUS_CHECKED_IN);
    expect($appointment->status)->toBe(Appointment::STATUS_CHECKED_IN);
    expect($appointment->canTransitionTo(Appointment::STATUS_IN_CONSULTATION))->toBeTrue();

    // Valid: in_consultation → completed
    $appointment->transitionTo(Appointment::STATUS_IN_CONSULTATION);
    expect($appointment->canTransitionTo(Appointment::STATUS_COMPLETED))->toBeTrue();

    // Terminal: completed → anything is invalid
    $appointment->transitionTo(Appointment::STATUS_COMPLETED);
    expect($appointment->isTerminal())->toBeTrue();
    expect($appointment->canTransitionTo(Appointment::STATUS_BOOKED))->toBeFalse();
    expect($appointment->canTransitionTo(Appointment::STATUS_CANCELLED))->toBeFalse();
});

it('rejects invalid transition with exception', function () {
    $appointment = new Appointment;
    $appointment->status = Appointment::STATUS_COMPLETED;

    $appointment->transitionTo(Appointment::STATUS_BOOKED);
})->throws(InvalidArgumentException::class, 'Cannot transition from [completed] to [booked].');

// ── No-show ───────────────────────────────────────────────────────────

it('marks a booked appointment as no-show', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $date = makeSchedule($org, $facility, $doctor);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => CarbonImmutable::parse($date.' 09:00:00')->toISOString(),
            'endsAt' => CarbonImmutable::parse($date.' 09:30:00')->toISOString(),
        ])
        ->assertCreated();
    $appointmentId = $response->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$appointmentId.'/no-show')
        ->assertOk()
        ->assertJsonPath('data.status', 'no_show');

    expect(AuditEvent::query()->where('action', 'appointment.no_show')->exists())->toBeTrue();
});

it('refuses no-show on a completed appointment', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $appointment = Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'status' => Appointment::STATUS_COMPLETED,
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/no-show')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

// ── Rescheduling ──────────────────────────────────────────────────────

it('reschedules an appointment preserving provenance', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $date = makeSchedule($org, $facility, $doctor);

    // Book at 09:00
    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => CarbonImmutable::parse($date.' 09:00:00')->toISOString(),
            'endsAt' => CarbonImmutable::parse($date.' 09:30:00')->toISOString(),
        ])
        ->assertCreated();
    $oldId = $response->json('data.id');

    // Reschedule to 10:00
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$oldId.'/reschedule', [
            'startsAt' => CarbonImmutable::parse($date.' 10:00:00')->toISOString(),
            'endsAt' => CarbonImmutable::parse($date.' 10:30:00')->toISOString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'booked')
        ->assertJsonPath('data.rescheduledFrom', $oldId);

    // Old appointment is cancelled
    $this->assertDatabaseHas('appointments', [
        'id' => $oldId,
        'status' => 'cancelled',
        'cancel_reason' => 'Rescheduled by staff',
    ]);

    // New appointment exists and points back
    $newAppointment = Appointment::query()->where('rescheduled_from', $oldId)->first();
    expect($newAppointment)->not->toBeNull()
        ->and($newAppointment->status)->toBe('booked');

    // Audit trail preserved
    expect(AuditEvent::query()->where('action', 'appointment.rescheduled')->exists())->toBeTrue();
});

it('refuses reschedule to an occupied slot', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $patientA = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $date = makeSchedule($org, $facility, $doctor);

    // Book 09:00 for patient A
    $responseA = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientA->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => CarbonImmutable::parse($date.' 09:00:00')->toISOString(),
            'endsAt' => CarbonImmutable::parse($date.' 09:30:00')->toISOString(),
        ])
        ->assertCreated();

    // Book 10:00 for patient B
    $responseB = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientB->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => CarbonImmutable::parse($date.' 10:00:00')->toISOString(),
            'endsAt' => CarbonImmutable::parse($date.' 10:30:00')->toISOString(),
        ])
        ->assertCreated();
    $appointmentBId = $responseB->json('data.id');

    // Try to reschedule B into A's slot (09:00)
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$appointmentBId.'/reschedule', [
            'startsAt' => CarbonImmutable::parse($date.' 09:00:00')->toISOString(),
            'endsAt' => CarbonImmutable::parse($date.' 09:30:00')->toISOString(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

// ── Service-duration-aware availability ────────────────────────────────

it('returns service-duration-aware block slots when service has default_duration_minutes', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $date = CarbonImmutable::parse('next monday')->toDateString();

    // 30-min template slots, 09:00–10:00 → 2 slots (09:00, 09:30)
    ScheduleTemplate::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $doctor->getKey(),
        'day_of_week' => (int) CarbonImmutable::parse($date)->format('w'),
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'slot_minutes' => 30,
        'valid_from' => today()->toDateString(),
        'status' => 'active',
    ]);

    // Service with 60-minute duration
    $service = Service::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'default_duration_minutes' => 60,
    ]);

    // Without serviceId: 2 standard 30-min slots
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/staff/'.$doctor->getKey().'/availability?date='.$date.'&includeUnavailable=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // With serviceId: 1 block slot (09:00–10:00) because 60-min fits once
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/staff/'.$doctor->getKey().'/availability?date='.$date.'&serviceId='.$service->getKey().'&includeUnavailable=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.startsAt', CarbonImmutable::parse($date.' 09:00:00')->toISOString())
        ->assertJsonPath('data.0.endsAt', CarbonImmutable::parse($date.' 10:00:00')->toISOString());
});

it('marks a service-duration block as unavailable when any sub-slot is full', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $date = CarbonImmutable::parse('next monday')->toDateString();

    // 30-min template slots, 09:00–10:00
    ScheduleTemplate::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $doctor->getKey(),
        'day_of_week' => (int) CarbonImmutable::parse($date)->format('w'),
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'slot_minutes' => 30,
        'valid_from' => today()->toDateString(),
        'status' => 'active',
    ]);

    // Service with 60-minute duration
    $service = Service::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'default_duration_minutes' => 60,
    ]);

    // Book 09:30 sub-slot
    Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'starts_at' => CarbonImmutable::parse($date.' 09:30:00'),
        'ends_at' => CarbonImmutable::parse($date.' 10:00:00'),
        'status' => 'booked',
    ]);

    // The 09:00–10:00 block should be unavailable because 09:30 is booked
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/staff/'.$doctor->getKey().'/availability?date='.$date.'&serviceId='.$service->getKey())
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // With includeUnavailable, should show 1 block with available=false
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/staff/'.$doctor->getKey().'/availability?date='.$date.'&serviceId='.$service->getKey().'&includeUnavailable=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.available', false);
});

// ── Resource booking exclusion constraint ──────────────────────────────

it('rejects overlapping resource bookings at the database level', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);

    ResourceBooking::create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'resource_type' => 'room',
        'resource_id' => Str::uuid(),
        'booking_code' => 'RB-'.strtoupper(Str::random(8)),
        'title' => 'First booking',
        'starts_at' => now(),
        'ends_at' => now()->addHours(2),
        'status' => 'reserved',
    ]);

    // Overlapping booking should fail with exclusion constraint violation
    ResourceBooking::create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'resource_type' => 'room',
        'resource_id' => ResourceBooking::first()->resource_id,
        'booking_code' => 'RB-'.strtoupper(Str::random(8)),
        'title' => 'Overlapping booking',
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(3),
        'status' => 'reserved',
    ]);
})->throws(QueryException::class);

it('allows non-overlapping resource bookings for the same resource', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $resourceId = Str::uuid();

    ResourceBooking::create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'resource_type' => 'room',
        'resource_id' => $resourceId,
        'booking_code' => 'RB-'.strtoupper(Str::random(8)),
        'title' => 'First booking',
        'starts_at' => now(),
        'ends_at' => now()->addHours(2),
        'status' => 'reserved',
    ]);

    // Non-overlapping: starts after first ends
    ResourceBooking::create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'resource_type' => 'room',
        'resource_id' => $resourceId,
        'booking_code' => 'RB-'.strtoupper(Str::random(8)),
        'title' => 'Second booking',
        'starts_at' => now()->addHours(2)->addMinute(),
        'ends_at' => now()->addHours(4),
        'status' => 'reserved',
    ]);

    expect(ResourceBooking::count())->toBe(2);
});

it('allows overlapping cancelled bookings for the same resource', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $resourceId = Str::uuid();

    ResourceBooking::create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'resource_type' => 'room',
        'resource_id' => $resourceId,
        'booking_code' => 'RB-'.strtoupper(Str::random(8)),
        'title' => 'Cancelled booking',
        'starts_at' => now(),
        'ends_at' => now()->addHours(2),
        'status' => 'cancelled',
    ]);

    // Overlapping but cancelled — should be allowed
    ResourceBooking::create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'resource_type' => 'room',
        'resource_id' => $resourceId,
        'booking_code' => 'RB-'.strtoupper(Str::random(8)),
        'title' => 'New booking over cancelled',
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(3),
        'status' => 'reserved',
    ]);

    expect(ResourceBooking::count())->toBe(2);
});
