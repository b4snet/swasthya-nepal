<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\Staff;
use App\Support\AccessCheck;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::current()->tenantId;
        $facilityId = $request->header('X-Swasthya-Facility') ?? $request->header('X-Facility-Id');
        $patientId = $request->query('patient_id');
        $status = $request->query('status');

        $query = Referral::where('tenant_id', $tenantId)
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(['patient:id,full_name,mrn', 'referringStaff:id,full_name', 'receivingStaff:id,full_name'])
            ->orderByDesc('created_at');

        $referrals = $query->paginate($request->query('per_page', 25));

        return response()->json($referrals);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => 'required|uuid',
            'encounter_id' => 'nullable|uuid',
            'receiving_staff_id' => 'nullable|uuid',
            'receiving_facility_name' => 'nullable|string|max:200',
            'receiving_department' => 'nullable|string|max:100',
            'reason' => 'required|string|max:500',
            'clinical_summary' => 'nullable|string',
            'urgency' => 'in:routine,urgent,emergent',
            'specialty' => 'nullable|string|max:100',
        ]);

        $tenantId = TenantContext::current()->tenantId;
        $facilityId = $request->header('X-Swasthya-Facility') ?? $request->header('X-Facility-Id');
        $userId = TenantContext::current()->user?->getKey();
        $staff = Staff::where('user_id', $userId)->where('tenant_id', $tenantId)->first();
        $staffId = $staff?->getKey();

        // Must have either receiving_staff_id or receiving_facility_name
        if (empty($validated['receiving_staff_id']) && empty($validated['receiving_facility_name'])) {
            return response()->json(['message' => 'Either receiving_staff_id or receiving_facility_name is required'], 422);
        }

        // Prevent duplicate pending referrals for same patient + receiving
        $duplicateQuery = Referral::where('tenant_id', $tenantId)
            ->where('patient_id', $validated['patient_id'])
            ->where('status', Referral::STATUS_PENDING);

        if (! empty($validated['receiving_staff_id'])) {
            $duplicateQuery->where('receiving_staff_id', $validated['receiving_staff_id']);
        } else {
            $duplicateQuery->where('receiving_facility_name', $validated['receiving_facility_name']);
        }

        if ($duplicateQuery->exists()) {
            return response()->json(['message' => 'A pending referral for this patient to the same destination already exists'], 409);
        }

        $referral = Referral::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'referring_staff_id' => $staffId,
            'referring_department' => $staff?->department_id,
            'urgency' => $validated['urgency'] ?? Referral::URGENCY_ROUTINE,
            'status' => Referral::STATUS_PENDING,
            'created_by' => $userId,
        ]);

        return response()->json($referral->load(['patient:id,full_name,mrn', 'referringStaff:id,full_name']), 201);
    }

    public function show(Referral $referral): JsonResponse
    {
        AccessCheck::scoped($referral);

        return response()->json($referral->load([
            'patient:id,full_name,mrn,date_of_birth,sex',
            'referringStaff:id,full_name',
            'receivingStaff:id,full_name',
            'scheduledAppointment:id,starts_at,status',
        ]));
    }

    public function accept(Referral $referral): JsonResponse
    {
        AccessCheck::scoped($referral, write: true);

        if (! $referral->canAccept()) {
            return response()->json(['message' => 'Referral cannot be accepted in current status'], 422);
        }

        $referral->update([
            'status' => Referral::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'updated_by' => TenantContext::current()->user?->getKey(),
        ]);

        return response()->json($referral->fresh());
    }

    public function reject(Request $request, Referral $referral): JsonResponse
    {
        AccessCheck::scoped($referral, write: true);

        if (! $referral->canReject()) {
            return response()->json(['message' => 'Referral cannot be rejected in current status'], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $referral->update([
            'status' => Referral::STATUS_REJECTED,
            'rejection_reason' => $validated['rejection_reason'],
            'rejected_at' => now(),
            'updated_by' => TenantContext::current()->user?->getKey(),
        ]);

        return response()->json($referral->fresh());
    }

    public function schedule(Request $request, Referral $referral): JsonResponse
    {
        AccessCheck::scoped($referral, write: true);

        if (! in_array($referral->status, [Referral::STATUS_PENDING, Referral::STATUS_ACCEPTED])) {
            return response()->json(['message' => 'Referral cannot be scheduled in current status'], 422);
        }

        $validated = $request->validate([
            'appointment_id' => 'required|uuid',
        ]);

        $referral->update([
            'status' => Referral::STATUS_SCHEDULED,
            'scheduled_appointment_id' => $validated['appointment_id'],
            'updated_by' => TenantContext::current()->user?->getKey(),
        ]);

        return response()->json($referral->fresh());
    }

    public function complete(Request $request, Referral $referral): JsonResponse
    {
        AccessCheck::scoped($referral, write: true);

        if (! $referral->canComplete()) {
            return response()->json(['message' => 'Referral cannot be completed in current status'], 422);
        }

        $validated = $request->validate([
            'completion_notes' => 'nullable|string|max:1000',
        ]);

        $referral->update([
            'status' => Referral::STATUS_COMPLETED,
            'completion_notes' => $validated['completion_notes'] ?? null,
            'completed_at' => now(),
            'updated_by' => TenantContext::current()->user?->getKey(),
        ]);

        return response()->json($referral->fresh());
    }

    public function cancel(Request $request, Referral $referral): JsonResponse
    {
        AccessCheck::scoped($referral, write: true);

        if (! $referral->canCancel()) {
            return response()->json(['message' => 'Referral cannot be cancelled in current status'], 422);
        }

        $validated = $request->validate([
            'cancellation_reason' => 'nullable|string|max:500',
        ]);

        $referral->update([
            'status' => Referral::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'updated_by' => TenantContext::current()->user?->getKey(),
        ]);

        return response()->json($referral->fresh());
    }
}
