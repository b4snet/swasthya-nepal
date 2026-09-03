<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\DataGovernanceService;
use App\Services\Export\ArchiveService;
use App\Services\Export\ExportService;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PHASE 91 — Data Governance and Records Lifecycle.
 *
 * Provides endpoints for:
 * - Data classification matrix
 * - Retention eligibility
 * - Tenant offboarding readiness
 * - Export manifest
 * - Tenant data export (portability / offboarding)
 *
 * Read-only governance views require audit:view; export requires data:export.
 */
final class DataGovernanceController extends Controller
{
    public function __construct(
        protected DataGovernanceService $governance,
        protected ExportService $exports,
        protected ArchiveService $archive,
        protected AuditLogger $audit,
    ) {}

    /**
     * GET /governance/classification
     *
     * Returns the data classification matrix for all major record classes.
     */
    public function classification(Request $request): JsonResponse
    {
        return Envelope::success(
            data: ['matrix' => $this->governance->classificationMatrix()],
            request: $request,
        );
    }

    /**
     * GET /governance/retention
     *
     * Returns retention eligibility for a given record class.
     */
    public function retention(Request $request): JsonResponse
    {
        $request->validate([
            'record_class' => 'required|string',
        ]);

        $result = $this->governance->retentionEligibility(
            $request->input('record_class'),
            $request->integer('hospital_retention_years'),
        );

        return Envelope::success(data: $result, request: $request);
    }

    /**
     * GET /governance/offboarding-readiness
     *
     * Returns tenant offboarding readiness for the current organization.
     */
    public function offboardingReadiness(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();

        $result = $this->governance->offboardingReadiness((string) $ctx->tenantId());

        return Envelope::success(
            data: $result,
            status: $result['ready'] ? 200 : 422,
            request: $request,
        );
    }

    /**
     * GET /governance/export-manifest
     *
     * Returns the data export manifest for the current organization.
     */
    public function exportManifest(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();

        $manifest = $this->governance->exportManifest((string) $ctx->tenantId());

        return Envelope::success(data: $manifest, request: $request);
    }

    /**
     * POST /governance/export
     *
     * Build and archive a facts-only data export for the CURRENT tenant
     * (TENANCY.md V2 §15). The tenant is derived from context — never client
     * input — so a caller can only ever export their own tenant. The artifact
     * is written to a tenant-scoped storage path and audited.
     *
     * @throws ApiException
     */
    public function export(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $tenantId = $ctx->tenantId();

        if ($tenantId === null) {
            throw new ApiException(
                ErrorCodes::TENANT_REQUIRED,
                'An organization context is required to export tenant data.',
                403,
            );
        }

        // Authorization is enforced by the route middleware (data:export);
        // re-confirm the scope so the check is explicit and testable.
        if (! $ctx->can('data:export')) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'You are not authorized to export tenant data.',
                403,
            );
        }

        $bundle = $this->exports->bundle($tenantId);
        $artifact = $this->archive->archive($bundle, $tenantId);

        $event = $this->audit->record(
            'data.export',
            'organization',
            $tenantId,
            [
                'export_id' => $artifact['export_id'],
                'total_records' => $bundle['total_records'],
                'format' => 'json',
            ],
            $request,
            tenantId: $tenantId,
        );

        return Envelope::success(
            data: [
                'export_id' => $artifact['export_id'],
                'total_records' => $bundle['total_records'],
                'exported_at' => $bundle['exported_at'],
                'tenant_id' => $tenantId,
                'path' => $artifact['path'],
            ],
            status: 201,
            request: $request,
            headers: ['X-Audit-Event-Id' => (string) $event->getKey()],
        );
    }
}
