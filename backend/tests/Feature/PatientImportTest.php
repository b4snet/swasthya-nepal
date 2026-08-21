<?php

namespace Tests\Feature;

use App\Models\CsvImport;
use App\Services\PatientCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Patient CSV import tests (Phase 80).
 */
class PatientImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_columns_defined(): void
    {
        $columns = PatientCsvImportService::templateColumns();

        $this->assertIsArray($columns);
        $this->assertArrayHasKey('full_name', $columns);
        $this->assertArrayHasKey('date_of_birth', $columns);
        $this->assertArrayHasKey('sex', $columns);
        $this->assertArrayHasKey('blood_group', $columns);
        $this->assertArrayHasKey('phone', $columns);
        $this->assertArrayHasKey('email', $columns);
        $this->assertArrayHasKey('national_id', $columns);
        $this->assertArrayHasKey('passport', $columns);
    }

    public function test_validate_row_valid(): void
    {
        $result = PatientCsvImportService::validateRow([
            'fullName' => 'Ram Bahadur Thapa',
            'dateOfBirth' => '1985-03-15',
            'sex' => 'male',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validate_row_missing_name(): void
    {
        $result = PatientCsvImportService::validateRow([
            'fullName' => '',
            'dateOfBirth' => '1985-03-15',
            'sex' => 'male',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_validate_row_invalid_sex(): void
    {
        $result = PatientCsvImportService::validateRow([
            'fullName' => 'Ram Bahadur',
            'dateOfBirth' => '1985-03-15',
            'sex' => 'invalid',
        ]);

        $this->assertFalse($result['valid']);
    }

    public function test_validate_row_future_dob(): void
    {
        $result = PatientCsvImportService::validateRow([
            'fullName' => 'Ram Bahadur',
            'dateOfBirth' => '2030-01-01',
            'sex' => 'male',
        ]);

        $this->assertFalse($result['valid']);
    }

    public function test_validate_row_invalid_blood_group(): void
    {
        $result = PatientCsvImportService::validateRow([
            'fullName' => 'Ram Bahadur',
            'dateOfBirth' => '1985-03-15',
            'sex' => 'male',
            'bloodGroup' => 'X+',
        ]);

        $this->assertFalse($result['valid']);
    }

    public function test_map_row_basic(): void
    {
        $mapping = [
            'full_name' => 'full_name',
            'date_of_birth' => 'date_of_birth',
            'sex' => 'sex',
            'phone' => 'phone',
        ];

        $row = [
            'full_name' => 'Ram Bahadur',
            'date_of_birth' => '1985-03-15',
            'sex' => 'male',
            'phone' => '9841234567',
        ];

        $mapped = PatientCsvImportService::mapRow($mapping, $row);

        $this->assertEquals('Ram Bahadur', $mapped['fullName']);
        $this->assertEquals('1985-03-15', $mapped['dateOfBirth']);
        $this->assertEquals('male', $mapped['sex']);
        $this->assertEquals('9841234567', $mapped['phone']);
    }

    public function test_map_row_with_emergency_contact(): void
    {
        $mapping = [
            'full_name' => 'full_name',
            'date_of_birth' => 'date_of_birth',
            'sex' => 'sex',
            'emergency_name' => 'emergency_contact_name',
            'emergency_phone' => 'emergency_contact_phone',
            'emergency_rel' => 'emergency_contact_relation',
        ];

        $row = [
            'full_name' => 'Sita Devi',
            'date_of_birth' => '1990-07-22',
            'sex' => 'female',
            'emergency_name' => 'Ram',
            'emergency_phone' => '9851234567',
            'emergency_rel' => 'spouse',
        ];

        $mapped = PatientCsvImportService::mapRow($mapping, $row);

        $this->assertNotNull($mapped['emergencyContact']);
        $this->assertEquals('Ram', $mapped['emergencyContact']['name']);
        $this->assertEquals('9851234567', $mapped['emergencyContact']['phone']);
        $this->assertEquals('spouse', $mapped['emergencyContact']['relation']);
    }

    public function test_csv_import_model_states(): void
    {
        $this->assertEquals('pending', CsvImport::STATUS_PENDING);
        $this->assertEquals('validating', CsvImport::STATUS_VALIDATING);
        $this->assertEquals('dry_run', CsvImport::STATUS_DRY_RUN);
        $this->assertEquals('importing', CsvImport::STATUS_IMPORTING);
        $this->assertEquals('completed', CsvImport::STATUS_COMPLETED);
        $this->assertEquals('failed', CsvImport::STATUS_FAILED);
    }

    public function test_csv_import_model_entity_types(): void
    {
        $this->assertContains('patient', CsvImport::ENTITY_TYPES);
        $this->assertIsArray(CsvImport::ENTITY_TYPES);
    }

    public function test_parse_csv_generates_valid_structure(): void
    {
        $csv = "full_name,date_of_birth,sex\nRam Bahadur,1985-03-15,male\nSita Devi,1990-07-22,female\n";
        $path = 'imports/test-'.time().'.csv';
        Storage::disk('local')->put($path, $csv);

        $result = PatientCsvImportService::parseCsv($path);

        $this->assertEquals(['full_name', 'date_of_birth', 'sex'], $result['headers']);
        $this->assertCount(2, $result['rows']);
        $this->assertEquals(2, $result['totalRows']);
        $this->assertEquals('Ram Bahadur', $result['rows'][0]['full_name']);
    }
}
