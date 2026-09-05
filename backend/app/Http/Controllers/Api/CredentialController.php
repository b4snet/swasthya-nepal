<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\StaffCredential;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff credential management (prompt §24-27). Provides CRUD for
 * professional credentials (licenses, registrations, certifications)
 * with verification workflow. Tenant+facility scoped via RLS.
 */
class CredentialController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $facilityId = TenantContext::current()->facilityId();
        if ($facilityId === null) {
            throw new ApiException(ErrorCodes::FACILITY_DENIED, 'A facility context is required.', 403);
        }

        $query = StaffCredential::query()
            ->where('tenant_id', TenantContext::current()->tenantId())
            ->where('facility_id', $facilityId);

        if ($staffId = $request->input('staff_id')) {
            $query->where('staff_id', $staffId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->input('credential_type')) {
            $query->where('credential_type', $type);
        }

        $credentials = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($credentials);
    }

    public function store(Request $request): JsonResponse
    {
        $facilityId = TenantContext::current()->facilityId();
        if ($facilityId === null) {
            throw new ApiException(ErrorCodes::FACILITY_DENIED, 'A facility context is required.', 403);
        }

        $validated = $request->validate([
            'staff_id' => 'required|uuid',
            'credential_type' => 'required|string|max:50',
            'credential_code' => 'nullable|string|max:100',
            'title' => 'required|string|max:255',
            'issuing_authority' => 'nullable|string|max:255',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:issue_date',
            'document_id' => 'nullable|uuid',
        ]);

        $credential = StaffCredential::query()->create([
            'tenant_id' => TenantContext::current()->tenantId(),
            'facility_id' => $facilityId,
            'staff_id' => $validated['staff_id'],
            'credential_type' => $validated['credential_type'],
            'credential_code' => $validated['credential_code'] ?? null,
            'title' => $validated['title'],
            'issuing_authority' => $validated['issuing_authority'] ?? null,
            'issue_date' => $validated['issue_date'] ?? null,
            'expiry_date' => $validated['expiry_date'] ?? null,
            'status' => StaffCredential::STATUS_ACTIVE,
        ]);

        $this->audit->record('staff_credential.create', 'staff_credential', $credential->getKey(), [
            'staff_id' => $validated['staff_id'],
            'credential_type' => $validated['credential_type'],
            'title' => $validated['title'],
        ]);

        return response()->json($credential, 201);
    }

    public function show(Request $request, StaffCredential $credential): JsonResponse
    {
        AccessCheck::scoped($credential, write: false);

        return response()->json($credential);
    }

    public function update(Request $request, StaffCredential $credential): JsonResponse
    {
        AccessCheck::scoped($credential, write: true);

        $validated = $request->validate([
            'credential_code' => 'nullable|string|max:100',
            'title' => 'sometimes|string|max:255',
            'issuing_authority' => 'nullable|string|max:255',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'status' => 'sometimes|string|in:active,expiring,expired,revoked',
        ]);

        $credential->update($validated);

        $this->audit->record('staff_credential.update', 'staff_credential', $credential->getKey(), [
            'status' => $credential->status,
        ]);

        return response()->json($credential);
    }

    public function verify(Request $request, StaffCredential $credential): JsonResponse
    {
        AccessCheck::scoped($credential, write: true);

        $credential->update([
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'status' => StaffCredential::STATUS_ACTIVE,
        ]);

        $this->audit->record('staff_credential.verify', 'staff_credential', $credential->getKey(), [
            'credential_type' => $credential->credential_type,
        ]);

        return response()->json($credential);
    }

    public function expiring(Request $request): JsonResponse
    {
        $facilityId = TenantContext::current()->facilityId();
        if ($facilityId === null) {
            throw new ApiException(ErrorCodes::FACILITY_DENIED, 'A facility context is required.', 403);
        }

        $days = $request->integer('days', 30);
        $threshold = now()->addDays($days);

        $credentials = StaffCredential::query()
            ->where('tenant_id', TenantContext::current()->tenantId())
            ->where('facility_id', $facilityId)
            ->where('status', StaffCredential::STATUS_ACTIVE)
            ->where('expiry_date', '<=', $threshold)
            ->where('expiry_date', '>=', now())
            ->orderBy('expiry_date')
            ->get();

        return response()->json($credentials);
    }
}
