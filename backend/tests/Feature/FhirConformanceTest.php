<?php

use App\Services\FhirProjection;
use Illuminate\Support\Facades\File;

/**
 * FHIR R4 conformance test (INTEROPERABILITY.md §5, MASTER_RULES.md §32.5).
 *
 * Every documented FHIR resource projection is tested against its conformance
 * fixture. Mapping drift fails CI — the fixture IS the contract.
 *
 * The projection layer is a pure function: internal rows → FHIR R4 shapes.
 * No database, no HTTP, no external dependencies.
 */
it('projects Patient matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/patient.json')), true);

    $patient = [
        'id' => 'p1',
        'full_name' => 'John Doe',
        'mrn' => 'MRN-1001',
        'date_of_birth' => '1990-01-01',
        'sex' => 'male',
    ];

    $result = FhirProjection::patient($patient);

    expect($result['resourceType'])->toBe('Patient');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['identifier'][0]['value'])->toBe($fixture['identifier'][0]['value']);
    expect($result['name'][0]['family'])->toBe($fixture['name'][0]['family']);
    expect($result['gender'])->toBe($fixture['gender']);
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('projects Encounter matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/encounter.json')), true);

    $encounter = [
        'id' => 'enc-1',
        'status' => 'completed',
        'type' => 'AMB',
        'patient_id' => 'p1',
        'started_at' => '2026-01-15T10:00:00+00:00',
        'ended_at' => '2026-01-15T11:30:00+00:00',
    ];

    $result = FhirProjection::encounter($encounter);

    expect($result['resourceType'])->toBe('Encounter');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['status'])->toBe('finished');
    expect($result['subject']['reference'])->toBe('Patient/p1');
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('projects Observation matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/observation.json')), true);

    $item = [
        'id' => 'item-1',
        'test_name' => 'Hemoglobin',
        'result_value' => '12.5',
        'result_unit' => 'g/dL',
        'reference_range' => '12.0-16.0',
        'verified_at' => '2026-01-15T12:00:00+00:00',
    ];
    $order = [
        'patient_id' => 'p1',
        'reported_at' => '2026-01-15T12:00:00+00:00',
    ];

    $result = FhirProjection::observation($item, $order);

    expect($result['resourceType'])->toBe('Observation');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['code']['text'])->toBe('Hemoglobin');
    expect($result['value']['value'])->toBe(12.5);
    expect($result['value']['unit'])->toBe('g/dL');
    expect($result['referenceRange'][0]['text'])->toBe('12.0-16.0');
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('projects MedicationRequest matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/medication_request.json')), true);

    $prescription = [
        'id' => 'rx-1',
        'patient_id' => 'p1',
        'status' => 'active',
        'created_at' => '2026-01-15T10:00:00+00:00',
    ];
    $lines = [
        [
            'medication_name' => 'Amoxicillin 500mg',
            'dose' => '500mg',
            'route' => 'oral',
            'frequency' => 'tid',
            'duration' => '7 days',
        ],
    ];

    $result = FhirProjection::medicationRequest($prescription, $lines);

    expect($result['resourceType'])->toBe('MedicationRequest');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['status'])->toBe('active');
    expect($result['medicationCodeableConcept']['text'])->toBe('Amoxicillin 500mg');
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('projects DiagnosticReport matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/diagnostic_report.json')), true);

    $order = [
        'id' => 'ord-1',
        'patient_id' => 'p1',
        'order_code' => 'CBC',
        'reported_at' => '2026-01-15T12:00:00+00:00',
    ];
    $items = [
        ['id' => 'obs1'],
        ['id' => 'obs2'],
    ];

    $result = FhirProjection::diagnosticReport($order, $items);

    expect($result['resourceType'])->toBe('DiagnosticReport');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['code']['text'])->toBe('CBC');
    expect($result['result'])->toHaveCount(2);
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('projects Practitioner matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/practitioner.json')), true);

    $staff = [
        'id' => 'pr1',
        'full_name' => 'Anita Sharma',
    ];

    $result = FhirProjection::practitioner($staff);

    expect($result['resourceType'])->toBe('Practitioner');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['name'][0]['family'])->toBe('Sharma');
    expect($result['active'])->toBeTrue();
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('projects Organization matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/organization.json')), true);

    $org = [
        'id' => 'org1',
        'name' => 'Smoke Hospital Group',
        'code' => 'ORG-001',
    ];

    $result = FhirProjection::organization($org);

    expect($result['resourceType'])->toBe('Organization');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['name'])->toBe('Smoke Hospital Group');
    expect($result['identifier'][0]['value'])->toBe('ORG-001');
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('projects AllergyIntolerance matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/allergy_intolerance.json')), true);

    $allergy = [
        'id' => 'allergy1',
        'patient_id' => 'p1',
        'allergen_name' => 'Penicillin',
        'status' => 'active',
        'created_at' => '2026-01-15T10:00:00+00:00',
    ];

    $result = FhirProjection::allergyIntolerance($allergy);

    expect($result['resourceType'])->toBe('AllergyIntolerance');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['code']['text'])->toBe('Penicillin');
    expect($result['patient']['reference'])->toBe('Patient/p1');
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('projects Condition matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/condition.json')), true);

    $diagnosis = [
        'id' => 'cond1',
        'patient_id' => 'p1',
        'diagnosis_code' => 'J18.9',
        'description' => 'Pneumonia',
        'coding_system' => 'http://hl7.org/fhir/sid/icd-10',
    ];

    $result = FhirProjection::condition($diagnosis);

    expect($result['resourceType'])->toBe('Condition');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['code']['text'])->toBe('Pneumonia');
    expect($result['code']['coding'][0]['code'])->toBe('J18.9');
    expect($result['subject']['reference'])->toBe('Patient/p1');
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('projects ServiceRequest matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/service_request.json')), true);

    $order = [
        'id' => 'sr1',
        'patient_id' => 'p1',
        'status' => 'ordered',
        'order_code' => 'Complete Blood Count',
        'created_at' => '2026-01-15T10:00:00+00:00',
    ];

    $result = FhirProjection::serviceRequest($order);

    expect($result['resourceType'])->toBe('ServiceRequest');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['status'])->toBe('active');
    expect($result['code']['text'])->toBe('Complete Blood Count');
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('projects ImagingStudy matching the conformance fixture', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/fhir/imaging_study.json')), true);

    $study = [
        'id' => 'img1',
        'patient_id' => 'p1',
        'modality_code' => 'CR',
        'performed_at' => '2026-01-15T10:00:00+00:00',
    ];
    $refs = [
        [
            'reference_type' => 'dicom_study_instance_uid',
            'reference_value' => '1.2.3.4.5.6.7.8.9',
        ],
    ];

    $result = FhirProjection::imagingStudy($study, $refs);

    expect($result['resourceType'])->toBe('ImagingStudy');
    expect($result['id'])->toBe($fixture['id']);
    expect($result['status'])->toBe('available');
    expect($result['numberOfSeries'])->toBe(1);
    expect($result['meta']['profile'])->toBe($fixture['meta']['profile']);
});

