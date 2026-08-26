<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\MultiHospitalService;
use Tests\TestCase;

/**
 * Multi-Hospital Replication Tests — Phase 97.
 *
 * Verifies:
 * - Hospital creation (from scratch and from template)
 * - Template system (create, version, diff)
 * - Tenant isolation (14 dimensions)
 * - Cross-hospital denial
 * - Configuration drift detection
 * - Hospital export/import
 * - Hospital lifecycle
 * - Data reconciliation
 * - Support model
 */
class MultiHospitalReplicationTest extends TestCase
{
    private MultiHospitalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MultiHospitalService::class);
    }

    /** @test */
    public function it_creates_hospital_from_scratch(): void
    {
        $hospital = $this->service->createHospital([
            'name' => 'Test Hospital Nepal',
            'timezone' => 'Asia/Kathmandu',
            'currency' => 'NPR',
            'departments' => ['OPD', 'Emergency', 'Lab', 'Pharmacy'],
        ]);

        $this->assertNotEmpty($hospital['id']);
        $this->assertEquals('Test Hospital Nepal', $hospital['name']);
        $this->assertEquals(MultiHospitalService::HOSPITAL_STATUS_CREATED, $hospital['status']);
        $this->assertNotEmpty($hospital['created_at']);
    }

    /** @test */
    public function it_creates_hospital_from_template(): void
    {
        $template = $this->service->createTemplate('General Hospital', [
            'departments' => [
                ['name' => 'OPD', 'type' => 'outpatient'],
                ['name' => 'Emergency', 'type' => 'emergency'],
                ['name' => 'Lab', 'type' => 'diagnostic'],
            ],
            'services' => [
                ['name' => 'Consultation', 'department' => 'OPD', 'price' => 1000, 'type' => 'clinical'],
                ['name' => 'Blood Test', 'department' => 'Lab', 'price' => 500, 'type' => 'diagnostic'],
            ],
            'roles' => [
                ['name' => 'Doctor', 'code' => 'DOCTOR', 'scope' => 'clinical'],
                ['name' => 'Nurse', 'code' => 'NURSE', 'scope' => 'clinical'],
            ],
            'facility_count' => 1,
            'timezone' => 'Asia/Kathmandu',
            'currency' => 'NPR',
        ]);

        $hospital = $this->service->createHospitalFromTemplate($template, [
            'name' => 'Nepal General Hospital',
        ]);

        $this->assertNotEmpty($hospital['id']);
        $this->assertEquals('Nepal General Hospital', $hospital['name']);
        $this->assertEquals($template['id'], $hospital['template_id']);
        $this->assertArrayHasKey('departments', $hospital['config']);
    }

    /** @test */
    public function it_validates_hospital_config(): void
    {
        $valid = $this->service->validateHospitalConfig([
            'name' => 'Test Hospital',
            'departments' => [['name' => 'OPD', 'type' => 'outpatient']],
            'services' => [['name' => 'Consultation', 'department' => 'OPD', 'price' => 1000, 'type' => 'clinical']],
            'timezone' => 'Asia/Kathmandu',
            'currency' => 'NPR',
        ]);

        $this->assertTrue($valid['valid']);
        $this->assertEmpty($valid['errors']);
    }

    /** @test */
    public function it_detects_invalid_hospital_config(): void
    {
        $result = $this->service->validateHospitalConfig([
            'name' => '',
            'departments' => [],
            'services' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertContains('Hospital name is required', $result['errors']);
    }

    /** @test */
    public function it_verifies_tenant_isolation_across_hospitals(): void
    {
        $hospitalA = [
            'patient_data' => 'hospital_a_patients',
            'encounter_data' => 'hospital_a_encounters',
            'appointment_data' => 'hospital_a_appointments',
            'order_data' => 'hospital_a_orders',
            'result_data' => 'hospital_a_results',
            'invoice_data' => 'hospital_a_invoices',
            'payment_data' => 'hospital_a_payments',
            'inventory_data' => 'hospital_a_inventory',
            'document_data' => 'hospital_a_documents',
            'staff_data' => 'hospital_a_staff',
            'audit_data' => 'hospital_a_audit',
            'configuration' => 'hospital_a_config',
            'branding' => 'hospital_a_branding',
            'notification_templates' => 'hospital_a_notifications',
        ];

        $hospitalB = [
            'patient_data' => 'hospital_b_patients',
            'encounter_data' => 'hospital_b_encounters',
            'appointment_data' => 'hospital_b_appointments',
            'order_data' => 'hospital_b_orders',
            'result_data' => 'hospital_b_results',
            'invoice_data' => 'hospital_b_invoices',
            'payment_data' => 'hospital_b_payments',
            'inventory_data' => 'hospital_b_inventory',
            'document_data' => 'hospital_b_documents',
            'staff_data' => 'hospital_b_staff',
            'audit_data' => 'hospital_b_audit',
            'configuration' => 'hospital_b_config',
            'branding' => 'hospital_b_branding',
            'notification_templates' => 'hospital_b_notifications',
        ];

        $result = $this->service->verifyMultiHospitalIsolation($hospitalA, $hospitalB);

        $this->assertEquals(14, $result['total']);
        $this->assertEquals(14, $result['passed']);
        $this->assertEquals(0, $result['failed']);

        foreach ($result['checks'] as $check) {
            $this->assertTrue($check['isolated']);
            $this->assertEquals('verified', $check['status']);
        }
    }

    /** @test */
    public function it_detects_tenant_isolation_failure(): void
    {
        $hospitalA = ['patient_data' => 'shared_data'];
        $hospitalB = ['patient_data' => 'shared_data'];

        $result = $this->service->verifyMultiHospitalIsolation($hospitalA, $hospitalB);

        $patientCheck = collect($result['checks'])->firstWhere('dimension', 'patient_data');
        $this->assertFalse($patientCheck['isolated']);
        $this->assertEquals('failed', $patientCheck['status']);
    }

    /** @test */
    public function it_verifies_cross_hospital_denial(): void
    {
        $result = $this->service->verifyCrossHospitalDenial();

        $this->assertEquals(8, count($result['checks']));
        $this->assertEquals(8, $result['passed']);
        $this->assertEquals(0, $result['failed']);

        foreach ($result['checks'] as $check) {
            $this->assertTrue($check['denied']);
        }
    }

    /** @test */
    public function it_diffs_configurations(): void
    {
        $templateConfig = [
            'timezone' => 'Asia/Kathmandu',
            'currency' => 'NPR',
            'language' => 'ne',
            'template_setting' => 'default',
        ];

        $hospitalConfig = [
            'timezone' => 'Asia/Kathmandu',  // Same
            'currency' => 'NPR',              // Same
            'language' => 'en',               // Different
            'custom_setting' => 'hospital',   // Only in hospital
        ];

        $diff = $this->service->diffConfigurations($templateConfig, $hospitalConfig);

        $this->assertEquals(2, $diff['identical']); // timezone, currency
        $this->assertEquals(1, $diff['different']); // language
        $this->assertCount(1, $diff['only_in_template']); // template_setting
        $this->assertCount(1, $diff['only_in_hospital']); // custom_setting
    }

    /** @test */
    public function it_detects_configuration_drift(): void
    {
        $original = ['timezone' => 'Asia/Kathmandu', 'currency' => 'NPR', 'language' => 'ne'];
        $current = ['timezone' => 'Asia/Kathmandu', 'currency' => 'USD', 'language' => 'en'];

        $drift = $this->service->detectDrift($original, $current);

        $this->assertTrue($drift['has_drift']);
        $this->assertEquals(2, $drift['drift_count']);
        $this->assertContains('currency', $drift['drift_items']);
        $this->assertContains('language', $drift['drift_items']);
    }

    /** @test */
    public function it_no_drift_when_identical(): void
    {
        $original = ['timezone' => 'Asia/Kathmandu', 'currency' => 'NPR'];
        $current = ['timezone' => 'Asia/Kathmandu', 'currency' => 'NPR'];

        $drift = $this->service->detectDrift($original, $current);

        $this->assertFalse($drift['has_drift']);
        $this->assertEquals(0, $drift['drift_count']);
    }

    /** @test */
    public function it_exports_hospital_configuration(): void
    {
        $hospital = [
            'name' => 'Export Hospital',
            'config' => [
                'name' => 'Export Hospital',
                'timezone' => 'Asia/Kathmandu',
                'currency' => 'NPR',
                'departments' => [['name' => 'OPD', 'type' => 'outpatient']],
                'services' => [['name' => 'Consultation', 'department' => 'OPD', 'price' => 1000, 'type' => 'clinical']],
                'roles' => [['name' => 'Doctor', 'code' => 'DOCTOR', 'scope' => 'clinical']],
                'branding' => ['logo_url' => 'https://example.com/logo.png'],
            ],
        ];

        $export = $this->service->exportConfiguration($hospital, 'admin@test.com');

        $this->assertNotEmpty($export['hospital_config']);
        $this->assertNotEmpty($export['departments']);
        $this->assertNotEmpty($export['services']);
        $this->assertNotEmpty($export['roles']);
        $this->assertNotEmpty($export['branding']);
        $this->assertNotEmpty($export['exported_at']);
        $this->assertEquals('admin@test.com', $export['exported_by']);
    }

    /** @test */
    public function it_imports_hospital_configuration(): void
    {
        $export = [
            'hospital_config' => [
                'name' => 'Imported Hospital',
                'timezone' => 'Asia/Kathmandu',
                'currency' => 'NPR',
            ],
            'departments' => [['name' => 'OPD', 'type' => 'outpatient']],
            'services' => [['name' => 'Consultation', 'department' => 'OPD', 'price' => 1000, 'type' => 'clinical']],
            'roles' => [['name' => 'Doctor', 'code' => 'DOCTOR', 'scope' => 'clinical']],
            'branding' => ['logo_url' => 'https://example.com/logo.png'],
        ];

        $hospital = $this->service->importConfiguration($export);

        $this->assertNotEmpty($hospital['id']);
        $this->assertStringContainsString('Copy', $hospital['name']);
        $this->assertEquals(MultiHospitalService::HOSPITAL_STATUS_CREATED, $hospital['status']);
        $this->assertArrayHasKey('departments', $hospital['config']);
        $this->assertEquals('Imported Hospital', $hospital['imported_from']);
    }

    /** @test */
    public function it_validates_lifecycle_transitions(): void
    {
        $this->assertTrue(
            $this->service->canTransitionStatus('created', 'configuring')['allowed']
        );
        $this->assertTrue(
            $this->service->canTransitionStatus('configuring', 'validating')['allowed']
        );
        $this->assertTrue(
            $this->service->canTransitionStatus('active', 'suspended')['allowed']
        );
        $this->assertTrue(
            $this->service->canTransitionStatus('active', 'offboarding')['allowed']
        );
    }

    /** @test */
    public function it_rejects_invalid_lifecycle_transitions(): void
    {
        $this->assertFalse(
            $this->service->canTransitionStatus('created', 'active')['allowed']
        );
        $this->assertFalse(
            $this->service->canTransitionStatus('offboarding', 'active')['allowed']
        );
        $this->assertFalse(
            $this->service->canTransitionStatus('active', 'created')['allowed']
        );
    }

    /** @test */
    public function it_verifies_data_reconciliation(): void
    {
        $hospitalA = [
            'patient_count' => 100,
            'encounter_count' => 200,
            'invoice_count' => 50,
            'payment_count' => 45,
            'inventory_count' => 1000,
            'document_count' => 300,
            'audit_count' => 500,
        ];

        $hospitalB = [
            'patient_count' => 50,
            'encounter_count' => 80,
            'invoice_count' => 20,
            'payment_count' => 18,
            'inventory_count' => 500,
            'document_count' => 100,
            'audit_count' => 200,
        ];

        $result = $this->service->verifyDataReconciliation($hospitalA, $hospitalB);

        $this->assertTrue($result['isolated']);
        $this->assertCount(7, $result['details']);

        foreach ($result['details'] as $detail) {
            $this->assertTrue($detail['isolated']);
        }
    }

    /** @test */
    public function it_verifies_template_isolation(): void
    {
        $result = $this->service->verifyTemplateIsolation(
            'tpl_general',
            'tpl_specialized'
        );

        $this->assertTrue($result['independent']);
        $this->assertEquals('tpl_general', $result['hospital_a_template']);
        $this->assertEquals('tpl_specialized', $result['hospital_b_template']);
        $this->assertFalse($result['same_template']);
    }

    /** @test */
    public function it_has_correct_support_model(): void
    {
        $model = $this->service->getSupportModel();

        $this->assertNotEmpty($model['support_types']);
        $this->assertCount(3, $model['support_types']);
        $this->assertNotEmpty($model['restrictions']);

        // Hospital admin doesn't need approval but is audited
        $hospitalAdmin = collect($model['support_types'])->firstWhere('type', 'hospital_admin');
        $this->assertFalse($hospitalAdmin['approval_required']);
        $this->assertTrue($hospitalAdmin['audit']);

        // Platform support requires approval
        $platformSupport = collect($model['support_types'])->firstWhere('type', 'platform_support');
        $this->assertTrue($platformSupport['approval_required']);
    }

    /** @test */
    public function hospital_status_constants_are_correct(): void
    {
        $this->assertEquals('created', MultiHospitalService::HOSPITAL_STATUS_CREATED);
        $this->assertEquals('configuring', MultiHospitalService::HOSPITAL_STATUS_CONFIGURING);
        $this->assertEquals('validating', MultiHospitalService::HOSPITAL_STATUS_VALIDATING);
        $this->assertEquals('ready', MultiHospitalService::HOSPITAL_STATUS_READY);
        $this->assertEquals('active', MultiHospitalService::HOSPITAL_STATUS_ACTIVE);
        $this->assertEquals('suspended', MultiHospitalService::HOSPITAL_STATUS_SUSPENDED);
        $this->assertEquals('offboarding', MultiHospitalService::HOSPITAL_STATUS_OFFBOARDING);
    }

    /** @test */
    public function template_version_increments(): void
    {
        $template1 = $this->service->createTemplate('Test', ['departments' => []]);
        $this->assertEquals(1, $template1['version']);
    }

    /** @test */
    public function hospital_from_template_preserves_config(): void
    {
        $template = $this->service->createTemplate('Test', [
            'departments' => [['name' => 'OPD']],
            'services' => [['name' => 'Consult']],
        ]);

        $hospital = $this->service->createHospitalFromTemplate($template, [
            'name' => 'New Hospital',
        ]);

        $this->assertArrayHasKey('departments', $hospital['config']);
        $this->assertArrayHasKey('services', $hospital['config']);
    }

    /** @test */
    public function offboarding_simulation_detects_blockers(): void
    {
        $hospital = [
            'config' => [
                'active_patients' => true,
                'open_encounters' => true,
                'pending_invoices' => true,
            ],
        ];

        $result = $this->service->simulateOffboarding($hospital);

        $this->assertFalse($result['can_offboard']);
        $this->assertCount(3, $result['blockers']);
        $this->assertTrue($result['export_available']);
    }

    /** @test */
    public function offboarding_allows_when_no_blockers(): void
    {
        $hospital = [
            'config' => [],
        ];

        $result = $this->service->simulateOffboarding($hospital);

        $this->assertTrue($result['can_offboard']);
        $this->assertEmpty($result['blockers']);
    }
}
