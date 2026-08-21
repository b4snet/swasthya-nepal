<?php

namespace App\Services;

use App\Models\CsvImport;
use App\Models\Patient;
use App\Models\PatientContact;
use App\Models\PatientIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Patient CSV import pipeline (Phase 80): template generation, CSV parsing,
 * column mapping, validation, duplicate detection, dry-run preview, and
 * atomic import with failure reporting.
 *
 * Every import is tracked as a CsvImport record with full audit trail.
 * The pipeline never overwrites existing patient identifiers.
 */
final class PatientCsvImportService
{
    /**
     * Template columns: header => validation rules description.
     *
     * @return array<string, string>
     */
    public static function templateColumns(): array
    {
        return [
            'full_name' => 'Required. Patient full name (min 2 chars).',
            'date_of_birth' => 'Required. Date of birth (YYYY-MM-DD).',
            'sex' => 'Required. One of: male, female, other, unknown.',
            'blood_group' => 'Optional. One of: A+, A-, B+, B-, AB+, AB-, O+, O-.',
            'phone' => 'Optional. Primary phone number.',
            'email' => 'Optional. Primary email address.',
            'national_id' => 'Optional. National ID / citizenship number.',
            'passport' => 'Optional. Passport number.',
            'address_line1' => 'Optional. Street address.',
            'city' => 'Optional. City.',
            'state' => 'Optional. State / Province.',
            'emergency_contact_name' => 'Optional. Emergency contact name.',
            'emergency_contact_phone' => 'Optional. Emergency contact phone.',
            'emergency_contact_relation' => 'Optional. Relationship (e.g. spouse, parent).',
        ];
    }

    /**
     * Generate a CSV template file for download.
     *
     * @return string The file path of the generated template
     */
    public static function generateTemplate(): string
    {
        $columns = self::templateColumns();
        $headers = array_keys($columns);

        $content = implode(',', $headers)."\n";
        $content .= '"Ram Bahadur Thapa","1985-03-15","male","O+","9841234567","ram@example.com","12-34-56789","","Kathmandu-11","Bagmati","Sita Thapa","9841234568","spouse"'."\n";
        $content .= '"Sita Devi Shrestha","1990-07-22","female","B+","9851234567","sita@example.com","","NP123456","Lalitpur-3","Bagmati","Ram Shrestha","9851234569","spouse"'."\n";

        $path = 'imports/patient-template-'.time().'.csv';
        Storage::disk('local')->put($path, $content);

        return $path;
    }

    /**
     * Parse a CSV file into rows.
     *
     * @return array{headers: list<string>, rows: list<array<string, string>>, totalRows: int}
     */
    public static function parseCsv(string $filePath): array
    {
        $fullPath = Storage::disk('local')->path($filePath);
        $handle = fopen($fullPath, 'r');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open CSV file.');
        }

