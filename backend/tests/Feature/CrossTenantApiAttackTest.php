<?php

use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Branch;
use App\Models\Charge;
use App\Models\ClinicalNote;
use App\Models\Consent;
use App\Models\Department;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\FacilitySetting;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Location;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientContact;
use App\Models\PatientDocument;
use App\Models\PatientIdentifier;
use App\Models\PatientTimelineEntry;
use App\Models\Payer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Room;
use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * PROGRAM PHASE 1 — adversarial API-level cross-tenant attack suite.
 *
 * Tenant B owns a complete, realistic record graph (catalogs, patient
 * master, scheduling, clinical, billing). An org_admin of tenant A — who has
 * the full permission set for tenant A — attempts to reach tenant B's
 * records by ID swapping (IDOR/BOLA), to create child records under tenant
 * B's parents, and to surface B's data through search/list surfaces. Every
 * attempt must fail with a safe denial (404/403) and must not mutate B's
 * data. The 404-for-reads contract (existence is never leaked) is the
 * established Laravel behavior and is asserted exactly.
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * Build tenant B's complete record graph and return the models the attacks
 * need.
 *
 * @return array<string, mixed>
 */
function victimTenantB(): array
{
    $orgB = Identity::organization(['code' => 'victim-b']);
    $facilityB = Identity::facility($orgB);
    $branchB = Branch::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    $departmentB = Department::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'branch_id' => $branchB->getKey()]);
    $locationB = Location::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'branch_id' => $branchB->getKey()]);
    $wardB = Ward::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'branch_id' => $branchB->getKey()]);
    $roomB = Room::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'ward_id' => $wardB->getKey(), 'branch_id' => $branchB->getKey()]);
    $bedB = Bed::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'room_id' => $roomB->getKey(), 'branch_id' => $branchB->getKey()]);

    $staffB = Staff::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'department_id' => $departmentB->getKey(),
    ]);

    $serviceB = Service::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    $medicationB = Medication::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey()]);
    FacilitySetting::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'key' => 'victim.key']);

    $patientB = Patient::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'full_name' => 'Victim Unique Patient Name',
    ]);
    $identifierB = PatientIdentifier::factory()->create(['tenant_id' => $orgB->getKey(), 'patient_id' => $patientB->getKey()]);
    $contactB = PatientContact::factory()->create(['tenant_id' => $orgB->getKey(), 'patient_id' => $patientB->getKey()]);
    $payerB = Payer::factory()->create(['tenant_id' => $orgB->getKey()]);
    $timelineB = PatientTimelineEntry::factory()->create(['tenant_id' => $orgB->getKey(), 'patient_id' => $patientB->getKey()]);
    $consentB = Consent::factory()->create(['tenant_id' => $orgB->getKey(), 'patient_id' => $patientB->getKey()]);
    $documentB = PatientDocument::factory()->create(['tenant_id' => $orgB->getKey(), 'patient_id' => $patientB->getKey()]);

    $templateB = ScheduleTemplate::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'staff_id' => $staffB->getKey()]);
    $exceptionB = ScheduleException::factory()->create(['tenant_id' => $orgB->getKey(), 'facility_id' => $facilityB->getKey(), 'staff_id' => $staffB->getKey()]);

    $appointmentB = Appointment::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
        'provider_staff_id' => $staffB->getKey(),
        'status' => 'booked',
    ]);
    $encounterB = Encounter::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
        'provider_staff_id' => $staffB->getKey(),
        'appointment_id' => $appointmentB->getKey(),
        'status' => 'open',
    ]);
    $diagnosisB = Diagnosis::factory()->create(['tenant_id' => $orgB->getKey(), 'encounter_id' => $encounterB->getKey()]);
    $noteB = ClinicalNote::factory()->create(['tenant_id' => $orgB->getKey(), 'encounter_id' => $encounterB->getKey(), 'author_staff_id' => $staffB->getKey()]);
    $prescriptionB = Prescription::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'encounter_id' => $encounterB->getKey(),
        'patient_id' => $patientB->getKey(),
        'prescriber_staff_id' => $staffB->getKey(),
    ]);
    $lineB = PrescriptionLine::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'prescription_id' => $prescriptionB->getKey(),
        'medication_id' => $medicationB->getKey(),
    ]);
    $chargeB = Charge::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
        'encounter_id' => $encounterB->getKey(),
        'source_type' => 'manual',
    ]);
    $invoiceB = Invoice::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
        'status' => 'issued',
    ]);
    $invoiceLineB = InvoiceLine::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'invoice_id' => $invoiceB->getKey(),
        'charge_id' => $chargeB->getKey(),
    ]);
    $paymentB = Payment::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'facility_id' => $facilityB->getKey(),
        'patient_id' => $patientB->getKey(),
    ]);
    PaymentAllocation::factory()->create([
        'tenant_id' => $orgB->getKey(),
        'payment_id' => $paymentB->getKey(),
        'invoice_id' => $invoiceB->getKey(),
    ]);

    return compact(
        'orgB', 'facilityB', 'branchB', 'departmentB', 'locationB', 'wardB', 'roomB', 'bedB',
        'staffB', 'serviceB', 'medicationB', 'patientB', 'identifierB', 'contactB', 'payerB',
        'timelineB', 'consentB', 'documentB', 'templateB', 'exceptionB',
        'appointmentB', 'encounterB', 'diagnosisB', 'noteB', 'prescriptionB', 'lineB',
        'chargeB', 'invoiceB', 'invoiceLineB', 'paymentB',
    );
}