it('all 10 FHIR resource types are defined in the projection class', function () {
    $resources = [
        FhirProjection::RESOURCE_PATIENT,
        FhirProjection::RESOURCE_PRACTITIONER,
        FhirProjection::RESOURCE_ORGANIZATION,
        FhirProjection::RESOURCE_LOCATION,
        FhirProjection::RESOURCE_ENCOUNTER,
        FhirProjection::RESOURCE_ALLERGY_INTOLERANCE,
        FhirProjection::RESOURCE_CONDITION,
        FhirProjection::RESOURCE_OBSERVATION,
        FhirProjection::RESOURCE_MEDICATION_REQUEST,
        FhirProjection::RESOURCE_SERVICE_REQUEST,
        FhirProjection::RESOURCE_DIAGNOSTIC_REPORT,
        FhirProjection::RESOURCE_PROCEDURE,
        FhirProjection::RESOURCE_DOCUMENT_REFERENCE,
        FhirProjection::RESOURCE_IMAGING_STUDY,
    ];

    expect($resources)->toHaveCount(14);

    foreach ($resources as $resource) {
        expect($resource)->not->toBeEmpty();
    }
});

it('every FHIR fixture contains resourceType and meta.profile', function () {
    $fixtures = File::glob(base_path('tests/Fixtures/fhir/*.json'));

    expect(count($fixtures))->toBeGreaterThanOrEqual(10);

    foreach ($fixtures as $path) {
        $data = json_decode(File::get($path), true);
        $name = basename($path);

        expect(isset($data['resourceType']))->toBeTrue("{$name} missing resourceType");
        expect(isset($data['meta']))->toBeTrue("{$name} missing meta");
        expect(isset($data['meta']['profile']))->toBeTrue("{$name} missing meta.profile");
        expect($data['meta']['profile'])->not->toBeEmpty("{$name} has empty profile");
    }
});
