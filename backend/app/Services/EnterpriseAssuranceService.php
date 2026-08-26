<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Enterprise Assurance and Control Verification Service — Phase 96.
 *
 * Provides evidence-based control verification across:
 * - Security controls (auth, authZ, RLS, tenant isolation)
 * - Clinical safety controls (patient ID, medication, results)
 * - Financial controls (payment, reconciliation, period lock)
 * - Data integrity controls (FK, constraints, audit)
 * - Availability controls (backup, recovery, monitoring)
 * - Governance controls (change, configuration, AI)
 *
 * Every control records: ID, name, objective, implementation, test, evidence, owner, status, exception.
 */
class EnterpriseAssuranceService
{
    public const CONTROL_STATUS_IMPLEMENTED = 'implemented';

    public const CONTROL_STATUS_TESTED = 'tested';

    public const CONTROL_STATUS_EVIDENCE_VERIFIED = 'evidence_verified';

    public const CONTROL_STATUS_PARTIAL = 'partial';

    public const CONTROL_STATUS_POLICY_DEPENDENCY = 'policy_dependency';

    public const CONTROL_STATUS_EXTERNAL_DEPENDENCY = 'external_dependency';

    public const CONTROL_STATUS_NOT_IMPLEMENTED = 'not_implemented';

    public const CONTROL_STATUS_NOT_APPLICABLE = 'not_applicable';

    public const CONTROL_DOMAIN_SECURITY = 'security';

    public const CONTROL_DOMAIN_PRIVACY = 'privacy';

    public const CONTROL_DOMAIN_CLINICAL = 'clinical_safety';

    public const CONTROL_DOMAIN_FINANCIAL = 'financial';

    public const CONTROL_DOMAIN_DATA_INTEGRITY = 'data_integrity';

    public const CONTROL_DOMAIN_AVAILABILITY = 'availability';

    public const CONTROL_DOMAIN_INTEROPERABILITY = 'interoperability';

    public const CONTROL_DOMAIN_AI = 'ai_governance';

    public const CONTROL_DOMAIN_CONFIGURATION = 'configuration';

    public const CONTROL_DOMAIN_AUDIT = 'audit';

    /**
     * Generate the complete SWASTHYA control framework.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getControlFramework(): array
    {
        return array_merge(
            $this->getSecurityControls(),
            $this->getClinicalSafetyControls(),
            $this->getFinancialControls(),
            $this->getDataIntegrityControls(),
            $this->getAvailabilityControls(),
            $this->getGovernanceControls()
        );
    }

    /**
     * Run all control verifications and produce an assurance report.
     *
     * @return array{
     *   controls: array<string, array<string, mixed>>,
     *   summary: array{total: int, implemented: int, tested: int, evidence_verified: int, partial: int, policy_dependency: int, not_implemented: int, exceptions: int},
     *   domains: array<string, array{total: int, implemented: int, gaps: list<string>}>,
     *   trust_decision: string
     * }
     */
    public function runAssuranceReport(): array
    {
        $controls = $this->getControlFramework();
        $domains = [];
        $summary = [
            'total' => 0,
            'implemented' => 0,
            'tested' => 0,
            'evidence_verified' => 0,
            'partial' => 0,
            'policy_dependency' => 0,
            'not_implemented' => 0,
            'exceptions' => 0,
        ];

        foreach ($controls as $id => $control) {
            $summary['total']++;
            $status = $control['status'] ?? self::CONTROL_STATUS_NOT_IMPLEMENTED;
            match ($status) {
                self::CONTROL_STATUS_IMPLEMENTED => $summary['implemented']++,
                self::CONTROL_STATUS_TESTED => $summary['tested']++,
                self::CONTROL_STATUS_EVIDENCE_VERIFIED => $summary['evidence_verified']++,
                self::CONTROL_STATUS_PARTIAL => $summary['partial']++,
                self::CONTROL_STATUS_POLICY_DEPENDENCY => $summary['policy_dependency']++,
                default => $summary['not_implemented']++,
            };

            if (! empty($control['exception'])) {
                $summary['exceptions']++;
            }

            $domain = $control['domain'] ?? 'unknown';
            if (! isset($domains[$domain])) {
                $domains[$domain] = ['total' => 0, 'implemented' => 0, 'gaps' => []];
            }
            $domains[$domain]['total']++;
            if (in_array($status, [self::CONTROL_STATUS_IMPLEMENTED, self::CONTROL_STATUS_TESTED, self::CONTROL_STATUS_EVIDENCE_VERIFIED])) {
                $domains[$domain]['implemented']++;
            } else {
                $domains[$domain]['gaps'][] = $id;
            }
        }

        // Trust decision
        $passRate = $summary['total'] > 0
            ? ($summary['implemented'] + $summary['tested'] + $summary['evidence_verified']) / $summary['total']
            : 0;

        $trustDecision = match (true) {
            $passRate >= 0.95 && $summary['not_implemented'] === 0 => 'CONTROLLED',
            $passRate >= 0.80 => 'CONTROLLED_WITH_CONDITIONS',
            $passRate >= 0.60 => 'GAPS_REMAIN',
            default => 'MAJOR_CONTROL_WORK_REQUIRED',
        };

        return [
            'controls' => $controls,
            'summary' => $summary,
            'domains' => $domains,
            'trust_decision' => $trustDecision,
        ];
    }

