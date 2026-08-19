<?php

namespace App\Services;

/**
 * FHIR R4 projection layer (INTEROPERABILITY.md §5, ROADMAP Phase 18).
 *
 * The INTERNAL model is the truth; standards are PROJECTIONS — this class
 * maps internal rows (passed as plain arrays — pure, fixture-tested, no
 * database) to FHIR R4 resource shapes. The internal schema is never
 * reshaped to fit the standard. Only the documented resource projections
 * exist: Patient, Encounter, Observation, MedicationRequest,
 * DiagnosticReport. Fixtures in backend/tests/Fixtures/fhir/ contract-test
 * every shape (mapping drift fails CI, MASTER_RULES.md §32.5).
 *
 * No PHI beyond the patient's own permitted projection: identifiers carry
 * the MRN; names/DOB/gender are the patient identity fields FHIR requires.
 */
final class FhirProjection
{
    public const RESOURCE_PATIENT = 'Patient';

    public const RESOURCE_ENCOUNTER = 'Encounter';

    public const RESOURCE_OBSERVATION = 'Observation';

    public const RESOURCE_MEDICATION_REQUEST = 'MedicationRequest';

    public const RESOURCE_DIAGNOSTIC_REPORT = 'DiagnosticReport';

    public const RESOURCE_PRACTITIONER = 'Practitioner';

    public const RESOURCE_ORGANIZATION = 'Organization';

    public const RESOURCE_LOCATION = 'Location';

    public const RESOURCE_ALLERGY_INTOLERANCE = 'AllergyIntolerance';

    public const RESOURCE_CONDITION = 'Condition';

    public const RESOURCE_SERVICE_REQUEST = 'ServiceRequest';

    public const RESOURCE_PROCEDURE = 'Procedure';

    public const RESOURCE_DOCUMENT_REFERENCE = 'DocumentReference';

    public const RESOURCE_IMAGING_STUDY = 'ImagingStudy';

