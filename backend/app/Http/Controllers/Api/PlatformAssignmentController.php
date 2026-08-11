<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\GrantPlatformRoleRequest;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-scope role assignments (TENANCY.md V2 §8): managing the platform
 * administration team. Platform context only — tenant roles are granted
 * through the organization endpoints by tenant administrators. Granting a
 * platform role never touches tenant data; every grant/revoke is audited.
 */
final class PlatformAssignmentController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function grant(GrantPlatformRoleRequest $request, User $user): JsonResponse
    {
        $context = TenantContext::current();

        if (! $context->isPlatform) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'Platform role assignments require platform context.',
                403,
            );
        }

        /** @var Role|null $role */
        $role = Role::query()->where('code', $request->validated('roleCode'))->first();

        if ($role === null || $role->scope_type !== Role::SCOPE_PLATFORM) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'Only platform roles can be granted through this endpoint.',
                422,
            );
        }

        $existing = RoleAssignment::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->where('role_id', $role->getKey())
            ->whereNull('tenant_id')
            ->exists();

        if ($existing) {
            throw new ApiException(
                ErrorCodes::RESOURCE_EXISTS,
                'This user already holds this platform role.',
                409,
            );
        }

        $assignment = RoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
            'tenant_id' => null,
            'facility_id' => null,
            'branch_id' => null,
            'scope_type' => Role::SCOPE_PLATFORM,
            'status' => RoleAssignment::STATUS_ACTIVE,
            'granted_by' => $context->user?->getKey(),
            'granted_at' => now(),
        ]);

        $event = $this->audit->record(
            'role_assignment.granted',
            'role_assignment',
            $assignment->getKey(),
            ['userId' => $user->getKey(), 'roleCode' => $role->code, 'scope' => 'platform'],
            $request,
        );

        return Envelope::success(
            data: [
                'id' => $assignment->getKey(),
                'userId' => $user->getKey(),
                'roleCode' => $role->code,
                'status' => $assignment->status,
                'grantedAt' => $assignment->granted_at?->toIso8601String(),
            ],
            status: 201,
            request: $request,
            headers: ['X-Audit-Event-Id' => (string) $event->getKey()],
        );
    }

    public function revoke(Request $request, User $user, RoleAssignment $assignment): JsonResponse
    {
        $context = TenantContext::current();

        if (! $context->isPlatform) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'Platform role assignments require platform context.',
                403,
            );
        }

        if ($assignment->user_id !== $user->getKey() || $assignment->tenant_id !== null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Assignment not found.', 404);
        }

        if ($assignment->status !== RoleAssignment::STATUS_ACTIVE) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This assignment is not active.', 409);
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
            ['userId' => $user->getKey(), 'roleId' => $assignment->role_id, 'scope' => 'platform'],
            $request,
        );

        return response()->json(null, 204, ['X-Audit-Event-Id' => (string) $event->getKey()]);
    }
}