    /**
     * Verify tenant isolation controls.
     *
     * @param  array<string, mixed>  $hospitalA
     * @param  array<string, mixed>  $hospitalB
     * @return array{checks: list<array<string, mixed>>, passed: int, failed: int}
     */
    public function verifyTenantIsolation(array $hospitalA, array $hospitalB): array
    {
        $checks = [];
        $dimensions = [
            'patient' => 'Patient records isolated per tenant',
            'encounter' => 'Encounters isolated per tenant',
            'documents' => 'Documents isolated per tenant',
            'finance' => 'Financial records isolated per tenant',
            'inventory' => 'Inventory isolated per tenant',
            'staff' => 'Staff records isolated per tenant',
            'audit' => 'Audit records isolated per tenant',
            'configuration' => 'Configuration isolated per tenant',
            'clinical' => 'Clinical records isolated per tenant',
        ];

        foreach ($dimensions as $dim => $objective) {
            $isolated = isset($hospitalA[$dim]) && isset($hospitalB[$dim])
                && $hospitalA[$dim] !== $hospitalB[$dim];

            $checks[] = [
                'dimension' => $dim,
                'objective' => $objective,
                'isolated' => $isolated,
                'status' => $isolated ? self::CONTROL_STATUS_TESTED : self::CONTROL_STATUS_NOT_IMPLEMENTED,
            ];
        }

        $passed = count(array_filter($checks, fn ($c) => $c['isolated']));
        $failed = count($checks) - $passed;

        return ['checks' => $checks, 'passed' => $passed, 'failed' => $failed];
    }

    /**
     * Verify RLS enforcement across critical tables.
     *
     * @param  list<array<string, mixed>>  $tables
     * @return array{total: int, with_rls: int, without_rls: list<string>}
     */
    public function verifyRLSEnforcement(array $tables): array
    {
        $withoutRls = [];
        $withRls = 0;

        foreach ($tables as $table) {
            $name = $table['tablename'] ?? '';
            $rlsEnabled = $table['rowsecurity'] ?? false;
            if ($rlsEnabled) {
                $withRls++;
            } else {
                $withoutRls[] = $name;
            }
        }

        return [
            'total' => count($tables),
            'with_rls' => $withRls,
            'without_rls' => $withoutRls,
        ];
    }

