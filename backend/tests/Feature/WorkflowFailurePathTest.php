<?php

use App\Models\Appointment;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\TokenIssuer;
use Carbon\CarbonImmutable;
use Tests\Support\Identity;

/**
 * The failure paths of the first clinical workflow (TESTING_STRATEGY.md §5):
 * expired sessions, malformed requests, invalid resources, wrong-role
 * gates, missing clinical content, cross-tenant payment, and the
 * concurrent double-booking race. Success paths live in
 * AppointmentBookingTest / EncounterClinicalTest / BillingPaymentTest /
 * ClinicalWorkflowE2ETest.
 */
beforeEach(function (): void {
    seedIdentity();
});

function failureDoctor(Organization $org, Facility $facility): Staff
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

function failureAppointment(Organization $org, Facility $facility, Staff $doctor, string $status = 'booked', ?string $startsAt = null): Appointment
{
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $startsAt ??= $status; // unique per status: same provider/day must not collide on the slot index
    $starts = CarbonImmutable::parse('next monday')->addHours(9);
    $starts = $starts->addHours(match ($startsAt) {
        'booked' => 0,
        'checked_in' => 1,
        'completed' => 2,
        default => 3,
    });

    return Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'starts_at' => $starts,
        'ends_at' => $starts->addMinutes(30),
        'status' => $status,
    ]);
}

it('rejects an expired access token on workflow endpoints', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org, $facility);

    // A token whose expiry is in the past must be refused by auth:sanctum
    // before any controller code runs (SECURITY.md §4).
    $expired = $admin->createToken('expired-access', [], now()->subMinute())->plainTextToken;

    $this->withToken($expired)
        ->getJson('/api/v1/appointments/queue?date='.today()->toDateString())
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');

    $this->withToken($expired)
        ->getJson('/api/v1/appointments')
        ->assertStatus(401);
});

it('rejects malformed booking requests with structured validation errors', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = failureDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org, $facility);

    // endsAt before startsAt → after:startsAt fails.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => now()->addHour()->toISOString(),
            'endsAt' => now()->addMinutes(30)->toISOString(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonStructure(['error' => ['details' => [['field', 'code', 'message']]]])
        ->assertJsonPath('error.details.0.field', 'endsAt');

    // A non-UUID patientId is refused.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => 'not-a-uuid',
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => now()->addHour()->toISOString(),
            'endsAt' => now()->addHour()->addMinutes(30)->toISOString(),
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.details.0.field', 'patientId');

    // Missing fields at all.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('refuses booking with an unknown or cross-tenant patient', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = failureDoctor($org, $facility);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org, $facility);

    $slot = now()->addHour();

    // Unknown patient id → 404 (existence is never leaked on reads).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => '00000000-0000-7000-8000-000000000000',
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => $slot->toISOString(),
            'endsAt' => $slot->addMinutes(30)->toISOString(),
        ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // A patient from another tenant → 403 (write to out-of-scope record).
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $patientB = Patient::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientB->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => $slot->toISOString(),
            'endsAt' => $slot->addMinutes(30)->toISOString(),
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});

it('hides unknown and out-of-tenant appointments on every operation', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = failureDoctor($org, $facility);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org, $facility);

    $unknown = '00000000-0000-7000-8000-000000000000';

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/appointments/'.$unknown)
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$unknown.'/check-in')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$unknown.'/cancel', ['reason' => 'test'])
        ->assertStatus(404);

    // Another tenant's appointment: read → 404, write → 403.
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $appointmentB = failureAppointment($orgB, $facilityB, failureDoctor($orgB, $facilityB));

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/appointments/'.$appointmentB->getKey())
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$appointmentB->getKey().'/check-in')
        ->assertStatus(403);
});

it('enforces every invalid status transition', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = failureDoctor($org, $facility);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org, $facility);

    // An encounter can only start from a checked-in appointment.
    $booked = failureAppointment($org, $facility, $doctor, 'booked');
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$booked->getKey().'/start-encounter')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Double check-in is refused.
    $checkedIn = failureAppointment($org, $facility, $doctor, 'checked_in');
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$checkedIn->getKey().'/check-in')
        ->assertStatus(409);

    // A completed appointment cannot be cancelled or re-entered.
    $completed = failureAppointment($org, $facility, $doctor, 'completed');
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$completed->getKey().'/cancel', ['reason' => 'too late'])
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments/'.$completed->getKey().'/check-in')
        ->assertStatus(409);

    // A signed encounter cannot be signed again.
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $signed = Encounter::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'status' => 'signed',
    ]);
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/encounters/'.$signed->getKey().'/sign')
        ->assertStatus(409);

    // Clinical content cannot be added to a signed encounter.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/encounters/'.$signed->getKey().'/diagnoses', ['description' => 'late'])
        ->assertStatus(409);
});

