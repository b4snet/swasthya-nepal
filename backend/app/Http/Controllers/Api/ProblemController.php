<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Problem;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Patient problem list — longitudinal conditions persisting across encounters.
 * CRUD for active/resolved/ruled-out problems. Each problem is attributable
 * to the recording clinician and the originating encounter.
 */
final class ProblemController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /patients/{patient}/problems — list active problems for a patient.
     */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $status = $request->input('status', Problem::STATUS_ACTIVE);

        $problems = Problem::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->getKey())
            ->where('status', $status)
            ->orderByDesc('onset_date')
            ->get();

        return Envelope::success(data: $problems, request: $request);
    }

    /**
     * POST /patients/{patient}/problems — add a new problem to the list.
     */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $data = $request->validate([
            'code' => 'nullable|string|max:50',
            'codingSystem' => 'nullable|string|max:50',
            'description' => 'required|string|max:255',
            'clinicalDescription' => 'nullable|string|max:2000',
            'onsetDate' => 'nullable|date',
            'encounterId' => 'nullable|uuid',
        ]);

        $context = TenantContext::current();

        $problem = Problem::query()->create([
            'tenant_id' => $patient->tenant_id,
            'facility_id' => $patient->facility_id,
            'patient_id' => $patient->getKey(),
            'code' => $data['code'] ?? null,
            'coding_system' => $data['codingSystem'] ?? null,
            'description' => $data['description'],
            'clinical_description' => $data['clinicalDescription'] ?? null,
            'status' => Problem::STATUS_ACTIVE,
            'onset_date' => $data['onsetDate'] ?? null,
            'encounter_id' => $data['encounterId'] ?? null,
            'recorded_by' => $context->user?->getKey(),
            'lock_version' => 0,
        ]);

        $this->audit->record(
            'problem.added',
            'problem',
            $problem->getKey(),
            ['patientId' => $patient->getKey()],
            $request,
        );

        return Envelope::success(data: $problem, status: 201, request: $request);
    }

    /**
     * POST /patients/{patient}/problems/{problem}/resolve — mark a problem as resolved.
     */
    public function resolve(Request $request, Patient $patient, Problem $problem): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        if ($problem->patient_id !== $patient->getKey()) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Problem not found for this patient.', 404);
        }

        if ($problem->status !== Problem::STATUS_ACTIVE) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only an active problem can be resolved.', 409);
        }

        $data = $request->validate([
            'resolvedDate' => 'nullable|date',
            'resolvedReason' => 'nullable|string|max:1000',
        ]);

        $updated = DB::table('problems')
            ->where('id', $problem->getKey())
            ->where('lock_version', $problem->lock_version)
            ->where('status', Problem::STATUS_ACTIVE)
            ->update([
                'status' => Problem::STATUS_RESOLVED,
                'resolved_date' => $data['resolvedDate'] ?? now()->toDateString(),
                'resolved_reason' => $data['resolvedReason'] ?? null,
                'lock_version' => $problem->lock_version + 1,
            ]);

        if ($updated !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This problem was concurrently modified; refresh and retry.', 409);
        }

        $this->audit->record(
            'problem.resolved',
            'problem',
            $problem->getKey(),
            ['patientId' => $patient->getKey()],
            $request,
        );

        return Envelope::success(data: $problem->fresh(), request: $request);
    }

    /**
     * POST /patients/{patient}/problems/{problem}/rule-out — mark a problem as ruled out.
     */
    public function ruleOut(Request $request, Patient $patient, Problem $problem): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        if ($problem->patient_id !== $patient->getKey()) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Problem not found for this patient.', 404);
        }

        if ($problem->status !== Problem::STATUS_ACTIVE) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only an active problem can be ruled out.', 409);
        }

        $data = $request->validate([
            'resolvedReason' => 'nullable|string|max:1000',
        ]);

        $updated = DB::table('problems')
            ->where('id', $problem->getKey())
            ->where('lock_version', $problem->lock_version)
            ->where('status', Problem::STATUS_ACTIVE)
            ->update([
                'status' => Problem::STATUS_RULED_OUT,
                'resolved_date' => now()->toDateString(),
                'resolved_reason' => $data['resolvedReason'] ?? null,
                'lock_version' => $problem->lock_version + 1,
            ]);

        if ($updated !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This problem was concurrently modified; refresh and retry.', 409);
        }

        $this->audit->record(
            'problem.ruled_out',
            'problem',
            $problem->getKey(),
            ['patientId' => $patient->getKey()],
            $request,
        );

        return Envelope::success(data: $problem->fresh(), request: $request);
    }
}