/**
 * @return array{0: Organization, 1: User, 2: Facility}
 */
function attackerTenantA(): array
{
    $orgA = Identity::organization(['code' => 'attacker-a']);
    $facilityA = Identity::facility($orgA);
    $adminA = Identity::user(['email' => 'attacker-a@isolation.test']);
    Identity::assign($adminA, 'org_admin', $orgA);

    return [$orgA, $adminA, $facilityA];
}

it('a tenant cannot READ any of the other tenant\'s resources by swapping IDs (IDOR/BOLA sweep)', function () {
    [$orgA, $adminA] = attackerTenantA();
    $b = victimTenantB();

    $reads = [
        "/api/v1/facilities/{$b['facilityB']->getKey()}",
        "/api/v1/branches/{$b['branchB']->getKey()}",
        "/api/v1/departments/{$b['departmentB']->getKey()}",
        "/api/v1/locations/{$b['locationB']->getKey()}",
        "/api/v1/wards/{$b['wardB']->getKey()}",
        "/api/v1/rooms/{$b['roomB']->getKey()}",
        "/api/v1/beds/{$b['bedB']->getKey()}",
        "/api/v1/staff/{$b['staffB']->getKey()}",
        "/api/v1/services/{$b['serviceB']->getKey()}",
        "/api/v1/patients/{$b['patientB']->getKey()}",
        "/api/v1/patients/{$b['patientB']->getKey()}/timeline",
        "/api/v1/patients/{$b['patientB']->getKey()}/identifiers",
        "/api/v1/patients/{$b['patientB']->getKey()}/contacts",
        "/api/v1/patients/{$b['patientB']->getKey()}/insurance-policies",
        "/api/v1/patients/{$b['patientB']->getKey()}/consents",
        "/api/v1/patients/{$b['patientB']->getKey()}/documents",
        "/api/v1/appointments/{$b['appointmentB']->getKey()}",
        "/api/v1/encounters/{$b['encounterB']->getKey()}",
        "/api/v1/encounters/{$b['encounterB']->getKey()}/notes",
        "/api/v1/encounters/{$b['encounterB']->getKey()}/charges",
        "/api/v1/invoices/{$b['invoiceB']->getKey()}",
        "/api/v1/invoices/{$b['invoiceB']->getKey()}/payments",
        "/api/v1/facilities/{$b['facilityB']->getKey()}/settings",
    ];

    foreach ($reads as $uri) {
        $this->withToken(Identity::tokenFor($adminA))
            ->getJson($uri)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
});

it('a tenant cannot list or search the other tenant\'s data through scoped surfaces', function () {
    [$orgA, $adminA] = attackerTenantA();
    $b = victimTenantB();

    // Cross-tenant organization selectors are out of scope → 404 on every
    // catalog list, INCLUDING patients (Program Phase 1 contract fix: the
    // URL organization is a resource selector validated against claims,
    // never authorization scope).
    foreach ([
        "/api/v1/organizations/{$b['orgB']->getKey()}/departments",
        "/api/v1/organizations/{$b['orgB']->getKey()}/locations",
        "/api/v1/organizations/{$b['orgB']->getKey()}/wards",
        "/api/v1/organizations/{$b['orgB']->getKey()}/payers",
        "/api/v1/organizations/{$b['orgB']->getKey()}/medications",
        "/api/v1/organizations/{$b['orgB']->getKey()}/patients",
    ] as $uri) {
        $this->withToken(Identity::tokenFor($adminA))->getJson($uri)->assertStatus(404);
    }

    // Patient search: the victim's uniquely named patient must never appear.
    $search = $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/patients/search?q='.urlencode('Victim Unique Patient Name'))
        ->assertOk();

    collect($search->json('data'))->each(function (array $row) use ($b): void {
        expect($row['id'])->not->toBe($b['patientB']->getKey());
    });
});

it('a tenant cannot create CHILD records under the other tenant\'s parents', function () {
    [$orgA, $adminA] = attackerTenantA();
    $b = victimTenantB();

    $creates = [
        'identifiers' => ["/api/v1/patients/{$b['patientB']->getKey()}/identifiers", ['type' => 'national_id', 'value' => '12345']],
        'contacts' => ["/api/v1/patients/{$b['patientB']->getKey()}/contacts", ['type' => 'phone', 'value' => '9800000000']],
        'insurance-policies' => ["/api/v1/patients/{$b['patientB']->getKey()}/insurance-policies", ['payerId' => $b['payerB']->getKey(), 'policyNumber' => 'POL-X']],
        'consents' => ["/api/v1/patients/{$b['patientB']->getKey()}/consents", ['consentType' => 'treatment']],
        'documents' => ["/api/v1/patients/{$b['patientB']->getKey()}/documents", ['documentType' => 'other']],
        'appointment-checkin' => ["/api/v1/appointments/{$b['appointmentB']->getKey()}/check-in", []],
        'appointment-cancel' => ["/api/v1/appointments/{$b['appointmentB']->getKey()}/cancel", ['reason' => 'attack']],
        'start-encounter' => ["/api/v1/appointments/{$b['appointmentB']->getKey()}/start-encounter", []],
        'encounter-notes' => ["/api/v1/encounters/{$b['encounterB']->getKey()}/notes", ['noteType' => 'consultation', 'content' => ['cc' => 'attack']]],
        'encounter-diagnoses' => ["/api/v1/encounters/{$b['encounterB']->getKey()}/diagnoses", ['description' => 'attack']],
        'encounter-prescriptions' => ["/api/v1/encounters/{$b['encounterB']->getKey()}/prescriptions", ['lines' => [['medicationId' => $b['medicationB']->getKey(), 'dose' => '1', 'route' => 'oral', 'frequency' => 'OD']]]],
        'encounter-invoice' => ["/api/v1/encounters/{$b['encounterB']->getKey()}/invoice", []],
        'invoice-pay' => ["/api/v1/invoices/{$b['invoiceB']->getKey()}/pay", ['method' => 'cash', 'amountMinor' => 100]],
        'consent-revoke' => ["/api/v1/consents/{$b['consentB']->getKey()}/revoke", ['reason' => 'attack']],
    ];

    // Safe-denial set: 403 (write-scope gate before lookup), 404 (parent
    // invisible under the app role / AccessCheck read semantics), or 422
    // (FormRequest validation runs before the scope check on some routes).
    // None of these exposes victim data or mutates it.
    foreach ($creates as $label => [$uri, $payload]) {
        $response = $this->withToken(Identity::tokenFor($adminA))->postJson($uri, $payload);
        expect(in_array($response->status(), [403, 404, 422], true))
            ->toBeTrue("$label child-create under a victim parent fails safely (got {$response->status()})");
    }

    // No child row was created under tenant B: the victim's graph is intact
    // and untouched.
    expect(PatientIdentifier::query()->where('patient_id', $b['patientB']->getKey())->count())->toBe(1)
        ->and(ClinicalNote::query()->where('encounter_id', $b['encounterB']->getKey())->count())->toBe(1)
        ->and(PatientContact::query()->where('patient_id', $b['patientB']->getKey())->count())->toBe(1)
        ->and($b['appointmentB']->refresh()->status)->toBe('booked')
        ->and($b['invoiceB']->refresh()->status)->toBe('issued')
        ->and($b['patientB']->refresh()->full_name)->toBe('Victim Unique Patient Name');
});

it('a tenant cannot UPDATE or DELETE the other tenant\'s resources', function () {
    [$orgA, $adminA] = attackerTenantA();
    $b = victimTenantB();

    $updates = [
        ["/api/v1/patients/{$b['patientB']->getKey()}", ['fullName' => 'Pwned']],
        ["/api/v1/branches/{$b['branchB']->getKey()}", ['name' => 'Pwned']],
        ["/api/v1/departments/{$b['departmentB']->getKey()}", ['name' => 'Pwned']],
        ["/api/v1/wards/{$b['wardB']->getKey()}", ['name' => 'Pwned']],
        ["/api/v1/rooms/{$b['roomB']->getKey()}", ['name' => 'Pwned']],
        ["/api/v1/beds/{$b['bedB']->getKey()}", ['bedCode' => 'PWNED']],
        ["/api/v1/staff/{$b['staffB']->getKey()}", ['fullName' => 'Pwned']],
        ["/api/v1/services/{$b['serviceB']->getKey()}", ['name' => 'Pwned']],
        ["/api/v1/patients/{$b['patientB']->getKey()}/contacts/{$b['contactB']->getKey()}", ['value' => 'pwned']],
    ];

    // 422 = validation-before-scope on some routes (still a safe denial).
    foreach ($updates as [$uri, $payload]) {
        $response = $this->withToken(Identity::tokenFor($adminA))->patchJson($uri, $payload);
        expect(in_array($response->status(), [403, 404, 422], true))
            ->toBeTrue("cross-tenant UPDATE fails safely (got {$response->status()})");
    }

    // Beds are intentionally excluded: the contract exposes no DELETE route
    // for beds (GET/PATCH only) — there is no deletion surface to attack.
    $deletes = [
        "/api/v1/branches/{$b['branchB']->getKey()}",
        "/api/v1/departments/{$b['departmentB']->getKey()}",
        "/api/v1/locations/{$b['locationB']->getKey()}",
        "/api/v1/wards/{$b['wardB']->getKey()}",
        "/api/v1/rooms/{$b['roomB']->getKey()}",
        "/api/v1/services/{$b['serviceB']->getKey()}",
    ];

    foreach ($deletes as $uri) {
        $response = $this->withToken(Identity::tokenFor($adminA))->deleteJson($uri);
        expect(in_array($response->status(), [403, 404], true))
            ->toBeTrue("cross-tenant DELETE fails safely (got {$response->status()})");
    }

    // The victim's rows survived every attack.
    expect(Patient::query()->find($b['patientB']->getKey()))->not->toBeNull()
        ->and(Staff::query()->find($b['staffB']->getKey()))->not->toBeNull()
        ->and(Invoice::query()->find($b['invoiceB']->getKey()))->not->toBeNull()
        ->and(Branch::query()->find($b['branchB']->getKey()))->not->toBeNull()
        ->and($b['patientB']->refresh()->full_name)->toBe('Victim Unique Patient Name');
});

it('a tenant cannot book an appointment for the other tenant\'s patient, and forged references are inert', function () {
    [$orgA, $adminA, $facilityA] = attackerTenantA();
    $b = victimTenantB();

    $departmentA = Department::factory()->create(['tenant_id' => $orgA->getKey(), 'facility_id' => $facilityA->getKey()]);
    $staffA = Staff::factory()->create([
        'tenant_id' => $orgA->getKey(),
        'facility_id' => $facilityA->getKey(),
        'department_id' => $departmentA->getKey(),
    ]);

    // Booking with the victim's patient → the patient is out of scope → 404.
    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/appointments', [
            'patientId' => $b['patientB']->getKey(),
            'providerStaffId' => $staffA->getKey(),
            'startsAt' => now()->addHour()->toISOString(),
            'endsAt' => now()->addHour()->addMinutes(15)->toISOString(),
        ])
        // 403 under the schema-owner test connection (AccessCheck::scoped
        // write → SCOPE_DENIED); 404 under the least-privilege app role
        // (invisible patient → 'Patient not found.'). Both are safe denials.
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    // A forged tenant_id/facility_id in a body is rejected as an unknown
    // field (422) and the record lands in the CALLER's tenant.
    $facilityA2 = Identity::facility($orgA);
    $this->withToken(Identity::tokenFor($adminA))
        ->postJson('/api/v1/organizations/'.$orgA->getKey().'/departments', [
            'name' => 'Legit Dept',
            'code' => 'legit-dept-'.substr((string) Str::uuid(), 0, 6),
            'tenant_id' => $b['orgB']->getKey(),
            'facility_id' => $b['facilityB']->getKey(),
        ])
        ->assertStatus(422);

    expect(Department::query()->where('tenant_id', $b['orgB']->getKey())->where('code', 'like', 'legit-dept-%')->exists())->toBeFalse()
        ->and(Department::query()->where('tenant_id', $orgA->getKey())->where('code', 'like', 'legit-dept-%')->exists())->toBeFalse();
});

