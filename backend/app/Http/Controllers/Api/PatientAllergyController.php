<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Patient allergy management — CRUD for structured allergy records.
 * Allergies are safety-critical data: creation and modification require
 * clinical authorization; resolution preserves history.
 */
final class PatientAllergyController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /patients/{patient}/allergies — list active and resolved allergies.
     */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $allergies = PatientAllergy::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('created_at')
            ->get();

        return Envelope::success(data: $allergies, request: $request);
    }

    /**
     * POST /patients/{patient}/allergies — record a new allergy.
     */
    public function store(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $data = $request->validate([
            'allergen' => 'required|string|max:255',
            'allergenClass' => 'nullable|string|max:100',
            'severity' => 'required|in:mild,moderate,severe',
            'reaction' => 'nullable|string|max:1000',
        ]);

        $context = TenantContext::current();

        $allergy = PatientAllergy::query()->create([
            'tenant_id' => $patient->tenant_id,
            'facility_id' => $patient->facility_id,
            'patient_id' => $patient->getKey(),
            'allergen' => $data['allergen'],
            'allergen_class' => $data['allergenClass'] ?? null,
            'severity' => $data['severity'],
            'reaction' => $data['reaction'] ?? null,
            'status' => PatientAllergy::STATUS_ACTIVE,
            'lock_version' => 0,
            'recorded_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'allergy.recorded',
            'patient_allergy',
            $allergy->getKey(),
            ['patientId' => $patient->getKey()],
            $request,
        );

        return Envelope::success(data: $allergy, status: 201, request: $request);
    }

    /**
     * POST /patients/{patient}/allergies/{allergy}/resolve — mark an active allergy as resolved.
     */
    public function resolve(Request $request, Patient $patient, PatientAllergy $allergy): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        if ($allergy->patient_id !== $patient->getKey()) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Allergy not found for this patient.', 404);
        }

        if ($allergy->status !== PatientAllergy::STATUS_ACTIVE) {
            throw new \App\Exceptions\ApiException(ErrorCodes::CONFLICT, 'Only an active allergy can be resolved.', 409);
        }

        $updated = DB::table('patient_allergies')
            ->where('id', $allergy->getKey())
            ->where('lock_version', $allergy->lock_version)
            ->where('status', PatientAllergy::STATUS_ACTIVE)
            ->update([
                'status' => PatientAllergy::STATUS_RESOLVED,
                'lock_version' => $allergy->lock_version + 1,
            ]);

        if ($updated !== 1) {
            throw new \App\Exceptions\ApiException(ErrorCodes::CONFLICT, 'This allergy was concurrently modified; refresh and retry.', 409);
        }

        $this->audit->record(
            'allergy.resolved',
            'patient_allergy',
            $allergy->getKey(),
            ['patientId' => $patient->getKey()],
            $request,
        );

        return Envelope::success(data: $allergy->fresh(), request: $request);
    }
}