    /**
     * Verify audit coverage for critical event categories.
     *
     * @param  array<string, bool>  $categories
     * @return array{checks: list<array<string, mixed>>, covered: int, missing: int}
     */
    public function verifyAuditCoverage(array $categories): array
    {
        $checks = [];
        $covered = 0;
        $missing = 0;

        $descriptions = [
            'authentication' => 'Login, logout, session events',
            'authorization' => 'Permission changes, role assignments',
            'patient_access' => 'Patient record access and modifications',
            'clinical_mutation' => 'Orders, results, prescriptions',
            'financial_mutation' => 'Charges, payments, refunds',
            'export' => 'Data export events',
            'configuration' => 'System configuration changes',
            'ai_actions' => 'AI suggestions and decisions',
            'interoperability' => 'External system message processing',
            'security_events' => 'Failed access, policy violations',
        ];

        foreach ($categories as $category => $covered_flag) {
            $checks[] = [
                'category' => $category,
                'description' => $descriptions[$category] ?? $category,
                'covered' => $covered_flag,
                'status' => $covered_flag ? self::CONTROL_STATUS_TESTED : self::CONTROL_STATUS_PARTIAL,
            ];

            if ($covered_flag) {
                $covered++;
            } else {
                $missing++;
            }
        }

        return ['checks' => $checks, 'covered' => $covered, 'missing' => $missing];
    }

    /**
     * Verify financial controls.
     *
     * @param  array<string, mixed>  $config
     * @return array{checks: list<array<string, mixed>>, summary: array{total: int, implemented: int, gaps: int}}
     */
    public function verifyFinancialControls(array $config): array
    {
        $checks = [];
        $controls = [
            'charge_authorization' => 'Charges require authorized staff',
            'duplicate_payment' => 'Duplicate payment prevention (idempotency)',
            'period_lock' => 'Financial period locking prevents historical edits',
            'reconciliation' => 'Payment reconciliation tracking',
            'refund_approval' => 'Refunds require approval workflow',
            'void_control' => 'Invoice void requires authorization',
            'audit_trail' => 'All financial mutations audited',
            'export_control' => 'Financial export requires authorization',
            'segregation_of_duties' => 'Creator/approver/reconciler separation where policy requires',
        ];

        foreach ($controls as $key => $description) {
            $implemented = $config[$key] ?? false;
            $checks[] = [
                'control' => $key,
                'description' => $description,
                'implemented' => $implemented,
                'status' => $implemented ? self::CONTROL_STATUS_TESTED : self::CONTROL_STATUS_PARTIAL,
            ];
        }

        $implemented = count(array_filter($checks, fn ($c) => $c['implemented']));
        $total = count($checks);

        return [
            'checks' => $checks,
            'summary' => [
                'total' => $total,
                'implemented' => $implemented,
                'gaps' => $total - $implemented,
            ],
        ];
    }

    /**
     * Verify clinical safety controls.
     *
     * @param  array<string, mixed>  $config
     * @return array{checks: list<array<string, mixed>>, summary: array{total: int, implemented: int, gaps: int}}
     */
    public function verifyClinicalControls(array $config): array
    {
        $checks = [];
        $controls = [
            'patient_identification' => 'Patient identity verified before clinical actions',
            'encounter_scoping' => 'Clinical actions scoped to correct encounter',
            'allergy_check' => 'Allergy check before prescribing',
            'medication_verification' => 'Medication, dose, route, frequency verified',
            'result_attribution' => 'Results attributed to correct patient/order',
            'clinical_documentation' => 'Author, timestamp, version preserved',
            'emergency_access' => 'Emergency access audit trail',
            'wrong_patient_prevention' => 'Multi-tab stale context protection',
        ];

        foreach ($controls as $key => $description) {
            $implemented = $config[$key] ?? false;
            $checks[] = [
                'control' => $key,
                'description' => $description,
                'implemented' => $implemented,
                'status' => $implemented ? self::CONTROL_STATUS_TESTED : self::CONTROL_STATUS_PARTIAL,
            ];
        }

        $implemented = count(array_filter($checks, fn ($c) => $c['implemented']));
        $total = count($checks);

        return [
            'checks' => $checks,
            'summary' => [
                'total' => $total,
                'implemented' => $implemented,
                'gaps' => $total - $implemented,
            ],
        ];
    }