it('nested patient-contact UPDATE enforces tenant scope and parent linkage', function () {
    [$orgA, $adminA, $facilityA] = attackerTenantA();
    $b = victimTenantB();

    // Same-tenant legitimate update still works (regression: this endpoint
    // previously 500'd for EVERY request because the controller signature
    // omitted the URL {patient} parameter).
    $patientA = Patient::factory()->create(['tenant_id' => $orgA->getKey(), 'facility_id' => $facilityA->getKey()]);
    $contactA = PatientContact::factory()->create(['tenant_id' => $orgA->getKey(), 'patient_id' => $patientA->getKey(), 'value' => 'before']);

    $this->withToken(Identity::tokenFor($adminA))
        ->patchJson("/api/v1/patients/{$patientA->getKey()}/contacts/{$contactA->getKey()}", ['value' => 'after'])
        ->assertOk()
        ->assertJsonPath('data.value', 'after');
    expect(PatientContact::query()->find($contactA->getKey())->value)->toBe('after');

    // Cross-tenant: the victim's contact addressed under the victim's own
    // patient URL → 403 SCOPE_DENIED, and the victim's row is untouched.
    $response = $this->withToken(Identity::tokenFor($adminA))
        ->patchJson("/api/v1/patients/{$b['patientB']->getKey()}/contacts/{$b['contactB']->getKey()}", ['value' => 'pwned'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');

    expect(PatientContact::query()->find($b['contactB']->getKey())->value)->not->toBe('pwned');

    // Parent mismatch: the caller's own contact addressed under the victim's
    // patient URL → the contact is not a child of the URL patient → 404
    // (existence is never leaked), and it is not mutated.
    $this->withToken(Identity::tokenFor($adminA))
        ->patchJson("/api/v1/patients/{$b['patientB']->getKey()}/contacts/{$contactA->getKey()}", ['value' => 'pwned2'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND');
    expect(PatientContact::query()->find($contactA->getKey())->value)->toBe('after');
});

it('organizations/{org}/patients validates the org selector and never leaks across tenants', function () {
    [$orgA, $adminA, $facilityA] = attackerTenantA();
    $b = victimTenantB();

    $patientA = Patient::factory()->create([
        'tenant_id' => $orgA->getKey(),
        'facility_id' => $facilityA->getKey(),
        'full_name' => 'Same Tenant Patient',
    ]);

    // 1. Valid same-organization request → 200 with the caller's own patient
    //    (and never the victim's), exact projection, ordered newest-first.
    $list = $this->withToken(Identity::tokenFor($adminA))
        ->getJson("/api/v1/organizations/{$orgA->getKey()}/patients")
        ->assertOk();

    $ids = collect($list->json('data'))->pluck('id')->all();
    expect($ids)->toContain($patientA->getKey())
        ->and($ids)->not->toContain($b['patientB']->getKey());

    foreach ($list->json('data') as $row) {
        expect(array_keys($row))->toBe([
            'id', 'mrn', 'facilityId', 'fullName', 'dateOfBirth', 'sex',
            'bloodGroup', 'status', 'createdAt', 'updatedAt',
        ]);
        expect($row)->not->toHaveKeys(['tenant_id', 'tenantId', 'consent_summary', 'lock_version']);
    }

    // 2. Nonexistent (forged) organization → 404 "Resource not found.":
    //    implicit route binding fails before the controller runs, exactly
    //    like every sibling catalog read (verified against payers,
    //    medications, departments). Existence is never leaked.
    $this->withToken(Identity::tokenFor($adminA))
        ->getJson('/api/v1/organizations/'.Str::uuid().'/patients')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonPath('error.message', 'Resource not found.');

    // 3. Existing but out-of-scope organization → 404 "Resource not found."
    //    (existence is never leaked) — same contract as every sibling read.
    $this->withToken(Identity::tokenFor($adminA))
        ->getJson("/api/v1/organizations/{$b['orgB']->getKey()}/patients")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonPath('error.message', 'Resource not found.');

    // 4. The URL organization can never switch the caller's tenant context:
    //    even a direct claim of the victim's org returns 404, and the
    //    victim's patient row is untouched at the database layer.
    expect(Patient::query()->find($b['patientB']->getKey()))->not->toBeNull()
        ->and(Patient::query()->where('tenant_id', $b['orgB']->getKey())->count())->toBe(1);
});

it('a cross-tenant facility/branch proposal is refused', function () {
    [$orgA, $adminA] = attackerTenantA();
    $b = victimTenantB();

    $this->withToken(Identity::tokenFor($adminA))
        ->withHeader('X-Swasthya-Facility', $b['facilityB']->getKey())
        ->getJson('/api/v1/users')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FACILITY_DENIED');
});
