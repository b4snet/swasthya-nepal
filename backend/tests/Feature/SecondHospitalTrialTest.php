<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SecondHospitalTrialService;
use Tests\TestCase;

/**
 * Second-Hospital Replication Trial Tests — Phase 98.
 *
 * Verifies:
 * - Hospital B profile definition (intentionally different)
 * - Onboarding workflow execution and measurement
 * - Configuration provenance
 * - Workflow validation
 * - Engineering intervention register
 * - Replication scorecard
 * - Migration reconciliation
 * - UAT execution and defect classification
 * - Hospital comparison
 */
class SecondHospitalTrialTest extends TestCase
{
    private SecondHospitalTrialService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SecondHospitalTrialService::class);
    }

    /** @test */
    public function it_defines_hospital_b_profile(): void
    {
        $profile = $this->service->defineHospitalBProfile();

        $this->assertEquals('Himalayan Specialty Clinic', $profile['name']);
        $this->assertEquals('specialty_clinic', $profile['type']);
        $this->assertEquals('Asia/Kathmandu', $profile['timezone']);
        $this->assertEquals('NPR', $profile['currency']);
        $this->assertEquals('ne', $profile['language']);
        $this->assertCount(3, $profile['departments']);
        $this->assertCount(6, $profile['services']);
        $this->assertCount(5, $profile['staff']);
        $this->assertCount(1, $profile['facilities']);
    }

    /** @test */
    public function it_defines_hospital_a_profile(): void
    {
        $profile = $this->service->getHospitalAProfile();

        $this->assertEquals('Nepal General Hospital', $profile['name']);
        $this->assertEquals('general_hospital', $profile['type']);
        $this->assertEquals(5, $profile['department_count']);
        $this->assertEquals(10, $profile['service_count']);
        $this->assertEquals(50, $profile['staff_count']);
        $this->assertEquals('en', $profile['language']);
    }

    /** @test */
    public function it_creates_hospital_b(): void
    {
        $profile = $this->service->defineHospitalBProfile();
        $hospital = $this->service->createHospitalB($profile);

        $this->assertNotEmpty($hospital['id']);
        $this->assertEquals('Himalayan Specialty Clinic', $hospital['name']);
        $this->assertEquals('created', $hospital['status']);
        $this->assertEquals('canonical_sas_path', $hospital['onboarding_path']);
    }

    /** @test */
    public function it_configures_facilities(): void
    {
        $config = $this->service->configureFacilities('hosp_himalayan_001', [
            ['name' => 'Himalayan Clinic - Main', 'type' => 'outpatient_clinic'],
        ]);

        $this->assertEquals('hosp_himalayan_001', $config['hospital_id']);
        $this->assertCount(1, $config['facilities']);
        $this->assertNotEmpty($config['configured_at']);
    }

    /** @test */
    public function it_configures_departments(): void
    {
        $config = $this->service->configureDepartments('hosp_himalayan_001', [
            ['name' => 'OPD', 'type' => 'outpatient', 'hours' => '09:00-17:00'],
            ['name' => 'Diagnostic Lab', 'type' => 'laboratory', 'hours' => '08:00-18:00'],
            ['name' => 'Pharmacy', 'type' => 'pharmacy', 'hours' => '08:00-20:00'],
        ]);

        $this->assertEquals('hosp_himalayan_001', $config['hospital_id']);
        $this->assertCount(3, $config['departments']);
    }

    /** @test */
    public function it_configures_services(): void
    {
        $config = $this->service->configureServices('hosp_himalayan_001', [
            ['name' => 'General Consultation', 'department' => 'OPD', 'price' => 800, 'type' => 'clinical', 'duration_minutes' => 30],
            ['name' => 'Blood Test', 'department' => 'Diagnostic Lab', 'price' => 500, 'type' => 'diagnostic', 'duration_minutes' => 15],
        ]);

        $this->assertEquals('hosp_himalayan_001', $config['hospital_id']);
        $this->assertCount(2, $config['services']);
    }

    /** @test */
    public function it_configures_staff(): void
    {
        $config = $this->service->configureStaff('hosp_himalayan_001', [
            ['name' => 'Dr. Aarav Sharma', 'role' => 'Doctor', 'department' => 'OPD'],
            ['name' => 'Sita Poudel', 'role' => 'Lab Technician', 'department' => 'Diagnostic Lab'],
        ]);

        $this->assertEquals('hosp_himalayan_001', $config['hospital_id']);
        $this->assertCount(2, $config['staff']);
    }

    /** @test */
    public function it_configures_branding(): void
    {
        $config = $this->service->configureBranding('hosp_himalayan_001', [
            'logo_url' => '/assets/branding/himalayan/logo.png',
            'primary_color' => '#2E7D32',
            'hospital_name_display' => 'Himalayan Specialty Clinic',
        ]);

        $this->assertEquals('hosp_himalayan_001', $config['hospital_id']);
        $this->assertEquals('#2E7D32', $config['branding']['primary_color']);
    }

    /** @test */
    public function it_configures_financials(): void
    {
        $config = $this->service->configureFinancials('hosp_himalayan_001', [
            'default_payment_method' => 'cash',
            'tax_rate' => 13.0,
            'invoice_prefix' => 'HSC',
        ]);

        $this->assertEquals('hosp_himalayan_001', $config['hospital_id']);
        $this->assertEquals('HSC', $config['financial_config']['invoice_prefix']);
        $this->assertEquals(13.0, $config['financial_config']['tax_rate']);
    }

    /** @test */
    public function it_configures_queues(): void
    {
        $config = $this->service->configureQueues('hosp_himalayan_001', [
            ['name' => 'OPD Queue', 'department' => 'OPD', 'priority_rules' => ['emergency_first', 'elderly_priority']],
            ['name' => 'Lab Queue', 'department' => 'Diagnostic Lab', 'priority_rules' => ['first_come_first_served']],
        ]);

        $this->assertEquals('hosp_himalayan_001', $config['hospital_id']);
        $this->assertCount(2, $config['queues']);
    }

    /** @test */
    public function it_verifies_initialization_no_leakage(): void
    {
        $hospitalBData = [
            'patients' => ['himalayan_patient_1', 'himalayan_patient_2'],
            'encounters' => ['himalayan_enc_1'],
            'invoices' => ['himalayan_inv_1'],
            'documents' => ['himalayan_doc_1'],
            'staff' => ['himalayan_staff_1'],
            'audit' => ['himalayan_audit_1'],
        ];

        $hospitalAData = [
            'patients' => ['general_patient_1', 'general_patient_2'],
            'encounters' => ['general_enc_1'],
            'invoices' => ['general_inv_1'],
            'documents' => ['general_doc_1'],
            'staff' => ['general_staff_1'],
            'audit' => ['general_audit_1'],
        ];

        $result = $this->service->verifyInitialization($hospitalBData, $hospitalAData);

        $this->assertTrue($result['initialized']);
        $this->assertTrue($result['no_leakage']);

        foreach ($result['checks'] as $check) {
            $this->assertFalse($check['leaked']);
        }
    }

    /** @test */
    public function it_detects_leakage_when_data_shared(): void
    {
        $hospitalBData = ['patients' => ['shared_patient']];
        $hospitalAData = ['patients' => ['shared_patient']];

        $result = $this->service->verifyInitialization($hospitalBData, $hospitalAData);

        $this->assertFalse($result['no_leakage']);
        $patientCheck = collect($result['checks'])->firstWhere('dimension', 'patients');
        $this->assertTrue($patientCheck['leaked']);
    }

    /** @test */
    public function it_records_interventions(): void
    {
        $intervention = $this->service->recordIntervention(
            'department_configuration',
            'automated',
            'Hospital B departments configured via API'
        );

        $this->assertEquals('department_configuration', $intervention['step']);
        $this->assertEquals('automated', $intervention['type']);
        $this->assertNotEmpty($intervention['timestamp']);
    }

    /** @test */
    public function it_classifies_uat_defects(): void
    {
        $defect = $this->service->classifyDefect(
            'Patient search returns Hospital A data',
            'core_product_bug'
        );

        $this->assertEquals('core_product_bug', $defect['category']);
        $this->assertEquals('high', $defect['severity']);
        $this->assertStringContainsString('Fix', $defect['recommendation']);
    }

    /** @test */
    public function it_classifies_config_defect(): void
    {
        $defect = $this->service->classifyDefect(
            'Tax rate incorrect for Hospital B',
            'configuration_problem'
        );

        $this->assertEquals('configuration_problem', $defect['category']);
        $this->assertEquals('medium', $defect['severity']);
        $this->assertStringContainsString('configuration', $defect['recommendation']);
    }

    /** @test */
    public function it_classifies_missing_capability(): void
    {
        $defect = $this->service->classifyDefect(
            'Radiology module not configured',
            'missing_capability'
        );

        $this->assertEquals('missing_capability', $defect['category']);
        $this->assertEquals('medium', $defect['severity']);
        $this->assertStringContainsString('roadmap', $defect['recommendation']);
    }

    /** @test */
    public function it_reconciles_migration(): void
    {
        $source = ['patients' => 100, 'encounters' => 200, 'invoices' => 50, 'payments' => 45, 'documents' => 300];
        $target = ['patients' => 100, 'encounters' => 200, 'invoices' => 50, 'payments' => 45, 'documents' => 300];

        $result = $this->service->reconcileMigration($source, $target);

        $this->assertTrue($result['reconciled']);
        $this->assertEquals(0, $result['discrepancy']);
        $this->assertCount(5, $result['details']);

        foreach ($result['details'] as $detail) {
            $this->assertTrue($detail['match']);
        }
    }

    /** @test */
    public function it_detects_migration_discrepancy(): void
    {
        $source = ['patients' => 100, 'encounters' => 200, 'invoices' => 50, 'payments' => 45, 'documents' => 300];
        $target = ['patients' => 98, 'encounters' => 195, 'invoices' => 50, 'payments' => 45, 'documents' => 300];

        $result = $this->service->reconcileMigration($source, $target);

        $this->assertFalse($result['reconciled']);
        $this->assertEquals(7, $result['discrepancy']);
    }

    /** @test */
    public function it_calculates_replication_scorecard(): void
    {
        $scorecard = $this->service->calculateScorecard([
            'creation_time_minutes' => 1,
            'configuration_time_minutes' => 39,
            'user_setup_minutes' => 5,
            'migration_minutes' => 5,
            'validation_minutes' => 2,
            'engineering_hours' => 0,
            'support_hours' => 0,
            'uat_defects' => 0,
            'security_defects' => 0,
            'config_changes' => 13,
            'automated_steps' => 13,
            'manual_steps' => 0,
        ]);

        $this->assertEquals(52, $scorecard['total_onboarding_minutes']);
        $this->assertEquals(0, $scorecard['engineering_hours']);
        $this->assertEquals(1.0, $scorecard['automation_ratio']);
        $this->assertEquals(0.0, $scorecard['defect_rate_per_feature']);
        $this->assertEquals('EXCELLENT', $scorecard['score']);
    }

    /** @test */
    public function it_calculates_good_score(): void
    {
        $scorecard = $this->service->calculateScorecard([
            'creation_time_minutes' => 5,
            'configuration_time_minutes' => 60,
            'user_setup_minutes' => 15,
            'migration_minutes' => 30,
            'validation_minutes' => 10,
            'engineering_hours' => 6,
            'support_hours' => 4,
            'uat_defects' => 2,
            'security_defects' => 0,
            'config_changes' => 10,
            'automated_steps' => 8,
            'manual_steps' => 2,
        ]);

        $this->assertEquals(120, $scorecard['total_onboarding_minutes']);
        $this->assertEquals(6, $scorecard['engineering_hours']);
        $this->assertEquals(0.8, $scorecard['automation_ratio']);
        $this->assertEquals('GOOD', $scorecard['score']);
    }

    /** @test */
    public function it_compares_hospitals(): void
    {
        $hospitalA = [
            'type' => 'general_hospital',
            'language' => 'en',
            'currency' => 'USD',
            'departments' => [['name' => 'OPD'], ['name' => 'Emergency'], ['name' => 'Lab']],
            'services' => [['name' => 'S1'], ['name' => 'S2']],
            'branding' => ['primary_color' => '#1976D2'],
        ];

        $hospitalB = [
            'type' => 'specialty_clinic',
            'language' => 'ne',
            'currency' => 'NPR',
            'departments' => [['name' => 'OPD']],
            'services' => [['name' => 'S1']],
            'branding' => ['primary_color' => '#2E7D32'],
        ];

        $result = $this->service->compareHospitals($hospitalA, $hospitalB);

        $this->assertEquals(6, $result['total_differences']);
        $this->assertTrue($result['all_intentional']);

        $dims = array_column($result['differences'], 'dimension');
        $this->assertContains('type', $dims);
        $this->assertContains('language', $dims);
        $this->assertContains('currency', $dims);
        $this->assertContains('department_count', $dims);
        $this->assertContains('service_count', $dims);
        $this->assertContains('branding_color', $dims);
    }

    /** @test */
    public function it_gets_uat_scenarios(): void
    {
        $scenarios = $this->service->getUatScenarios();

        $this->assertCount(8, $scenarios);

        foreach ($scenarios as $scenario) {
            $this->assertArrayHasKey('id', $scenario);
            $this->assertArrayHasKey('name', $scenario);
            $this->assertArrayHasKey('category', $scenario);
            $this->assertArrayHasKey('steps', $scenario);
            $this->assertArrayHasKey('expected', $scenario);
            $this->assertNotEmpty($scenario['steps']);
        }
    }

    /** @test */
    public function it_executes_onboarding_sequence(): void
    {
        $profile = $this->service->defineHospitalBProfile();
        $sequence = $this->service->executeOnboardingSequence($profile);

        $this->assertCount(13, $sequence);

        foreach ($sequence as $step) {
            $this->assertArrayHasKey('step', $step);
            $this->assertArrayHasKey('type', $step);
            $this->assertArrayHasKey('status', $step);
            $this->assertArrayHasKey('timestamp', $step);
            $this->assertEquals('completed', $step['status']);
        }

        // All steps should be automated
        $automated = array_filter($sequence, fn ($s) => $s['type'] === 'automated');
        $this->assertCount(13, $automated);
    }

    /** @test */
    public function defect_category_constants_are_correct(): void
    {
        $this->assertEquals('core_product_bug', SecondHospitalTrialService::DEFECT_CATEGORY_CORE_BUG);
        $this->assertEquals('configuration_problem', SecondHospitalTrialService::DEFECT_CATEGORY_CONFIG);
        $this->assertEquals('training_issue', SecondHospitalTrialService::DEFECT_CATEGORY_TRAINING);
        $this->assertEquals('hospital_policy', SecondHospitalTrialService::DEFECT_CATEGORY_POLICY);
        $this->assertEquals('external_dependency', SecondHospitalTrialService::DEFECT_CATEGORY_EXTERNAL);
        $this->assertEquals('missing_capability', SecondHospitalTrialService::DEFECT_CATEGORY_MISSING);
    }

    /** @test */
    public function intervention_type_constants_are_correct(): void
    {
        $this->assertEquals('engineering', SecondHospitalTrialService::INTERVENTION_TYPE_ENGINEERING);
        $this->assertEquals('configuration', SecondHospitalTrialService::INTERVENTION_TYPE_CONFIG);
        $this->assertEquals('manual_support', SecondHospitalTrialService::INTERVENTION_TYPE_MANUAL);
        $this->assertEquals('automated', SecondHospitalTrialService::INTERVENTION_TYPE_AUTOMATED);
    }

    /** @test */
    public function hospital_b_has_different_departments(): void
    {
        $profile = $this->service->defineHospitalBProfile();
        $departmentNames = array_column($profile['departments'], 'name');

        $this->assertContains('OPD', $departmentNames);
        $this->assertContains('Diagnostic Lab', $departmentNames);
        $this->assertContains('Pharmacy', $departmentNames);

        // Hospital A has Emergency, Radiology, ICU - Hospital B does not
        $this->assertNotContains('Emergency', $departmentNames);
        $this->assertNotContains('Radiology', $departmentNames);
        $this->assertNotContains('ICU', $departmentNames);
    }

    /** @test */
    public function hospital_b_has_different_services(): void
    {
        $profile = $this->service->defineHospitalBProfile();
        $serviceNames = array_column($profile['services'], 'name');

        $this->assertContains('General Consultation', $serviceNames);
        $this->assertContains('Blood Test', $serviceNames);
        $this->assertContains('X-Ray', $serviceNames);

        // Hospital B has 6 services, Hospital A has 10
        $this->assertCount(6, $serviceNames);
    }

    /** @test */
    public function hospital_b_uses_different_currency(): void
    {
        $profile = $this->service->defineHospitalBProfile();

        $this->assertEquals('NPR', $profile['currency']);
        $this->assertEquals('ne', $profile['language']);
    }
}
