<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DisclosureLog;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PHASE 91 — Data Governance and Records Lifecycle.
 *
 * Provides:
 * - Data classification for major record classes
 * - Retention eligibility assessment
 * - Correction workflow initiation
 * - Export authorization checks
 * - Disclosure logging
 * - Tenant offboarding readiness
 */
final class DataGovernanceService
{
    /**
     * Data classification matrix for major record classes.
     *
     * @return array<string, array{
     *     classification: string,
     *     owner: string,
     *     retention_years: int|null,
     *     correction_method: string,
     *     exportable: bool,
     *     auditable: bool,
     *     description: string
     * }>
     */
    public function classificationMatrix(): array
    {
        return [
            'patient_identity' => [
                'classification' => 'CONFIDENTIAL_PHI',
                'owner' => 'Patient / Hospital',
                'retention_years' => null, // Requires hospital/legal policy
                'correction_method' => 'amendment_with_audit',
                'exportable' => true,
                'auditable' => true,
                'description' => 'Patient demographics, identifiers, contact information',
            ],
            'clinical_record' => [
                'classification' => 'CONFIDENTIAL_PHI',
                'owner' => 'Hospital / Clinician',
                'retention_years' => null, // Requires hospital/legal policy
                'correction_method' => 'amendment_with_audit',
                'exportable' => true,
                'auditable' => true,
                'description' => 'Encounters, diagnoses, clinical notes, assessments',
            ],
            'medication' => [
                'classification' => 'CONFIDENTIAL_PHI',
                'owner' => 'Hospital / Prescriber',
                'retention_years' => null, // Requires hospital/legal policy
                'correction_method' => 'amendment_with_audit',
                'exportable' => true,
                'auditable' => true,
                'description' => 'Prescriptions, dispensing, MAR entries',
            ],
            'diagnostics' => [
                'classification' => 'CONFIDENTIAL_PHI',
                'owner' => 'Hospital / Ordering Clinician',
                'retention_years' => null,
                'correction_method' => 'supersession',
                'exportable' => true,
                'auditable' => true,
                'description' => 'Lab orders, results, radiology, specimens',
            ],
            'documents' => [
                'classification' => 'CONFIDENTIAL_PHI',
                'owner' => 'Hospital / Author',
                'retention_years' => null,
                'correction_method' => 'version_supersession',
                'exportable' => true,
                'auditable' => true,
                'description' => 'Patient documents, clinical reports, generated documents',
            ],
            'finance' => [
                'classification' => 'FINANCIAL',
                'owner' => 'Hospital / Finance',
                'retention_years' => null, // Requires hospital/legal policy
                'correction_method' => 'reversal_adjustment',
                'exportable' => true,
                'auditable' => true,
                'description' => 'Charges, invoices, payments, refunds, claims',
            ],
            'staff' => [
                'classification' => 'CONFIDENTIAL',
                'owner' => 'Hospital / HR',
                'retention_years' => null,
                'correction_method' => 'amendment_with_audit',
                'exportable' => true, // HR export only
                'auditable' => true,
                'description' => 'Staff profiles, credentials, assignments, schedules',
            ],
            'security' => [
                'classification' => 'SECURITY_SENSITIVE',
                'owner' => 'Platform / Hospital Admin',
                'retention_years' => null,
                'correction_method' => 'append_only',
                'exportable' => false,
                'auditable' => true,
                'description' => 'Auth events, access logs, MFA challenges, support sessions',
            ],
            'audit' => [
                'classification' => 'SECURITY_SENSITIVE',
                'owner' => 'Platform',
                'retention_years' => null,
                'correction_method' => 'append_only',
                'exportable' => false,
                'auditable' => false, // Audit itself is the audit trail
                'description' => 'Audit events, domain events, compliance reports',
            ],
            'ai' => [
                'classification' => 'CONFIDENTIAL',
                'owner' => 'Hospital / Platform',
                'retention_years' => null,
                'correction_method' => 'deletion_on_request',
                'exportable' => true,
                'auditable' => true,
                'description' => 'AI drafts, suggestions, CDSS results',
            ],
            'configuration' => [
                'classification' => 'INTERNAL',
                'owner' => 'Hospital Admin',
                'retention_years' => null,
                'correction_method' => 'versioned_update',
                'exportable' => true,
                'auditable' => true,
                'description' => 'Hospital, facility, department, service, role configuration',
            ],
        ];
    }

