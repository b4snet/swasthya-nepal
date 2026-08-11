<?php

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Charge;
use App\Models\ClinicalNote;
use App\Models\Department;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\ScheduleTemplate;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Tests\Support\Identity;

/**
 * THE first complete clinical workflow, end to end, through the real API
 * and the real database:
 *
 *   register patient → book appointment → check in (token) → queue →
 *   start encounter → clinical note → diagnosis → prescription →
 *   sign encounter → generate charges → issue invoice → pay → audit
 *
 * Every step uses real records; nothing is simulated. This is the vertical
 * slice that proves the architecture (ROADMAP.md Phase 6 Milestone M1).
 */
beforeEach(function (): void {
    seedIdentity();
});

it('runs the complete patient-to-payment clinical workflow end to end', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    // Principals.
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);
    $doctorUser = Identity::user();
    $doctor = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $doctorUser->getKey(),
        'designation' => 'Consultant Physician',
        'status' => 'active',
    ]);
    Identity::assign($doctorUser, 'doctor', $org, $facility);
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    // Setup: a service with a charge, a medication, a schedule for Monday.
    $service = Service::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'name' => 'OPD Consultation',
        'code' => 'OPD-01',
        'service_type' => 'opd_consultation',
        'default_charge_minor' => 50000,
        'currency' => 'NPR',
    ]);
    $medication = Medication::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'generic_name' => 'Paracetamol',
        'strength' => '500 mg',
        'price_minor' => 2000,
    ]);
    $monday = CarbonImmutable::parse('next monday')->toDateString();
    ScheduleTemplate::factory()->create([
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

    // 1. Register the patient.
    $patientResponse = $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/patients', [
            'facilityId' => $facility->getKey(),
            'fullName' => 'Bimala Tamang',
            'dateOfBirth' => '1988-07-19',
            'sex' => 'female',
            'phone' => '+977-9841-556677',
        ])
        ->assertCreated();
    $patientId = $patientResponse->json('data.id');

    expect(Patient::query()->where('mrn', 'MRN-000001')->exists())->toBeTrue();

    // 2. Book the appointment on an open slot.
    $slot = CarbonImmutable::parse($monday.' 09:00:00');
    $appointmentResponse = $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/appointments', [
            'patientId' => $patientId,
            'providerStaffId' => $doctor->getKey(),
            'serviceId' => $service->getKey(),
            'startsAt' => $slot->toISOString(),
            'endsAt' => $slot->addMinutes(30)->toISOString(),
        ])
        ->assertCreated();
    $appointmentId = $appointmentResponse->json('data.id');

    // 3. Check in → token issued.
    $this->withToken(Identity::tokenFor($receptionist))
        ->postJson('/api/v1/appointments/'.$appointmentId.'/check-in')
        ->assertOk()
        ->assertJsonPath('data.tokenNo', 1)
        ->assertJsonPath('data.status', 'checked_in');

    // 4. Queue shows the patient with token 1.
    $this->withToken(Identity::tokenFor($receptionist))
        ->getJson('/api/v1/appointments/queue?date='.$monday)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tokenNo', 1)
        ->assertJsonPath('data.0.patient.mrn', 'MRN-000001');

    // 5. Doctor starts the encounter.
    $encounterId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/appointments/'.$appointmentId.'/start-encounter')
        ->assertCreated()
        ->json('data.id');

    // 6. Clinical documentation.
    $noteId = $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/notes', [
            'content' => [
                'complaint' => 'Fever and sore throat for 3 days',
                'history' => 'No comorbidities',
                'examination' => 'Temp 100.4F, pharyngeal congestion',
            ],
        ])
        ->assertCreated()
        ->json('data.id');

    // 7. Diagnosis (final, primary).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/diagnoses', [
            'code' => 'J02.9',
            'codingSystem' => 'icd10',
            'description' => 'Acute pharyngitis',
            'diagnosisType' => 'final',
            'isPrimary' => true,
        ])
        ->assertCreated();

    // 8. Prescription (15 tablets × NPR 20.00).
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/prescriptions', [
            'notes' => 'Take after food',
            'lines' => [
                [
                    'medicationId' => $medication->getKey(),
                    'dose' => '1 tablet',
                    'route' => 'oral',
                    'frequency' => 'three times daily',
                    'duration' => '5 days',
                    'quantityMinor' => 15,
                ],
            ],
        ])
        ->assertCreated();

    // 9. Sign the note, then the encounter.
    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/notes/'.$noteId.'/sign')
        ->assertOk();

    $this->withToken(Identity::tokenFor($doctorUser))
        ->postJson('/api/v1/encounters/'.$encounterId.'/sign')
        ->assertOk();

    // 10. Generate the bill: consultation (50,000) + medication (2,000 × 15).
    $invoiceResponse = $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/encounters/'.$encounterId.'/invoice', [
            'chargeIds' => [], // server derives charges from the encounter
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.totalMinor', 80000);

    $invoiceId = $invoiceResponse->json('data.id');

    expect(Charge::query()->count())->toBe(2)
        ->and((int) Charge::query()->where('source_type', 'encounter')->sum('amount_minor'))->toBe(50000)
        ->and((int) Charge::query()->where('source_type', 'prescription')->sum('amount_minor'))->toBe(30000);

    // 11. Payment settles the invoice.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/invoices/'.$invoiceId.'/pay', [
            'method' => 'cash',
            'amountMinor' => 80000,
            'idempotencyKey' => 'e2e-'.Str::uuid(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.invoice.status', 'paid');

    // 12. Audit: the whole chain is on the record.
    $actions = AuditEvent::query()->pluck('action')->all();
    foreach ([
        'patient.created',
        'appointment.booked',
        'appointment.checked_in',
        'encounter.started',
        'note.drafted',
        'diagnosis.added',
        'prescription.drafted',
        'note.signed',
        'encounter.signed',
        'invoice.issued',
        'payment.captured',
    ] as $action) {
        expect($actions)->toContain($action);
    }

    // Final state is real and consistent.
    expect(Encounter::query()->where('id', $encounterId)->value('status'))->toBe('signed')
        ->and(Appointment::query()->where('id', $appointmentId)->value('status'))->toBe('completed')
        ->and(Invoice::query()->where('id', $invoiceId)->value('status'))->toBe('paid')
        ->and(Diagnosis::query()->where('encounter_id', $encounterId)->count())->toBe(1)
        ->and(Prescription::query()->where('encounter_id', $encounterId)->count())->toBe(1)
        ->and(ClinicalNote::query()->where('encounter_id', $encounterId)->where('status', 'signed')->count())->toBe(1);
});
