<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataGovernanceService;
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
 *
 * All endpoints require admin:manage authorization.
 */
final class DataGovernanceController extends Controller
{
    public function __construct(
        protected DataGovernanceService $governance,
    ) {}

    /**
     * GET /governance/classification
     *
     * Returns the data classification matrix for all major record classes.
     */
    public function classification(Request $request): JsonResponse
    {
        return response()->json([
            'matrix' => $this->governance->classificationMatrix(),
        ]);
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

        return response()->json($result);
    }

    /**
     * GET /governance/offboarding-readiness
     *
     * Returns tenant offboarding readiness for the current organization.
     */
    public function offboardingReadiness(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();

        $result = $this->governance->offboardingReadiness($ctx->tenantId());

        return response()->json($result, $result['ready'] ? 200 : 422);
    }

    /**
     * GET /governance/export-manifest
     *
     * Returns the data export manifest for the current organization.
     */
    public function exportManifest(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();

        $manifest = $this->governance->exportManifest($ctx->tenantId());

        return response()->json($manifest);
    }
}
