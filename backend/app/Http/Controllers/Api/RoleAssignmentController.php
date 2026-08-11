<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\GrantRoleAssignmentRequest;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Role assignments — the highest-value authorization audit (DATABASE.md §3.7).
 *
 * Grant and revoke are status transitions on role_assignments; revoked rows
 * persist as history (there is no delete path). Both are audited with actor,
 * scope, and timestamp. Role changes take effect IMMEDIATELY: permission
 * checks resolve live from assignments on every request (MASTER_RULES.md
 * §9.4, SECURITY.md §6).
 */
final class RoleAssignmentController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function grant(GrantRoleAssignmentRequest $request, Organization $organization, User $user): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();

        // Tenant-scoped grants are never performed from an empty platform
        // context (TENANCY.md V2 §8): provisioning / support sessions are
        // the only platform route into a tenant.
        if ($context->isPlatform) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'Platform administrators must use provisioning or a support session for tenant actions.',
                403,
            );
        }
        $role = Role::query()->where('code', $request->validated('roleCode'))->first();

        if ($role === null) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The role does not exist.', 422);
        }

        if ($role->scope_type === Role::SCOPE_PLATFORM) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Platform roles cannot be granted through an organization.', 422);
        }

        $facility = null;
        $facilityId = $request->validated('facilityId');

        if ($role->scope_type === Role::SCOPE_FACILITY) {
            if ($facilityId === null) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'A facility is required for this role.', 422);
            }

            $facility = $organization->facilities()->find($facilityId);

            if ($facility === null) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The facility does not belong to this organization.', 422);
            }
        } elseif ($facilityId !== null) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Organization-scoped roles cannot carry a facility.', 422);
        }

        $existing = RoleAssignment::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->where('role_id', $role->getKey())
            ->where('tenant_id', $organization->getKey())
            ->where('facility_id', $facility?->getKey())
            ->exists();

        if ($existing) {
            throw new ApiException(
                ErrorCodes::RESOURCE_EXISTS,
                'This user already holds this role in the given scope.',
                409,
            );
        }

        $assignment = RoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facility?->getKey(),
            'scope_type' => $role->scope_type,
            'status' => RoleAssignment::STATUS_ACTIVE,
            'granted_by' => $context->user?->getKey(),
            'granted_at' => now(),
        ]);

        $event = $this->audit->record(
            'role_assignment.granted',
            'role_assignment',
            $assignment->getKey(),
            [
                'userId' => $user->getKey(),
                'roleCode' => $role->code,
                'facilityId' => $facility?->getKey(),
            ],
            $request,
        );

        return Envelope::success(
            data: [
                'id' => $assignment->getKey(),
                'userId' => $user->getKey(),
                'roleCode' => $role->code,
                'organizationId' => $organization->getKey(),
                'facilityId' => $facility?->getKey(),
                'status' => $assignment->status,
                'grantedAt' => $assignment->granted_at?->toIso8601String(),
            ],
            status: 201,
            request: $request,
            headers: ['X-Audit-Event-Id' => (string) $event->getKey()],
        );
    }

    public function revoke(Request $request, Organization $organization, User $user, RoleAssignment $assignment): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();

        if ($context->isPlatform) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'Platform administrators must use provisioning or a support session for tenant actions.',
                403,
            );
        }

        if ($assignment->user_id !== $user->getKey() || $assignment->tenant_id !== $organization->getKey()) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Assignment not found.', 404);
        }

        if ($assignment->status !== RoleAssignment::STATUS_ACTIVE) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'This assignment is not active.',
                409,
            );
        }

        $assignment->update([
            'status' => RoleAssignment::STATUS_REVOKED,
            'revoked_by' => $context->user?->getKey(),
            'revoked_at' => now(),
        ]);

        $event = $this->audit->record(
            'role_assignment.revoked',
            'role_assignment',
            $assignment->getKey(),
            ['userId' => $user->getKey(), 'roleId' => $assignment->role_id],
            $request,
        );

        return response()->json(null, 204, [
            'X-Audit-Event-Id' => (string) $event->getKey(),
        ]);
    }
}
