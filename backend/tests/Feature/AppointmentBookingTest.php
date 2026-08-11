<?php

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Tests\Support\Identity;

/**
 * Phase 6 — Front Desk (DATABASE.md §3.15–3.16): derived availability,
 * row-locked slot booking, check-in with race-safe tokens, queue ordering,
 * cancellation with reason.
 */
beforeEach(function (): void {
    seedIdentity();
});

function makeDoctor(Organization $org, Facility $facility): Staff
{
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    return Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'designation' => 'Consultant Physician',
        'status' => 'active',
    ]);
}

it('derives availability slots from templates and subtracts exceptions and bookings', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    // Monday (1) 09:00–11:00, 30-min slots → 4 slots.
    $date = CarbonImmutable::parse('next monday')->toDateString();
    $template = ScheduleTemplate::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $doctor->getKey(),
        'day_of_week' => 1,
        'starts_at' => '09:00',
        'ends_at' => '11:00',
        'slot_minutes' => 30,
        'valid_from' => today()->toDateString(),
        'status' => 'active',
    ]);

    // A booked slot reduces availability by one.
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $slotStart = CarbonImmutable::parse($date.' 09:30:00');
    Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'starts_at' => $slotStart,
        'ends_at' => $slotStart->addMinutes(30),
        'status' => 'booked',
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/staff/'.$doctor->getKey().'/availability?date='.$date.'&includeUnavailable=1')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.startsAt', CarbonImmutable::parse($date.' 09:00:00')->toISOString())
        ->assertJsonPath('data.1.startsAt', CarbonImmutable::parse($date.' 09:30:00')->toISOString())
        ->assertJsonPath('data.1.available', false);

    // An exception removes the whole day.
    ScheduleException::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $doctor->getKey(),
        'exception_date' => $date,
        'reason' => 'leave',
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/staff/'.$doctor->getKey().'/availability?date='.$date)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('books an appointment against an open slot and refuses a double-booking', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $service = Service::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'name' => 'OPD Consultation',
        'code' => 'OPD-01',
        'service_type' => 'opd_consultation',
        'default_charge_minor' => 50000,
        'currency' => 'NPR',
    ]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $date = CarbonImmutable::parse('next monday')->toDateString();
    ScheduleTemplate::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $doctor->getKey(),
        'day_of_week' => 1,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'slot_minutes' => 30,
        'valid_from' => today()->toDateString(),
        'status' => 'active',
    ]);

    $slot = CarbonImmutable::parse($date.' 09:00:00');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'serviceId' => $service->getKey(),
            'startsAt' => $slot->toISOString(),
            'endsAt' => $slot->addMinutes(30)->toISOString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'booked')
        ->assertJsonPath('data.patientId', $patient->getKey());

    // Same slot → 409 CONFLICT (availability + unique index).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => $slot->toISOString(),
            'endsAt' => $slot->addMinutes(30)->toISOString(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // A different slot in the same window is fine.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => $slot->addMinutes(30)->toISOString(),
            'endsAt' => $slot->addMinutes(60)->toISOString(),
        ])
        ->assertCreated();

    expect(Appointment::query()->count())->toBe(2);
});

it('rejects a slot outside the derived availability', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    // No template at all for this doctor.
    $date = CarbonImmutable::parse('next monday')->toDateString();
    $slot = CarbonImmutable::parse($date.' 09:00:00');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => $slot->toISOString(),
            'endsAt' => $slot->addMinutes(30)->toISOString(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');
});

it('checks a patient in and issues sequential tokens per provider and day', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $patientA = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);

    $date = CarbonImmutable::parse('next monday')->toDateString();
    $appointmentA = Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patientA->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'starts_at' => CarbonImmutable::parse($date.' 09:00:00'),
        'ends_at' => CarbonImmutable::parse($date.' 09:30:00'),
        'status' => 'booked',
    ]);
    $appointmentB = Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patientB->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'starts_at' => CarbonImmutable::parse($date.' 10:00:00'),
        'ends_at' => CarbonImmutable::parse($date.' 10:30:00'),
        'status' => 'booked',
    ]);

    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/appointments/'.$appointmentA->getKey().'/check-in')
        ->assertOk()
        ->assertJsonPath('data.status', 'checked_in')
        ->assertJsonPath('data.tokenNo', 1);

    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/appointments/'.$appointmentB->getKey().'/check-in')
        ->assertOk()
        ->assertJsonPath('data.tokenNo', 2);

    // The queue shows both, ordered by token.
    $this->withToken(Identity::tokenFor($receptionist))
        ->getJson('/api/v1/appointments/queue?date='.$date)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.tokenNo', 1)
        ->assertJsonPath('data.1.tokenNo', 2);
});

it('audits bookings, check-ins, and cancellations with facts only', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = makeDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $date = CarbonImmutable::parse('next monday')->toDateString();
    ScheduleTemplate::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $doctor->getKey(),
        'day_of_week' => 1,
        'starts_at' => '09:00',
        'ends_at' => '10:00',
        'slot_minutes' => 30,
        'valid_from' => today()->toDateString(),
        'status' => 'active',
    ]);

    $slot = CarbonImmutable::parse($date.' 09:00:00');

    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => $slot->toISOString(),
            'endsAt' => $slot->addMinutes(30)->toISOString(),
        ])
        ->assertCreated();
    $appointmentId = $response->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$appointmentId.'/check-in')
        ->assertOk();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$appointmentId.'/cancel', ['reason' => 'Patient rescheduled to next week'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect(AuditEvent::query()->where('action', 'appointment.booked')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'appointment.checked_in')->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'appointment.cancelled')->exists())->toBeTrue();

    $cancel = AuditEvent::query()->where('action', 'appointment.cancelled')->firstOrFail();
    expect($cancel->payload['reason'])->toBe('Patient rescheduled to next week')
        ->and($cancel->facility_id)->toBe($facility->getKey());
});

it('refuses check-in and cancellation in invalid states', function () {
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
        'status' => 'cancelled',
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/check-in')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/cancel', ['reason' => 'again'])
        ->assertStatus(409);

    // Cancellation without a reason is a validation error.
    $booked = Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'status' => 'booked',
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$booked->getKey().'/cancel', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});