    /**
     * @param  array<string, mixed>  $patient  internal patient row (whitelisted)
     * @return array<string, mixed>
     */
    public static function patient(array $patient): array
    {
        $name = self::splitName((string) ($patient['full_name'] ?? ''));

        return [
            'resourceType' => self::RESOURCE_PATIENT,
            'id' => (string) $patient['id'],
            'identifier' => [
                ['system' => 'urn:oid:1.3.6.1.4.1.99999.1.1', 'value' => (string) ($patient['mrn'] ?? '')],
            ],
            'name' => [[
                'family' => $name['family'],
                'given' => [$name['given']],
            ]],
            'birthDate' => (string) ($patient['date_of_birth'] ?? ''),
            'gender' => self::gender((string) ($patient['sex'] ?? '')),
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/Patient'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $encounter  internal encounter row (whitelisted)
     * @return array<string, mixed>
     */
    public static function encounter(array $encounter): array
    {
        return [
            'resourceType' => self::RESOURCE_ENCOUNTER,
            'id' => (string) $encounter['id'],
            'status' => self::encounterStatus((string) ($encounter['status'] ?? '')),
            'class' => ['code' => (string) ($encounter['type'] ?? '')],
            'subject' => ['reference' => 'Patient/'.(string) $encounter['patient_id']],
            'period' => [
                'start' => self::instant($encounter['started_at'] ?? null),
                'end' => self::instant($encounter['ended_at'] ?? null),
            ],
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/Encounter'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item  internal lab order item row (whitelisted)
     * @param  array<string, mixed>  $order  internal lab order row (whitelisted)
     * @return array<string, mixed>
     */
    public static function observation(array $item, array $order): array
    {
        $code = [
            'text' => (string) ($item['test_name'] ?? ''),
        ];
        $value = self::observationValue($item);

        return [
            'resourceType' => self::RESOURCE_OBSERVATION,
            'id' => (string) $item['id'],
            'status' => 'final',
            'code' => $code,
            'subject' => ['reference' => 'Patient/'.(string) $order['patient_id']],
            'effectiveDateTime' => self::instant($order['reported_at'] ?? null),
            'issued' => self::instant($item['verified_at'] ?? null),
            ...($value !== null ? ['value' => $value] : []),
            ...($item['reference_range'] !== null && $item['reference_range'] !== ''
                ? ['referenceRange' => [['text' => (string) $item['reference_range']]]]
                : []),
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/Observation'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $prescription  internal prescription row (whitelisted)
     * @param  array<int, array<string, mixed>>  $lines  internal prescription lines (whitelisted)
     * @return array<string, mixed>
     */
    public static function medicationRequest(array $prescription, array $lines): array
    {
        return [
            'resourceType' => self::RESOURCE_MEDICATION_REQUEST,
            'id' => (string) $prescription['id'],
            'status' => (string) ($prescription['status'] ?? 'active'),
            'intent' => 'order',
            'subject' => ['reference' => 'Patient/'.(string) $prescription['patient_id']],
            'authoredOn' => self::instant($prescription['created_at'] ?? null),
            'medicationCodeableConcept' => [
                'text' => $lines[0]['medication_name'] ?? '',
            ],
            'dosageInstruction' => array_values(array_filter(array_map(
                static fn (array $line): array => [
                    'text' => implode(' ', array_filter([
                        (string) ($line['dose'] ?? ''),
                        (string) ($line['route'] ?? ''),
                        (string) ($line['frequency'] ?? ''),
                        (string) ($line['duration'] ?? ''),
                    ])),
                ],
                $lines,
            ))),
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/MedicationRequest'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $order  internal reported lab order row (whitelisted)
     * @param  array<int, array<string, mixed>>  $items  internal order items (whitelisted)
     * @return array<string, mixed>
     */
    public static function diagnosticReport(array $order, array $items): array
    {
        return [
            'resourceType' => self::RESOURCE_DIAGNOSTIC_REPORT,
            'id' => (string) $order['id'],
            'status' => 'final',
            'code' => [
                'text' => (string) ($order['order_code'] ?? 'Laboratory report'),
            ],
            'subject' => ['reference' => 'Patient/'.(string) $order['patient_id']],
            'issued' => self::instant($order['reported_at'] ?? null),
            'result' => array_map(
                static fn (array $item): array => ['reference' => 'Observation/'.(string) $item['id']],
                $items,
            ),
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/DiagnosticReport'],
            ],
        ];
    }

    /**
     * @return array{family: string, given: string}
     */
    /**
     * @param  array<string, mixed>  $staff  internal staff row
     * @return array<string, mixed>
     */
    public static function practitioner(array $staff): array
    {
        $name = self::splitName((string) ($staff['full_name'] ?? ''));

        return [
            'resourceType' => self::RESOURCE_PRACTITIONER,
            'id' => (string) $staff['id'],
            'identifier' => [[
                'system' => 'urn:oid:1.3.6.1.4.1.99999.1.2',
                'value' => (string) ($staff['id'] ?? ''),
            ]],
            'name' => [[
                'family' => $name['family'],
                'given' => [$name['given']],
            ]],
            'active' => true,
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/Practitioner'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $org  internal organization row
     * @return array<string, mixed>
     */
    public static function organization(array $org): array
    {
        return [
            'resourceType' => self::RESOURCE_ORGANIZATION,
            'id' => (string) $org['id'],
            'identifier' => [[
                'system' => 'urn:oid:1.3.6.1.4.1.99999.1.3',
                'value' => (string) ($org['code'] ?? ''),
            ]],
            'name' => (string) ($org['name'] ?? ''),
            'active' => true,
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/Organization'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $facility  internal facility row
     * @return array<string, mixed>
     */
    public static function location(array $facility): array
    {
        return [
            'resourceType' => self::RESOURCE_LOCATION,
            'id' => (string) $facility['id'],
            'identifier' => [[
                'system' => 'urn:oid:1.3.6.1.4.1.99999.1.4',
                'value' => (string) ($facility['code'] ?? ''),
            ]],
            'name' => (string) ($facility['name'] ?? ''),
            'status' => 'active',
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/Location'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $allergy  internal patient_allergies row
     * @return array<string, mixed>
     */
    public static function allergyIntolerance(array $allergy): array
    {
        return [
            'resourceType' => self::RESOURCE_ALLERGY_INTOLERANCE,
            'id' => (string) $allergy['id'],
            'clinicalStatus' => ['coding' => [[
                'system' => 'http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical',
                'code' => (string) ($allergy['status'] ?? 'active'),
            ]]],
            'code' => [
                'text' => (string) ($allergy['allergen_name'] ?? ''),
            ],
            'patient' => ['reference' => 'Patient/'.(string) $allergy['patient_id']],
            'recordedDate' => self::instant($allergy['created_at'] ?? null),
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/AllergyIntolerance'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnosis  internal diagnoses row
     * @return array<string, mixed>
     */
    public static function condition(array $diagnosis): array
    {
        return [
            'resourceType' => self::RESOURCE_CONDITION,
            'id' => (string) $diagnosis['id'],
            'clinicalStatus' => ['coding' => [[
                'system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                'code' => 'active',
            ]]],
            'code' => [
                'coding' => [[
                    'system' => (string) ($diagnosis['coding_system'] ?? 'http://hl7.org/fhir/sid/icd-10'),
                    'code' => (string) ($diagnosis['diagnosis_code'] ?? ''),
                ]],
                'text' => (string) ($diagnosis['description'] ?? ''),
            ],
            'subject' => ['reference' => 'Patient/'.(string) $diagnosis['patient_id']],
            'encounter' => isset($diagnosis['encounter_id'])
                ? ['reference' => 'Encounter/'.(string) $diagnosis['encounter_id']]
                : null,
            'onsetDateTime' => self::instant($diagnosis['diagnosed_at'] ?? null),
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/Condition'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $order  internal lab/radiology order row
     * @return array<string, mixed>
     */
    public static function serviceRequest(array $order): array
    {
        return [
            'resourceType' => self::RESOURCE_SERVICE_REQUEST,
            'id' => (string) $order['id'],
            'status' => self::serviceRequestStatus((string) ($order['status'] ?? '')),
            'intent' => 'order',
            'code' => [
                'text' => (string) ($order['order_code'] ?? ''),
            ],
            'subject' => ['reference' => 'Patient/'.(string) $order['patient_id']],
            'authoredOn' => self::instant($order['created_at'] ?? null),
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/ServiceRequest'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $event  internal surgical/event row
     * @return array<string, mixed>
     */
    public static function procedure(array $event): array
    {
        return [
            'resourceType' => self::RESOURCE_PROCEDURE,
            'id' => (string) $event['id'],
            'status' => 'completed',
            'code' => [
                'text' => (string) ($event['procedure_name'] ?? $event['type'] ?? ''),
            ],
            'subject' => ['reference' => 'Patient/'.(string) $event['patient_id']],
            'performedDateTime' => self::instant($event['performed_at'] ?? $event['created_at'] ?? null),
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/Procedure'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $doc  internal document/timeline row
     * @return array<string, mixed>
     */
    public static function documentReference(array $doc): array
    {
        return [
            'resourceType' => self::RESOURCE_DOCUMENT_REFERENCE,
            'id' => (string) $doc['id'],
            'status' => 'current',
            'docStatus' => 'final',
            'type' => [
                'text' => (string) ($doc['entry_type'] ?? 'clinical document'),
            ],
            'subject' => ['reference' => 'Patient/'.(string) $doc['patient_id']],
            'date' => self::instant($doc['created_at'] ?? null),
            'content' => [[
                'attachment' => [
                    'contentType' => 'text/plain',
                    'data' => base64_encode((string) ($doc['content'] ?? '')),
                ],
            ]],
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/DocumentReference'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $study  internal radiology study row
     * @param  array<int, array<string, mixed>>  $refs  internal image_references
     * @return array<string, mixed>
     */
    public static function imagingStudy(array $study, array $refs = []): array
    {
        return [
            'resourceType' => self::RESOURCE_IMAGING_STUDY,
            'id' => (string) $study['id'],
            'status' => 'available',
            'modality' => [[
                'system' => 'http://dicom.nema.org/resources/ontology/DCM',
                'code' => (string) ($study['modality_code'] ?? ''),
            ]],
            'subject' => ['reference' => 'Patient/'.(string) $study['patient_id']],
            'started' => self::instant($study['performed_at'] ?? null),
            'numberOfSeries' => count($refs),
            'series' => array_map(
                static fn (array $ref): array => [
                    'uid' => (string) ($ref['reference_value'] ?? ''),
                    'modality' => [
                        'system' => 'http://dicom.nema.org/resources/ontology/DCM',
                        'code' => (string) ($ref['reference_type'] ?? ''),
                    ],
                ],
                $refs,
            ),
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/ImagingStudy'],
            ],
        ];
    }

    private static function serviceRequestStatus(string $status): string
    {
        return match ($status) {
            'ordered' => 'active',
            'collected', 'processing' => 'active',
            'results_entered', 'verified', 'reported' => 'completed',
            'cancelled' => 'revoked',
            default => 'unknown',
        };
    }

    private static function splitName(string $fullName): array
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($fullName)) ?: []));

        if ($parts === []) {
            return ['family' => '', 'given' => ''];
        }

        $family = array_pop($parts);

        return ['family' => $family, 'given' => implode(' ', $parts)];
    }

    private static function gender(string $sex): string
    {
        return match (strtolower($sex)) {
            'male', 'm' => 'male',
            'female', 'f' => 'female',
            default => 'unknown',
        };
    }

    private static function encounterStatus(string $status): string
    {
        return match ($status) {
            'completed', 'closed' => 'finished',
            'in_progress' => 'in-progress',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function observationValue(array $item): ?array
    {
        $raw = $item['result_value'] ?? null;
        $unit = $item['result_unit'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw) && $unit !== null && $unit !== '') {
            return ['value' => (float) $raw, 'unit' => (string) $unit];
        }

        return ['valueString' => (string) $raw];
    }

    private static function instant(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
