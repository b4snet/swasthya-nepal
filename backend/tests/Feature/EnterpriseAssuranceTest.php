<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\EnterpriseAssuranceService;
use Tests\TestCase;

/**
 * Enterprise Assurance and Control Verification Tests — Phase 96.
 *
 * Verifies:
 * - Control framework completeness
 * - Security control matrix
 * - Clinical safety controls
 * - Financial controls
 * - Data integrity controls
 * - Availability controls
 * - Governance controls
 * - Tenant isolation
 * - RLS enforcement
 * - Audit coverage
 * - Evidence recording
 */
class EnterpriseAssuranceTest extends TestCase
{
    private EnterpriseAssuranceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EnterpriseAssuranceService::class);
    }

    /** @test */
    public function it_generates_control_framework(): void
    {
        $framework = $this->service->getControlFramework();

        $this->assertNotEmpty($framework);
        $this->assertArrayHasKey('SEC-001', $framework);
        $this->assertArrayHasKey('CSA-001', $framework);
        $this->assertArrayHasKey('FIN-001', $framework);
        $this->assertArrayHasKey('DI-001', $framework);
        $this->assertArrayHasKey('AVL-001', $framework);
        $this->assertArrayHasKey('GOV-001', $framework);

        // Verify each control has required fields
        foreach ($framework as $id => $control) {
            $this->assertArrayHasKey('id', $control);
            $this->assertArrayHasKey('name', $control);
            $this->assertArrayHasKey('domain', $control);
            $this->assertArrayHasKey('objective', $control);
            $this->assertArrayHasKey('implementation', $control);
            $this->assertArrayHasKey('test', $control);
            $this->assertArrayHasKey('evidence', $control);
            $this->assertArrayHasKey('owner', $control);
            $this->assertArrayHasKey('status', $control);
            $this->assertArrayHasKey('exception', $control);
            $this->assertEquals($id, $control['id']);
        }
    }

    /** @test */
    public function it_produces_assurance_report_with_trust_decision(): void
    {
        $report = $this->service->runAssuranceReport();

        $this->assertArrayHasKey('controls', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('domains', $report);
        $this->assertArrayHasKey('trust_decision', $report);

        $this->assertGreaterThanOrEqual(25, $report['summary']['total']);
        $this->assertGreaterThan(0, $report['summary']['tested'] + $report['summary']['evidence_verified']);

        // Trust decision must be valid
        $validDecisions = [
            'CONTROLLED',
            'CONTROLLED_WITH_CONDITIONS',
            'GAPS_REMAIN',
            'MAJOR_CONTROL_WORK_REQUIRED',
        ];
        $this->assertContains($report['trust_decision'], $validDecisions);
    }

    /** @test */
    public function it_covers_all_control_domains(): void
    {
        $report = $this->service->runAssuranceReport();
        $domains = array_keys($report['domains']);

        $this->assertContains('security', $domains);
        $this->assertContains('clinical_safety', $domains);
        $this->assertContains('financial', $domains);
        $this->assertContains('data_integrity', $domains);
        $this->assertContains('availability', $domains);
        $this->assertContains('audit', $domains);
        $this->assertContains('ai_governance', $domains);
        $this->assertContains('configuration', $domains);
    }

    /** @test */
    public function it_verifies_tenant_isolation(): void
    {
        $hospitalA = [
            'patient' => 'hospital_a_patients',
            'encounter' => 'hospital_a_encounters',
            'documents' => 'hospital_a_docs',
            'finance' => 'hospital_a_finance',
            'inventory' => 'hospital_a_inventory',
            'staff' => 'hospital_a_staff',
            'audit' => 'hospital_a_audit',
            'configuration' => 'hospital_a_config',
            'clinical' => 'hospital_a_clinical',
        ];

        $hospitalB = [
            'patient' => 'hospital_b_patients',
            'encounter' => 'hospital_b_encounters',
            'documents' => 'hospital_b_docs',
            'finance' => 'hospital_b_finance',
            'inventory' => 'hospital_b_inventory',
            'staff' => 'hospital_b_staff',
            'audit' => 'hospital_b_audit',
            'configuration' => 'hospital_b_config',
            'clinical' => 'hospital_b_clinical',
        ];

        $result = $this->service->verifyTenantIsolation($hospitalA, $hospitalB);

        $this->assertEquals(9, count($result['checks']));
        $this->assertEquals(9, $result['passed']);
        $this->assertEquals(0, $result['failed']);

        foreach ($result['checks'] as $check) {
            $this->assertTrue($check['isolated']);
            $this->assertEquals(EnterpriseAssuranceService::CONTROL_STATUS_TESTED, $check['status']);
        }
    }

    /** @test */
    public function it_detects_tenant_isolation_failure(): void
    {
        $hospitalA = ['patient' => 'shared_data', 'finance' => 'hospital_a'];
        $hospitalB = ['patient' => 'shared_data', 'finance' => 'hospital_b'];

        $result = $this->service->verifyTenantIsolation($hospitalA, $hospitalB);

        $patientCheck = collect($result['checks'])->firstWhere('dimension', 'patient');
        $this->assertFalse($patientCheck['isolated']);
        $this->assertEquals(EnterpriseAssuranceService::CONTROL_STATUS_NOT_IMPLEMENTED, $patientCheck['status']);
    }

    /** @test */
    public function it_verifies_rls_enforcement(): void
    {
        $tables = [
            ['tablename' => 'patients', 'rowsecurity' => true],
            ['tablename' => 'encounters', 'rowsecurity' => true],
            ['tablename' => 'appointments', 'rowsecurity' => true],
            ['tablename' => 'orders', 'rowsecurity' => true],
            ['tablename' => 'results', 'rowsecurity' => true],
        ];

        $result = $this->service->verifyRLSEnforcement($tables);

        $this->assertEquals(5, $result['total']);
        $this->assertEquals(5, $result['with_rls']);
        $this->assertEmpty($result['without_rls']);
    }

    /** @test */
    public function it_detects_missing_rls(): void
    {
        $tables = [
            ['tablename' => 'patients', 'rowsecurity' => true],
            ['tablename' => 'test_logs', 'rowsecurity' => false],
        ];

        $result = $this->service->verifyRLSEnforcement($tables);

        $this->assertEquals(2, $result['total']);
        $this->assertEquals(1, $result['with_rls']);
        $this->assertContains('test_logs', $result['without_rls']);
    }

    /** @test */
    public function it_verifies_audit_coverage(): void
    {
        $categories = [
            'authentication' => true,
            'authorization' => true,
            'patient_access' => true,
            'clinical_mutation' => true,
            'financial_mutation' => true,
            'export' => true,
            'configuration' => true,
            'ai_actions' => true,
            'interoperability' => true,
            'security_events' => true,
        ];

        $result = $this->service->verifyAuditCoverage($categories);

        $this->assertEquals(10, count($result['checks']));
        $this->assertEquals(10, $result['covered']);
        $this->assertEquals(0, $result['missing']);
    }

    /** @test */
    public function it_detects_audit_gaps(): void
    {
        $categories = [
            'authentication' => true,
            'authorization' => true,
            'export' => false,
            'ai_actions' => false,
        ];

        $result = $this->service->verifyAuditCoverage($categories);

        $this->assertEquals(2, $result['covered']);
        $this->assertEquals(2, $result['missing']);

        $exportCheck = collect($result['checks'])->firstWhere('category', 'export');
        $this->assertEquals(EnterpriseAssuranceService::CONTROL_STATUS_PARTIAL, $exportCheck['status']);
    }

    /** @test */
    public function it_verifies_financial_controls(): void
    {
        $config = [
            'charge_authorization' => true,
            'duplicate_payment' => true,
            'period_lock' => true,
            'reconciliation' => true,
            'refund_approval' => true,
            'void_control' => true,
            'audit_trail' => true,
            'export_control' => true,
            'segregation_of_duties' => true,
        ];

        $result = $this->service->verifyFinancialControls($config);

        $this->assertEquals(9, $result['summary']['total']);
        $this->assertEquals(9, $result['summary']['implemented']);
        $this->assertEquals(0, $result['summary']['gaps']);
    }

    /** @test */
    public function it_detects_financial_control_gaps(): void
    {
        $config = [
            'charge_authorization' => true,
            'period_lock' => true,
            'audit_trail' => true,
        ];

        $result = $this->service->verifyFinancialControls($config);

        $this->assertEquals(9, $result['summary']['total']);
        $this->assertEquals(3, $result['summary']['implemented']);
        $this->assertEquals(6, $result['summary']['gaps']);
    }

    /** @test */
    public function it_verifies_clinical_controls(): void
    {
        $config = [
            'patient_identification' => true,
            'encounter_scoping' => true,
            'allergy_check' => true,
            'medication_verification' => true,
            'result_attribution' => true,
            'clinical_documentation' => true,
            'emergency_access' => true,
            'wrong_patient_prevention' => true,
        ];

        $result = $this->service->verifyClinicalControls($config);

        $this->assertEquals(8, $result['summary']['total']);
        $this->assertEquals(8, $result['summary']['implemented']);
        $this->assertEquals(0, $result['summary']['gaps']);
    }

    /** @test */
    public function it_detects_clinical_control_gaps(): void
    {
        $config = [
            'patient_identification' => true,
            'allergy_check' => true,
        ];

        $result = $this->service->verifyClinicalControls($config);

        $this->assertEquals(8, $result['summary']['total']);
        $this->assertEquals(2, $result['summary']['implemented']);
        $this->assertEquals(6, $result['summary']['gaps']);
    }

    /** @test */
    public function it_records_evidence_correctly(): void
    {
        $evidence = $this->service->recordEvidence(
            'SEC-003',
            'Row-Level Security',
            'Cross-tenant query blocked by RLS',
            'PASS',
            'local',
            'abc1234'
        );

        $this->assertEquals('SEC-003', $evidence['control_id']);
        $this->assertEquals('Row-Level Security', $evidence['control_name']);
        $this->assertEquals('Cross-tenant query blocked by RLS', $evidence['test']);
        $this->assertEquals('PASS', $evidence['result']);
        $this->assertEquals('local', $evidence['environment']);
        $this->assertEquals('abc1234', $evidence['commit']);
        $this->assertNotEmpty($evidence['date']);
        $this->assertNotEmpty($evidence['reviewer']);
    }

    /** @test */
    public function it_has_security_controls_for_all_critical_areas(): void
    {
        $controls = $this->service->getControlFramework();
        $securityControls = array_filter($controls, fn ($c) => $c['domain'] === 'security');

        $this->assertGreaterThanOrEqual(5, count($securityControls));

        // Verify specific critical security controls exist
        $ids = array_keys($securityControls);
        $this->assertContains('SEC-001', $ids); // Authentication
        $this->assertContains('SEC-002', $ids); // Authorization
        $this->assertContains('SEC-003', $ids); // RLS
        $this->assertContains('SEC-004', $ids); // Tenant Isolation
        $this->assertContains('SEC-005', $ids); // Secret Management
    }

    /** @test */
    public function it_has_clinical_safety_controls(): void
    {
        $controls = $this->service->getControlFramework();
        $clinicalControls = array_filter($controls, fn ($c) => $c['domain'] === 'clinical_safety');

        $this->assertGreaterThanOrEqual(5, count($clinicalControls));

        $ids = array_keys($clinicalControls);
        $this->assertContains('CSA-001', $ids); // Patient Identification
        $this->assertContains('CSA-003', $ids); // Allergy Check
        $this->assertContains('CSA-004', $ids); // Medication Verification
        $this->assertContains('CSA-007', $ids); // High-Risk Confirmation
    }

    /** @test */
    public function it_has_financial_controls(): void
    {
        $controls = $this->service->getControlFramework();
        $financialControls = array_filter($controls, fn ($c) => $c['domain'] === 'financial');

        $this->assertGreaterThanOrEqual(3, count($financialControls));

        $ids = array_keys($financialControls);
        $this->assertContains('FIN-001', $ids); // Charge Authorization
        $this->assertContains('FIN-003', $ids); // Period Lock
        $this->assertContains('FIN-005', $ids); // Audit Trail
    }

    /** @test**
    }

    /** @test */
    public function it_has_governance_controls(): void
    {
        $controls = $this->service->getControlFramework();
        $governanceControls = array_filter($controls, fn ($c) => $c['domain'] === 'audit' || $c['domain'] === 'configuration' || $c['domain'] === 'ai_governance' || $c['domain'] === 'privacy');

        $this->assertGreaterThanOrEqual(3, count($governanceControls));

        $ids = array_keys($governanceControls);
        $this->assertContains('GOV-001', $ids); // Audit Trail
        $this->assertContains('GOV-004', $ids); // AI Authority Boundary
        $this->assertContains('GOV-005', $ids); // Data Classification
    }

    /** @test */
    public function control_constants_are_correct(): void
    {
        $this->assertEquals('implemented', EnterpriseAssuranceService::CONTROL_STATUS_IMPLEMENTED);
        $this->assertEquals('tested', EnterpriseAssuranceService::CONTROL_STATUS_TESTED);
        $this->assertEquals('evidence_verified', EnterpriseAssuranceService::CONTROL_STATUS_EVIDENCE_VERIFIED);
        $this->assertEquals('partial', EnterpriseAssuranceService::CONTROL_STATUS_PARTIAL);
        $this->assertEquals('policy_dependency', EnterpriseAssuranceService::CONTROL_STATUS_POLICY_DEPENDENCY);
        $this->assertEquals('external_dependency', EnterpriseAssuranceService::CONTROL_STATUS_EXTERNAL_DEPENDENCY);
        $this->assertEquals('not_implemented', EnterpriseAssuranceService::CONTROL_STATUS_NOT_IMPLEMENTED);
        $this->assertEquals('not_applicable', EnterpriseAssuranceService::CONTROL_STATUS_NOT_APPLICABLE);
    }

    /** @test */
    public function domain_constants_are_correct(): void
    {
        $this->assertEquals('security', EnterpriseAssuranceService::CONTROL_DOMAIN_SECURITY);
        $this->assertEquals('privacy', EnterpriseAssuranceService::CONTROL_DOMAIN_PRIVACY);
        $this->assertEquals('clinical_safety', EnterpriseAssuranceService::CONTROL_DOMAIN_CLINICAL);
        $this->assertEquals('financial', EnterpriseAssuranceService::CONTROL_DOMAIN_FINANCIAL);
        $this->assertEquals('data_integrity', EnterpriseAssuranceService::CONTROL_DOMAIN_DATA_INTEGRITY);
        $this->assertEquals('availability', EnterpriseAssuranceService::CONTROL_DOMAIN_AVAILABILITY);
        $this->assertEquals('interoperability', EnterpriseAssuranceService::CONTROL_DOMAIN_INTEROPERABILITY);
        $this->assertEquals('ai_governance', EnterpriseAssuranceService::CONTROL_DOMAIN_AI);
        $this->assertEquals('configuration', EnterpriseAssuranceService::CONTROL_DOMAIN_CONFIGURATION);
        $this->assertEquals('audit', EnterpriseAssuranceService::CONTROL_DOMAIN_AUDIT);
    }

    /** @test */
    public function all_controls_have_valid_status(): void
    {
        $controls = $this->service->getControlFramework();
        $validStatuses = [
            EnterpriseAssuranceService::CONTROL_STATUS_IMPLEMENTED,
            EnterpriseAssuranceService::CONTROL_STATUS_TESTED,
            EnterpriseAssuranceService::CONTROL_STATUS_EVIDENCE_VERIFIED,
            EnterpriseAssuranceService::CONTROL_STATUS_PARTIAL,
            EnterpriseAssuranceService::CONTROL_STATUS_POLICY_DEPENDENCY,
            EnterpriseAssuranceService::CONTROL_STATUS_EXTERNAL_DEPENDENCY,
            EnterpriseAssuranceService::CONTROL_STATUS_NOT_IMPLEMENTED,
            EnterpriseAssuranceService::CONTROL_STATUS_NOT_APPLICABLE,
        ];

        foreach ($controls as $control) {
            $this->assertContains(
                $control['status'],
                $validStatuses,
                "Control {$control['id']} has invalid status: {$control['status']}"
            );
        }
    }
}