it('denies wrong-role actors at each clinical and financial gate', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $doctorUser = Identity::user();
    $doctor = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $doctorUser->getKey(),
        'designation' => 'Consultant Physician',
    ]);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $appointment = failureAppointment($org, $facility, $doctor, 'checked_in');
    $encounterId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/start-encounter')
        ->json('data.id');

    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'generic_name' => 'Amoxicillin',
        'strength' => '500 mg',
        'price_minor' => 3500,
    ]);

    // Nurse can document but not prescribe (encounter:prescribe not granted).
    $nurseUser = Identity::user();
    Identity::assign($nurseUser, 'nurse', $org, $facility);
    $nurse = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $nurseUser->getKey(),
        'designation' => 'Staff Nurse',
    ]);

    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/prescriptions', [
            'lines' => [[
                'medicationId' => $medication->getKey(),
                'dose' => '1 tablet',
                'route' => 'oral',
                'frequency' => 'twice daily',
            ]],
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // A doctor cannot collect payment (billing:collect is the clerk's).
    $invoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $appointment->patient_id,
        'status' => 'issued',
        'total_minor' => 10000,
        'total_tax_minor' => 0,
        'paid_minor' => 0,
        'issued_at' => now(),
    ]);

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
            'method' => 'cash',
            'amountMinor' => 10000,
            'idempotencyKey' => 'nurse-gate-'.Str::uuid(),
        ])
        ->assertStatus(403);

    // The nurse cannot sign the encounter either (encounter:sign).
    $this->withToken(Identity::tokenFor($nurseUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/sign')
        ->assertStatus(403);
});

it('refuses prescriptions and diagnoses missing required clinical content', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctorUser = Identity::user();
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $doctor = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $doctorUser->getKey(),
    ]);
    Identity::assign($doctorUser, 'doctor', $org, $facility);

    $appointment = failureAppointment($org, $facility, $doctor, 'checked_in');
    $encounterId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/appointments/'.$appointment->getKey().'/start-encounter')
        ->json('data.id');

    // Prescription with zero lines is refused.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/prescriptions', ['lines' => []])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // A diagnosis needs a description.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/diagnoses', ['code' => 'J06.9'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // A note needs non-empty structured content.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/notes', ['content' => []])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    // Prescription with an inactive (non-formulary) medication → 422.
    $inactive = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'generic_name' => 'Retired Drug',
        'status' => 'inactive',
    ]);
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/prescriptions', [
            'lines' => [[
                'medicationId' => $inactive->getKey(),
                'dose' => '1 tablet',
                'route' => 'oral',
                'frequency' => 'once daily',
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('refuses cross-tenant payment and malformed payment amounts', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    // Another tenant's invoice is invisible for payment (403 write).
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $patientB = Patient::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    $invoiceB = Invoice::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
        'status' => 'issued',
        'total_minor' => 10000,
        'total_tax_minor' => 0,
        'paid_minor' => 0,
        'issued_at' => now(),
    ]);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoiceB->getKey().'/pay', [
            'method' => 'cash',
            'amountMinor' => 10000,
            'idempotencyKey' => 'cross-tenant-'.Str::uuid(),
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // Own-tenant invoice with a zero/negative amount → validation error.
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $invoice = Invoice::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'status' => 'issued',
        'total_minor' => 10000,
        'total_tax_minor' => 0,
        'paid_minor' => 0,
        'issued_at' => now(),
    ]);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
            'method' => 'cash',
            'amountMinor' => 0,
            'idempotencyKey' => 'zero-amount-'.Str::uuid(),
        ])
        ->assertStatus(422);

    // Missing idempotency key is refused (retries would otherwise double-charge).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoice->getKey().'/pay', [
            'method' => 'cash',
            'amountMinor' => 10000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('wins the concurrent double-booking race via the unique index', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = failureDoctor($org, $facility);
    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org, $facility);

    $slot = CarbonImmutable::parse('next monday 09:00:00');
    $slotEnd = $slot->addMinutes(30);

    // Simulate the losing request arriving after the winner already
    // committed: the row exists, so the API booking for the same slot must
    // fail with CONFLICT — exactly the unique-index race outcome
    // (uq_appointments_tenant_provider_start).
    Appointment::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'patient_id' => $patient->getKey(),
        'provider_staff_id' => $doctor->getKey(),
        'starts_at' => $slot,
        'ends_at' => $slotEnd,
        'status' => 'booked',
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patient->getKey(),
            'providerStaffId' => $doctor->getKey(),
            'startsAt' => $slot->toISOString(),
            'endsAt' => $slotEnd->toISOString(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Only one row exists — no duplicate record was created.
    expect(Appointment::query()
        ->where('provider_staff_id', $doctor->getKey())
        ->where('starts_at', $slot)
        ->count())->toBe(1);
});

it('issues tokens without duplication under rapid sequential check-ins', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $doctor = failureDoctor($org, $facility);
    $issuer = app(TokenIssuer::class);
    $date = today()->toDateString();

    $tokens = collect(range(1, 25))
        ->map(fn (): int => $issuer->issue((string) $org->getKey(), (string) $facility->getKey(), (string) $doctor->getKey(), $date))
        ->all();

    expect($tokens)->toHaveCount(25)
        ->and(array_unique($tokens))->toHaveCount(25)
        ->and(min($tokens))->toBe(1)
        ->and(max($tokens))->toBe(25);

    // A new day resets the sequence (queue_date is part of the key).
    $next = $issuer->issue((string) $org->getKey(), (string) $facility->getKey(), (string) $doctor->getKey(), today()->addDay()->toDateString());
    expect($next)->toBe(1);
});