        // Read headers
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new \RuntimeException('CSV file is empty or has no headers.');
        }

        $headers = array_map('trim', $headers);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($headers)) {
                $rows[] = array_combine($headers, $row);
            }
        }

        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows, 'totalRows' => count($rows)];
    }

    /**
     * Map CSV columns to patient fields.
     *
     * @param  array<string, string>  $fieldMapping  CSV column => patient field
     * @param  array<string, string>  $row
     * @return array{fullName: string|null, dateOfBirth: string|null, sex: string|null, bloodGroup: string|null, phone: string|null, email: string|null, nationalId: string|null, passport: string|null, address: array|null, emergencyContact: array|null}
     */
    public static function mapRow(array $fieldMapping, array $row): array
    {
        $mapped = [];
        foreach ($fieldMapping as $csvColumn => $patientField) {
            if (isset($row[$csvColumn]) && $row[$csvColumn] !== '') {
                $mapped[$patientField] = trim($row[$csvColumn]);
            }
        }

        $address = null;
        if (isset($mapped['address_line1']) || isset($mapped['city']) || isset($mapped['state'])) {
            $address = [
                'line1' => $mapped['address_line1'] ?? null,
                'city' => $mapped['city'] ?? null,
                'state' => $mapped['state'] ?? null,
            ];
        }

        $emergencyContact = null;
        if (isset($mapped['emergency_contact_name']) || isset($mapped['emergency_contact_phone'])) {
            $emergencyContact = [
                'name' => $mapped['emergency_contact_name'] ?? null,
                'phone' => $mapped['emergency_contact_phone'] ?? null,
                'relation' => $mapped['emergency_contact_relation'] ?? null,
            ];
        }

        return [
            'fullName' => $mapped['full_name'] ?? null,
            'dateOfBirth' => $mapped['date_of_birth'] ?? null,
            'sex' => $mapped['sex'] ?? null,
            'bloodGroup' => $mapped['blood_group'] ?? null,
            'phone' => $mapped['phone'] ?? null,
            'email' => $mapped['email'] ?? null,
            'nationalId' => $mapped['national_id'] ?? null,
            'passport' => $mapped['passport'] ?? null,
            'address' => $address,
            'emergencyContact' => $emergencyContact,
        ];
    }

    /**
     * Validate a single mapped patient row.
     *
     * @param  array<string, mixed>  $mapped
     * @return array{valid: bool, errors: list<string>}
     */
    public static function validateRow(array $mapped): array
    {
        $errors = [];

        if (empty($mapped['fullName']) || strlen((string) $mapped['fullName']) < 2) {
            $errors[] = 'Full name is required (min 2 chars).';
        }

        if (empty($mapped['dateOfBirth'])) {
            $errors[] = 'Date of birth is required.';
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $mapped['dateOfBirth'])) {
            $errors[] = 'Date of birth must be YYYY-MM-DD format.';
        } elseif (new \DateTime((string) $mapped['dateOfBirth']) > new \DateTime) {
            $errors[] = 'Date of birth cannot be in the future.';
        }

        $validSexes = ['male', 'female', 'other', 'unknown'];
        if (empty($mapped['sex'])) {
            $errors[] = 'Sex is required.';
        } elseif (! in_array(strtolower((string) $mapped['sex']), $validSexes, true)) {
            $errors[] = 'Sex must be one of: male, female, other, unknown.';
        }

        if (! empty($mapped['bloodGroup'])) {
            $validBlood = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
            if (! in_array(strtoupper((string) $mapped['bloodGroup']), $validBlood, true)) {
                $errors[] = 'Invalid blood group.';
            }
        }

        if (! empty($mapped['email']) && ! filter_var($mapped['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }

        return ['valid' => count($errors) === 0, 'errors' => $errors];
    }

    /**
     * Run the full import pipeline for a CsvImport record.
     * This is the main entry point called by the controller.
     *
     * @return array{success: int, errors: int, errorDetails: list<array{row: int, errors: list<string>}>}
     */
    public static function runImport(CsvImport $import, string $tenantId, string $facilityId): array
    {
        $import->update(['status' => CsvImport::STATUS_IMPORTING, 'started_at' => now()]);

        $parsed = self::parseCsv($import->file_path);
        $fieldMapping = $import->field_mapping ?? [];

        $successCount = 0;
        $errorCount = 0;
        $errorDetails = [];
        $duplicateDetector = app(DuplicateDetector::class);
        $mrnIssuer = app(MrnIssuer::class);

        foreach ($parsed['rows'] as $index => $row) {
            $rowNum = $index + 1;

            try {
                $mapped = self::mapRow($fieldMapping, $row);
                $validation = self::validateRow($mapped);

                if (! $validation['valid']) {
                    $errorCount++;
                    $errorDetails[] = ['row' => $rowNum, 'errors' => $validation['errors']];

                    continue;
                }

                // Duplicate detection
                $identifierHashes = [];
                if (! empty($mapped['nationalId'])) {
                    $identifierHashes['national_id'] = PatientIdentifier::hashValue($mapped['nationalId']);
                }
                if (! empty($mapped['passport'])) {
                    $identifierHashes['passport'] = PatientIdentifier::hashValue($mapped['passport']);
                }

                $duplicates = $duplicateDetector->candidates(
                    $tenantId,
                    (string) $mapped['fullName'],
                    $mapped['dateOfBirth'] ? (string) $mapped['dateOfBirth'] : null,
                    $identifierHashes,
                );

                if ($duplicates->isNotEmpty()) {
                    $dupScores = $duplicates->pluck('score')->all();
                    $maxScore = max($dupScores);
                    if ($maxScore >= 0.95) {
                        $errorCount++;
                        $errorDetails[] = [
                            'row' => $rowNum,
                            'errors' => ['Near-exact duplicate found (score: '.round($maxScore, 2).'). Existing patient MRN: '.($duplicates->first()->mrn ?? 'unknown').'. Skipping to prevent duplicate.'],
                        ];

                        continue;
                    }
                }

                // Create patient
                $patient = DB::transaction(function () use ($mapped, $tenantId, $facilityId, $mrnIssuer, $context): Patient {
                    $patient = Patient::query()->create([
                        'tenant_id' => $tenantId,
                        'facility_id' => $facilityId,
                        'mrn' => $mrnIssuer->issue($tenantId),
                        'full_name' => $mapped['fullName'],
                        'date_of_birth' => $mapped['dateOfBirth'],
                        'sex' => strtolower((string) $mapped['sex']),
                        'blood_group' => ! empty($mapped['bloodGroup']) ? strtoupper((string) $mapped['bloodGroup']) : null,
                        'status' => Patient::STATUS_ACTIVE,
                        'consent_summary' => [],
                        'lock_version' => 0,
                        'created_by' => $context->user?->getKey() ?? null,
                    ]);

                    // Contacts
                    if (! empty($mapped['phone'])) {
                        PatientContact::query()->create([
                            'tenant_id' => $tenantId,
                            'patient_id' => $patient->getKey(),
                            'type' => PatientContact::TYPE_PHONE,
                            'value' => $mapped['phone'],
                            'is_primary' => true,
                            'status' => PatientContact::STATUS_ACTIVE,
                            'created_by' => $context->user?->getKey() ?? null,
                        ]);
                    }

                    if (! empty($mapped['email'])) {
                        PatientContact::query()->create([
                            'tenant_id' => $tenantId,
                            'patient_id' => $patient->getKey(),
                            'type' => PatientContact::TYPE_EMAIL,
                            'value' => $mapped['email'],
                            'is_primary' => true,
                            'status' => PatientContact::STATUS_ACTIVE,
                            'created_by' => $context->user?->getKey() ?? null,
                        ]);
                    }

                    // Identifiers — NEVER overwrite existing
                    if (! empty($mapped['nationalId'])) {
                        PatientIdentifier::query()->create([
                            'tenant_id' => $tenantId,
                            'patient_id' => $patient->getKey(),
                            'type' => 'national_id',
                            'value' => $mapped['nationalId'],
                            'value_hash' => PatientIdentifier::hashValue($mapped['nationalId']),
                            'status' => 'active',
                            'created_by' => $context->user?->getKey() ?? null,
                        ]);
                    }

                    if (! empty($mapped['passport'])) {
                        PatientIdentifier::query()->create([
                            'tenant_id' => $tenantId,
                            'patient_id' => $patient->getKey(),
                            'type' => 'passport',
                            'value' => $mapped['passport'],
                            'value_hash' => PatientIdentifier::hashValue($mapped['passport']),
                            'status' => 'active',
                            'created_by' => $context->user?->getKey() ?? null,
                        ]);
                    }

                    return $patient;
                });

                $successCount++;
            } catch (\Throwable $e) {
                $errorCount++;
                $errorDetails[] = ['row' => $rowNum, 'errors' => [$e->getMessage()]];
            }
        }

        $import->update([
            'status' => $errorCount === 0 ? CsvImport::STATUS_COMPLETED : CsvImport::STATUS_COMPLETED,
            'total_rows' => $parsed['totalRows'],
            'success_rows' => $successCount,
            'error_rows' => $errorCount,
            'import_errors' => $errorDetails,
            'completed_at' => now(),
        ]);

        return ['success' => $successCount, 'errors' => $errorCount, 'errorDetails' => $errorDetails];
    }

    /**
     * Preview the import (dry run): validate all rows without writing.
     *
     * @return array{totalRows: int, validRows: int, errorRows: int, preview: list<array{row: int, fullName: string|null, sex: string|null, valid: bool, errors: list<string>, duplicateCandidate: bool}>, errorSummary: list<array{row: int, errors: list<string>}>}
     */
    public static function previewImport(CsvImport $import, string $tenantId): array
    {
        $import->update(['status' => CsvImport::STATUS_DRY_RUN]);

        $parsed = self::parseCsv($import->file_path);
        $fieldMapping = $import->field_mapping ?? [];
        $duplicateDetector = app(DuplicateDetector::class);

        $preview = [];
        $errorSummary = [];
        $validCount = 0;
        $errorCount = 0;

        foreach ($parsed['rows'] as $index => $row) {
            $rowNum = $index + 1;
            $mapped = self::mapRow($fieldMapping, $row);
            $validation = self::validateRow($mapped);
            $isDuplicate = false;

            if ($validation['valid']) {
                // Quick duplicate check
                $identifierHashes = [];
                if (! empty($mapped['nationalId'])) {
                    $identifierHashes['national_id'] = PatientIdentifier::hashValue($mapped['nationalId']);
                }

                $dups = $duplicateDetector->candidates(
                    $tenantId,
                    (string) $mapped['fullName'],
                    $mapped['dateOfBirth'] ? (string) $mapped['dateOfBirth'] : null,
                    $identifierHashes,
                );

                $isDuplicate = $dups->isNotEmpty() && max($dups->pluck('score')->all()) >= 0.95;
            }

            $rowValid = $validation['valid'] && ! $isDuplicate;
            if ($rowValid) {
                $validCount++;
            } else {
                $errorCount++;
                $errors = $validation['errors'];
                if ($isDuplicate) {
                    $errors[] = 'Near-exact duplicate detected.';
                }
                $errorSummary[] = ['row' => $rowNum, 'errors' => $errors];
            }

            $preview[] = [
                'row' => $rowNum,
                'fullName' => $mapped['fullName'],
                'sex' => $mapped['sex'],
                'valid' => $rowValid,
                'errors' => $rowValid ? [] : ($validation['errors'] ?: ['Duplicate detected']),
                'duplicateCandidate' => $isDuplicate,
            ];
        }

        $import->update([
            'total_rows' => $parsed['totalRows'],
            'success_rows' => $validCount,
            'error_rows' => $errorCount,
            'validation_errors' => $errorSummary,
        ]);

        return [
            'totalRows' => $parsed['totalRows'],
            'validRows' => $validCount,
            'errorRows' => $errorCount,
            'preview' => $preview,
            'errorSummary' => $errorSummary,
        ];
    }
}