    /**
     * Determine retention eligibility for a given record class.
     *
     * @param  string  $recordClass  The record class identifier
     * @param  int|null  $hospitalRetentionYears  Hospital-specific retention override
     * @return array{eligible: bool, classification: string, retention_years: int|null, requires_policy: bool, reason: string}
     */
    public function retentionEligibility(string $recordClass, ?int $hospitalRetentionYears = null): array
    {
        $matrix = $this->classificationMatrix();

        if (! isset($matrix[$recordClass])) {
            return [
                'eligible' => false,
                'classification' => 'UNKNOWN',
                'retention_years' => null,
                'requires_policy' => true,
                'reason' => "Record class '{$recordClass}' not defined in governance matrix",
            ];
        }

        $policy = $matrix[$recordClass];
        $retentionYears = $hospitalRetentionYears ?? $policy['retention_years'];

        return [
            'eligible' => $retentionYears !== null,
            'classification' => $policy['classification'],
            'retention_years' => $retentionYears,
            'requires_policy' => $retentionYears === null,
            'reason' => $retentionYears === null
                ? 'Hospital/legal retention policy required before automated retention'
                : "Records eligible for retention after {$retentionYears} years",
        ];
    }

    /**
     * Initiate a data correction workflow.
     *
     * Records the correction request, its authorization, and creates an audit trail.
     * The original record is NOT modified — a correction entry is appended.
     *
     * @param  string  $recordType  The type of record being corrected
     * @param  string  $recordId  The ID of the record being corrected
     * @param  array{original: array, corrected: array, reason: string, authorized_by: string}  $correction
     * @return array{correction_id: string, status: string, audit_id: string}
     */
    public function initiateCorrection(string $recordType, string $recordId, array $correction): array
    {
        $correctionId = DB::table('audit_events')->insertGetId([
            'id' => Str::uuid7()->toString(),
            'event_type' => 'data.correction.requested',
            'aggregate_type' => $recordType,
            'aggregate_id' => $recordId,
            'payload' => json_encode([
                'original' => $correction['original'],
                'proposed' => $correction['corrected'],
                'reason' => $correction['reason'],
                'authorized_by' => $correction['authorized_by'],
                'status' => 'pending_review',
            ]),
            'causer_type' => 'App\\Models\\User',
            'causer_id' => $correction['authorized_by'],
            'idempotency_key' => 'correction-'.Str::uuid7(),
            'status' => 'pending',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return [
            'correction_id' => $correctionId,
            'status' => 'pending_review',
            'audit_id' => (string) $correctionId,
        ];
    }

    /**
     * Check whether a data export is authorized for the given user and scope.
     *
     * @param  string  $userId  The user requesting export
     * @param  string  $scope  The export scope (e.g., 'patient', 'financial', 'bulk')
     * @return array{authorized: bool, reason: string}
     */
    public function authorizeExport(string $userId, string $scope): array
    {
        // Export authorization is scope-dependent:
        // - patient export: requires patient:view or patient:export
        // - financial export: requires billing:export
        // - bulk export: requires admin:export (platform or hospital admin)
        // - configuration export: requires admin:manage
        // - clinical export: requires clinical:export

        $requiredPermissions = match ($scope) {
            'patient' => ['patient:export', 'patient:view'],
            'financial' => ['billing:export'],
            'bulk' => ['admin:export'],
            'clinical' => ['clinical:export'],
            'configuration' => ['admin:manage'],
            default => ['admin:export'],
        };

        // Authorization check is delegated to AccessCheck in the controller layer
        // Here we just return the required permissions for the scope
        return [
            'authorized' => true, // Placeholder — actual check done at controller via AccessCheck
            'reason' => 'Export requires: '.implode(' or ', $requiredPermissions),
            'required_permissions' => $requiredPermissions,
        ];
    }

    /**
     * Log a data disclosure (external release of patient information).
     *
     * @param  array{patient_id: string, disclosed_to: string, purpose: string, scope: string, authorized_by: string}  $disclosure
     * @return string The disclosure log ID
     */
    public function logDisclosure(array $disclosure): string
    {
        return DisclosureLog::insertGetId([
            'id' => Str::uuid7()->toString(),
            'patient_id' => $disclosure['patient_id'],
            'disclosed_to' => $disclosure['disclosed_to'],
            'purpose' => $disclosure['purpose'],
            'scope' => $disclosure['scope'],
            'authorized_by' => $disclosure['authorized_by'],
            'disclosed_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Check tenant offboarding readiness.
     *
     * Before a hospital can be offboarded, certain conditions must be met.
     *
     * @param  string  $organizationId  The hospital to check
     * @return array{ready: bool, blockers: list<string>, data_summary: array}
     */
    public function offboardingReadiness(string $organizationId): array
    {
        $blockers = [];

        // Check for active patients
        $patientCount = Patient::where('tenant_id', $organizationId)->count();
        if ($patientCount > 0) {
            $blockers[] = "{$patientCount} patient record(s) exist. Export or transfer before offboarding.";
        }

        // Check for open encounters
        $openEncounters = DB::table('encounters')
            ->where('tenant_id', $organizationId)
            ->where('status', 'open')
            ->count();
        if ($openEncounters > 0) {
            $blockers[] = "{$openEncounters} open encounter(s) must be completed or closed.";
        }

        // Check for pending financial transactions
        $pendingInvoices = DB::table('invoices')
            ->where('tenant_id', $organizationId)
            ->whereNotIn('status', ['paid', 'voided', 'written_off'])
            ->count();
        if ($pendingInvoices > 0) {
            $blockers[] = "{$pendingInvoices} pending invoice(s) must be resolved.";
        }

        // Check for active integrations
        $activeIntegrations = DB::table('integrations')
            ->where('tenant_id', $organizationId)
            ->where('status', 'active')
            ->count();
        if ($activeIntegrations > 0) {
            $blockers[] = "{$activeIntegrations} active integration(s) must be deactivated.";
        }

        return [
            'ready' => empty($blockers),
            'blockers' => $blockers,
            'data_summary' => [
                'patients' => $patientCount,
                'open_encounters' => $openEncounters,
                'pending_invoices' => $pendingInvoices,
                'active_integrations' => $activeIntegrations,
            ],
        ];
    }

    /**
     * Generate a data export manifest for a hospital.
     *
     * @param  string  $organizationId  The hospital to export
     * @return array{exportable_categories: list<array{category: string, record_count: int, classification: string, requires_authorization: bool}>}
     */
    public function exportManifest(string $organizationId): array
    {
        $categories = [];
        $tables = [
            'patients' => 'patient_identity',
            'encounters' => 'clinical_record',
            'lab_orders' => 'diagnostics',
            'prescriptions' => 'medication',
            'invoices' => 'finance',
            'payments' => 'finance',
            'staff' => 'staff',
        ];

        foreach ($tables as $table => $classification) {
            $count = DB::table($table)
                ->where('tenant_id', $organizationId)
                ->count();

            $matrix = $this->classificationMatrix();
            $policy = $matrix[$classification] ?? null;

            $categories[] = [
                'category' => $table,
                'record_count' => $count,
                'classification' => $policy['classification'] ?? 'UNKNOWN',
                'requires_authorization' => in_array($policy['classification'] ?? '', ['CONFIDENTIAL_PHI', 'FINANCIAL']),
            ];
        }

        return ['exportable_categories' => $categories];
    }
}