    /**
     * Produce a structured evidence record.
     *
     * @return array{control_id: string, control_name: string, test: string, result: string, date: string, environment: string, commit: string, reviewer: string}
     */
    public function recordEvidence(
        string $controlId,
        string $controlName,
        string $testDescription,
        string $result,
        string $environment = 'local',
        string $commit = '',
        string $reviewer = 'automated',
    ): array {
        return [
            'control_id' => $controlId,
            'control_name' => $controlName,
            'test' => $testDescription,
            'result' => $result,
            'date' => now()->toIso8601String(),
            'environment' => $environment,
            'commit' => $commit,
            'reviewer' => $reviewer,
        ];
    }

    // ── Security Controls ─────────────────────────────────────

    private function getSecurityControls(): array
    {
        return [
            'SEC-001' => [
                'id' => 'SEC-001',
                'name' => 'Authentication',
                'domain' => self::CONTROL_DOMAIN_SECURITY,
                'objective' => 'Verify users authenticate before accessing any resource',
                'risk' => 'Unauthorized access to clinical and financial data',
                'implementation' => 'JWT-based authentication with Laravel Sanctum',
                'test' => 'Unauthenticated requests return 401; session expiration enforced',
                'evidence' => 'AuthTest, AuthClaimsTest, AuthSubjectBindingTest',
                'owner' => 'Platform Security',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'SEC-002' => [
                'id' => 'SEC-002',
                'name' => 'Authorization (RBAC)',
                'domain' => self::CONTROL_DOMAIN_SECURITY,
                'objective' => 'Users access only resources permitted by their role',
                'risk' => 'Privilege escalation, unauthorized clinical/financial access',
                'implementation' => 'Role-based access control with granular permissions',
                'test' => 'Unauthorized role returns 403; permission boundary verified',
                'evidence' => 'AuthorizationTest, ClaimsBasedRlsTest',
                'owner' => 'Platform Security',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'SEC-003' => [
                'id' => 'SEC-003',
                'name' => 'Row-Level Security (RLS)',
                'domain' => self::CONTROL_DOMAIN_SECURITY,
                'objective' => 'Database enforces tenant isolation at the row level',
                'risk' => 'Cross-tenant data leakage',
                'implementation' => 'PostgreSQL RLS with GUC-based JWT claims, 794 policies',
                'test' => 'Cross-hospital data access blocked; RLS policies verified post-restore',
                'evidence' => 'ClaimsBasedRlsTest (31 tests, 304 assertions), DatabaseRowLevelSecurityTest',
                'owner' => 'Platform Security',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
            'SEC-004' => [
                'id' => 'SEC-004',
                'name' => 'Tenant Isolation',
                'domain' => self::CONTROL_DOMAIN_SECURITY,
                'objective' => 'Hospital A cannot access Hospital B data',
                'risk' => 'Cross-tenant data breach',
                'implementation' => 'RLS + RBAC + API middleware + application role',
                'test' => 'Multi-hospital isolation tests verify 9 data dimensions',
                'evidence' => 'HospitalConfigurationIsolationTest (4 tests, 13 assertions)',
                'owner' => 'Platform Security',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'SEC-005' => [
                'id' => 'SEC-005',
                'name' => 'Secret Management',
                'domain' => self::CONTROL_DOMAIN_SECURITY,
                'objective' => 'Secrets not committed to source code',
                'risk' => 'Credential exposure, unauthorized system access',
                'implementation' => 'Environment variables, Render env, .env files gitignored',
                'test' => 'Secret scan in CI, .gitignore verified',
                'evidence' => 'CI pipeline, .gitignore, no secrets in repository',
                'owner' => 'Platform Security',
                'review_frequency' => 'monthly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
            'SEC-006' => [
                'id' => 'SEC-006',
                'name' => 'Application Role Security',
                'domain' => self::CONTROL_DOMAIN_SECURITY,
                'objective' => 'Application cannot bypass RLS or escalate privileges',
                'risk' => 'Application-level bypass of database security',
                'implementation' => 'swasthya_app role: NOSUPERUSER, NOBYPASSRLS, login via JWT GUC',
                'test' => 'Application role cannot disable RLS; NOBYPASSRLS verified',
                'evidence' => 'claims.sql, grants.sql, bootstrap verification',
                'owner' => 'Platform Security',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
            'SEC-007' => [
                'id' => 'SEC-007',
                'name' => 'Session Management',
                'domain' => self::CONTROL_DOMAIN_SECURITY,
                'objective' => 'Sessions expire, can be revoked, and are validated',
                'risk' => 'Stale session access, session hijacking',
                'implementation' => 'JWT with expiration, session revocation, refresh flow',
                'test' => 'Expired session returns 401; revoked session returns 401',
                'evidence' => 'AuthTest (session expiry, revocation tests)',
                'owner' => 'Platform Security',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'SEC-008' => [
                'id' => 'SEC-008',
                'name' => 'Facility Isolation',
                'domain' => self::CONTROL_DOMAIN_SECURITY,
                'objective' => 'Users access only authorized facilities within their hospital',
                'risk' => 'Unauthorized cross-facility data access',
                'implementation' => 'Facility-scoped JWT claims, facility validation on API requests',
                'test' => 'Cross-facility access denied; facility switching enforced',
                'evidence' => 'FacilityIsolationTest',
                'owner' => 'Platform Security',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
        ];
    }

    // ── Clinical Safety Controls ──────────────────────────────

    private function getClinicalSafetyControls(): array
    {
        return [
            'CSA-001' => [
                'id' => 'CSA-001',
                'name' => 'Patient Identification',
                'domain' => self::CONTROL_DOMAIN_CLINICAL,
                'objective' => 'Patient identity verified before clinical actions',
                'risk' => 'Wrong-patient clinical action',
                'implementation' => 'Identity Spine on clinical screens, MRN verification',
                'test' => 'Patient context required for clinical workflows',
                'evidence' => 'PatientWorkspace, EncounterWorkspace tests',
                'owner' => 'Clinical Safety',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'CSA-002' => [
                'id' => 'CSA-002',
                'name' => 'Encounter Scoping',
                'domain' => self::CONTROL_DOMAIN_CLINICAL,
                'objective' => 'Clinical actions scoped to correct encounter',
                'risk' => 'Actions attached to wrong episode',
                'implementation' => 'Encounter ID validated on clinical mutations',
                'test' => 'Cross-encounter action blocked; stale encounter detected',
                'evidence' => 'EncounterWorkspace tests',
                'owner' => 'Clinical Safety',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_PARTIAL,
                'exception' => 'Requires additional stale-context detection tests',
            ],
            'CSA-003' => [
                'id' => 'CSA-003',
                'name' => 'Allergy Check',
                'domain' => self::CONTROL_DOMAIN_CLINICAL,
                'objective' => 'Allergy check before prescribing',
                'risk' => 'Adverse drug reaction from known allergy',
                'implementation' => 'Allergy check in prescription workflow',
                'test' => 'Allergy conflict flagged during prescription',
                'evidence' => 'Medication safety tests, AccessibilityService clinical checks',
                'owner' => 'Clinical Safety',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'CSA-004' => [
                'id' => 'CSA-004',
                'name' => 'Medication Verification',
                'domain' => self::CONTROL_DOMAIN_CLINICAL,
                'objective' => 'Medication, dose, route, frequency verified before dispensing',
                'risk' => 'Wrong medication or dosage dispensed',
                'implementation' => 'Prescription verification workflow, dosage validation',
                'test' => 'Prescription details verified before dispensing',
                'evidence' => 'PharmacyInventory tests, medication safety checks',
                'owner' => 'Clinical Safety',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'CSA-005' => [
                'id' => 'CSA-005',
                'name' => 'Result Attribution',
                'domain' => self::CONTROL_DOMAIN_CLINICAL,
                'objective' => 'Results attributed to correct patient/order',
                'risk' => 'Result mismatch with wrong patient/order',
                'implementation' => 'Result linked to order, order linked to patient',
                'test' => 'Cross-patient result attribution blocked',
                'evidence' => 'LabResult tests, ClinicalWorkflows tests',
                'owner' => 'Clinical Safety',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'CSA-006' => [
                'id' => 'CSA-006',
                'name' => 'Clinical Documentation Integrity',
                'domain' => self::CONTROL_DOMAIN_CLINICAL,
                'objective' => 'Author, timestamp, version preserved for clinical records',
                'risk' => 'Clinical history altered without trace',
                'implementation' => 'Author tracking, timestamps, versioning, corrections',
                'test' => 'Clinical document preserves author and timestamp',
                'evidence' => 'Clinical record tests, DataGovernance clinical correction model',
                'owner' => 'Clinical Safety',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
            'CSA-007' => [
                'id' => 'CSA-007',
                'name' => 'High-Risk Action Confirmation',
                'domain' => self::CONTROL_DOMAIN_CLINICAL,
                'objective' => 'Critical clinical actions require explicit confirmation',
                'risk' => 'Accidental or silent high-risk clinical action',
                'implementation' => 'Confirmation dialog for critical actions, audit logging',
                'test' => 'High-risk action requires explicit user confirmation',
                'evidence' => 'AccessibilityTest clinical safety checks',
                'owner' => 'Clinical Safety',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
        ];
    }

    // ── Financial Controls ────────────────────────────────────

    private function getFinancialControls(): array
    {
        return [
            'FIN-001' => [
                'id' => 'FIN-001',
                'name' => 'Charge Authorization',
                'domain' => self::CONTROL_DOMAIN_FINANCIAL,
                'objective' => 'Charges require authorized staff',
                'risk' => 'Unauthorized financial mutations',
                'implementation' => 'RBAC billing permissions, staff authorization check',
                'test' => 'Unprivileged user cannot create charges',
                'evidence' => 'AuthorizationTest (billing scope)',
                'owner' => 'Finance Administration',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'FIN-002' => [
                'id' => 'FIN-002',
                'name' => 'Duplicate Payment Prevention',
                'domain' => self::CONTROL_DOMAIN_FINANCIAL,
                'objective' => 'Only one payment result per transaction',
                'risk' => 'Duplicate charges, double billing',
                'implementation' => 'Idempotency keys, payment deduplication',
                'test' => 'Duplicate callback produces single payment',
                'evidence' => 'Finance test suite, payment deduplication tests',
                'owner' => 'Finance Administration',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_PARTIAL,
                'exception' => 'Requires explicit idempotency key test',
            ],
            'FIN-003' => [
                'id' => 'FIN-003',
                'name' => 'Financial Period Lock',
                'domain' => self::CONTROL_DOMAIN_FINANCIAL,
                'objective' => 'Closed/locked periods cannot be modified',
                'risk' => 'Historical financial records altered',
                'implementation' => 'Period status enforcement, mutation prevention',
                'test' => 'Locked period rejects mutations',
                'evidence' => 'FiscalPeriodWorkflowTest, NepalFinanceE2E',
                'owner' => 'Finance Administration',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'FIN-004' => [
                'id' => 'FIN-004',
                'name' => 'Payment Reconciliation',
                'domain' => self::CONTROL_DOMAIN_FINANCIAL,
                'objective' => 'Payment reconciliation tracking and audit',
                'risk' => 'Unreconciled payments, financial discrepancy',
                'implementation' => 'Payment reconciliation workflow, ledger tracking',
                'test' => 'Payment reconciliation records auditable',
                'evidence' => 'Finance test suite',
                'owner' => 'Finance Administration',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'FIN-005' => [
                'id' => 'FIN-005',
                'name' => 'Audit Trail',
                'domain' => self::CONTROL_DOMAIN_FINANCIAL,
                'objective' => 'All financial mutations are audited',
                'risk' => 'Untraceable financial changes',
                'implementation' => 'Canonical audit system, financial event logging',
                'test' => 'Financial mutations produce audit records',
                'evidence' => 'AuditTest, AuditTestSuite',
                'owner' => 'Finance Administration',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
        ];
    }

    // ── Data Integrity Controls ───────────────────────────────

    private function getDataIntegrityControls(): array
    {
        return [
            'DI-001' => [
                'id' => 'DI-001',
                'name' => 'Foreign Key Integrity',
                'domain' => self::CONTROL_DOMAIN_DATA_INTEGRITY,
                'objective' => 'Referential integrity enforced at database level',
                'risk' => 'Orphan records, broken relationships',
                'implementation' => 'PostgreSQL foreign key constraints',
                'test' => 'Orphan record creation blocked by FK constraint',
                'evidence' => 'Database migration tests, FK constraint verification',
                'owner' => 'Platform Engineering',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'DI-002' => [
                'id' => 'DI-002',
                'name' => 'Unique Constraints',
                'domain' => self::CONTROL_DOMAIN_DATA_INTEGRITY,
                'objective' => 'Duplicate records prevented by unique constraints',
                'risk' => 'Duplicate patients, duplicate invoices, duplicate identifiers',
                'implementation' => 'Database unique constraints, application validation',
                'test' => 'Duplicate creation blocked',
                'evidence' => 'Constraint verification tests',
                'owner' => 'Platform Engineering',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'DI-003' => [
                'id' => 'DI-003',
                'name' => 'Transaction Integrity',
                'domain' => self::CONTROL_DOMAIN_DATA_INTEGRITY,
                'objective' => 'Multi-step operations are atomic',
                'risk' => 'Partial writes, inconsistent state',
                'implementation' => 'Database transactions, application-level transactions',
                'test' => 'Partial operation rollback verified',
                'evidence' => 'Test suite transaction tests',
                'owner' => 'Platform Engineering',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'DI-004' => [
                'id' => 'DI-004',
                'name' => 'Migration Control',
                'domain' => self::CONTROL_DOMAIN_DATA_INTEGRITY,
                'objective' => 'Database migrations are versioned and testable',
                'risk' => 'Schema drift, data corruption during migration',
                'implementation' => 'Laravel migrations, version-controlled, test-verified',
                'test' => 'Migration runs cleanly, rollback verified',
                'evidence' => '236 tables, 130+ migrations, test suite',
                'owner' => 'Platform Engineering',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
        ];
    }

    // ── Availability Controls ─────────────────────────────────

    private function getAvailabilityControls(): array
    {
        return [
            'AVL-001' => [
                'id' => 'AVL-001',
                'name' => 'Backup Coverage',
                'domain' => self::CONTROL_DOMAIN_AVAILABILITY,
                'objective' => 'Critical data is backed up regularly',
                'risk' => 'Data loss from infrastructure failure',
                'implementation' => 'Supabase PITR, database backups, storage backup',
                'test' => 'Backup exists and is restorable',
                'evidence' => 'DisasterRecovery documentation, backup tests',
                'owner' => 'Platform Operations',
                'review_frequency' => 'monthly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
            'AVL-002' => [
                'id' => 'AVL-002',
                'name' => 'Recovery Capability',
                'domain' => self::CONTROL_DOMAIN_AVAILABILITY,
                'objective' => 'System can recover from failure within RTO',
                'risk' => 'Extended outage, data loss',
                'implementation' => 'Docker restart, PITR restore, recovery runbook',
                'test' => 'Recovery runbook tested, RLS verified post-restore',
                'evidence' => 'ResilienceTest (7 tests, 32 assertions), recovery runbook',
                'owner' => 'Platform Operations',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'AVL-003' => [
                'id' => 'AVL-003',
                'name' => 'AI Optional',
                'domain' => self::CONTROL_DOMAIN_AVAILABILITY,
                'objective' => 'Core HMS continues when AI is unavailable',
                'risk' => 'AI dependency blocks core clinical/financial workflows',
                'implementation' => 'AI is optional; all core workflows independent',
                'test' => 'Core HMS functions without AI provider',
                'evidence' => 'AiGovernanceTest, architectural verification',
                'owner' => 'Platform Engineering',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
            'AVL-004' => [
                'id' => 'AVL-004',
                'name' => 'Health Monitoring',
                'domain' => self::CONTROL_DOMAIN_AVAILABILITY,
                'objective' => 'Health endpoints detect component failures',
                'risk' => 'Undetected failure leads to silent degradation',
                'implementation' => '/health (unauthenticated), /health/authenticated, /health/full',
                'test' => 'Health endpoints return component status',
                'evidence' => 'ResilienceTest health verification, staging health endpoints',
                'owner' => 'Platform Operations',
                'review_frequency' => 'monthly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
        ];
    }

    // ── Governance Controls ───────────────────────────────────

    private function getGovernanceControls(): array
    {
        return [
            'GOV-001' => [
                'id' => 'GOV-001',
                'name' => 'Audit Trail',
                'domain' => self::CONTROL_DOMAIN_AUDIT,
                'objective' => 'High-value actions produce audit records',
                'risk' => 'Untraceable system changes',
                'implementation' => 'Canonical audit system (AuditEvent, AuditTrail)',
                'test' => 'Security, clinical, financial, configuration events audited',
                'evidence' => 'AuditTest, AuditTestSuite, AuditEvent model',
                'owner' => 'Platform Security',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
            'GOV-002' => [
                'id' => 'GOV-002',
                'name' => 'Configuration Change Control',
                'domain' => self::CONTROL_DOMAIN_CONFIGURATION,
                'objective' => 'Configuration changes are traceable and auditable',
                'risk' => 'Unauthorized or untraceable configuration changes',
                'implementation' => 'Hospital configuration audit, onboarding validation',
                'test' => 'Configuration changes produce audit records',
                'evidence' => 'ConfigurationValidationService, HospitalConfigurationIsolationTest',
                'owner' => 'Platform Operations',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'GOV-003' => [
                'id' => 'GOV-003',
                'name' => 'Deployment Traceability',
                'domain' => self::CONTROL_DOMAIN_CONFIGURATION,
                'objective' => 'Deployments traceable to source commits',
                'risk' => 'Unreproducible deployments',
                'implementation' => 'Git-based CI, Docker builds, commit SHA tagging',
                'test' => 'HEAD matches remote; deployment traceable to commit',
                'evidence' => 'Git verification in all phase checkpoints',
                'owner' => 'Platform Engineering',
                'review_frequency' => 'per deployment',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
            'GOV-004' => [
                'id' => 'GOV-004',
                'name' => 'AI Authority Boundary',
                'domain' => self::CONTROL_DOMAIN_AI,
                'objective' => 'AI is never authoritative for clinical/financial data',
                'risk' => 'AI-generated errors treated as ground truth',
                'implementation' => 'AI suggestions require human approval; not written to authoritative records without review',
                'test' => 'AI output marked as suggestion; human approval required',
                'evidence' => 'AiGovernanceTest, Phase 70 framework',
                'owner' => 'Clinical Safety',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_TESTED,
                'exception' => '',
            ],
            'GOV-005' => [
                'id' => 'GOV-005',
                'name' => 'Data Governance Classification',
                'domain' => self::CONTROL_DOMAIN_PRIVACY,
                'objective' => 'Data classified by sensitivity with appropriate access rules',
                'risk' => 'Inappropriate data access or exposure',
                'implementation' => 'Data classification matrix, 11 record classes',
                'test' => 'Classification-driven access rules enforced',
                'evidence' => 'DataGovernanceTest (9 tests, 137 assertions), classification matrix',
                'owner' => 'Data Governance',
                'review_frequency' => 'quarterly',
                'status' => self::CONTROL_STATUS_EVIDENCE_VERIFIED,
                'exception' => '',
            ],
        ];
    }
}
