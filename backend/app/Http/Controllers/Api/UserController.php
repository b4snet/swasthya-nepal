<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
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
use Illuminate\Support\Facades\DB;

/**
 * Users within a tenant (API_CONTRACTS.md §21.4).
 *
 * users are global identities; a user "belongs" to a tenant through active
 * role assignments. Listing is tenant-scoped; creation is an org-admin
 * action that creates the identity AND its first assignment atomically.
 */
final class UserController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $tenantId = $context->tenantId();

        $users = RoleAssignment::query()
            ->active()
            ->where('tenant_id', $tenantId)
            ->with(['user', 'role', 'organization', 'facility'])
            ->orderBy('granted_at')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($assignments, string $userId): array => [
                'id' => $userId,
                'email' => $assignments->first()->user?->email,
                'status' => $assignments->first()->user?->status,
                'assignments' => $assignments->map(fn (RoleAssignment $assignment): array => [
                    'organizationId' => $assignment->tenant_id,
                    'facilityId' => $assignment->facility_id,
                    'role' => $assignment->role?->code,
                    'status' => $assignment->status,
                ])->values(),
            ])
            ->values();

        return Envelope::success(data: $users, request: $request);
    }

    public function store(StoreUserRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();

        // Tenant writes are never performed from an empty platform context:
        // the platform administrator must use provisioning or a support
        // session (TENANCY.md V2 §8).
        if ($context->isPlatform) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'Platform administrators must use provisioning or a support session for tenant actions.',
                403,
            );
        }
        $role = $this->resolveRoleForTenant((string) $request->validated('roleCode'), $organization, $request);

        $user = DB::transaction(function () use ($request, $organization, $role, $context): User {
            $user = User::query()->create([
                'email' => strtolower(trim((string) $request->validated('email'))),
                'password_hash' => $request->validated('password'),
                'status' => User::STATUS_ACTIVE,
            ]);

            $this->grantAssignment($user, $role, $organization, $request->validated('facilityId'), $context->user?->getKey());

            return $user;
        });

        // Actor is the creating admin (context user); the created user is the resource.
        $event = $this->audit->record(
            'user.created',
            'user',
            $user->getKey(),
            ['organizationId' => $organization->getKey(), 'roleCode' => $role->code],
            $request,
        );

        return Envelope::success(
            data: [
                'id' => $user->getKey(),
                'email' => $user->email,
                'status' => $user->status,
                'assignments' => $this->assignmentsPayload($user),
            ],
            status: 201,
            request: $request,
            headers: ['X-Audit-Event-Id' => (string) $event->getKey()],
        );
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return Envelope::success(
            data: [
                'id' => $user?->getKey(),
                'email' => $user?->email,
                'status' => $user?->status,
                'assignments' => $this->assignmentsPayload($user),
            ],
            request: $request,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignmentsPayload(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $groups = [];

        foreach ($user->roleAssignments()->active()->with(['role', 'organization', 'facility'])->get() as $assignment) {
            $key = ($assignment->tenant_id ?? 'platform').'|'.($assignment->facility_id ?? '');

            $groups[$key] ??= [
                'organizationId' => $assignment->organization?->getKey(),
                'organizationCode' => $assignment->organization?->code,
                'facilityId' => $assignment->facility_id,
                'facilityName' => $assignment->facility?->name,
                'roles' => [],
            ];

            $groups[$key]['roles'][] = $assignment->role?->code;
        }

        return array_values($groups);
    }

    private function grantAssignment(User $user, Role $role, Organization $organization, mixed $facilityId, ?string $grantedBy): RoleAssignment
    {
        $facility = null;

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

        return RoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facility?->getKey(),
            'scope_type' => $role->scope_type,
            'status' => RoleAssignment::STATUS_ACTIVE,
            'granted_by' => $grantedBy,
            'granted_at' => now(),
        ]);
    }

    private function resolveRoleForTenant(string $roleCode, Organization $organization, Request $request): Role
    {
        $role = Role::query()->where('code', $roleCode)->first();

        if ($role === null) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The role does not exist.', 422);
        }

        if ($role->scope_type === Role::SCOPE_PLATFORM) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Platform roles cannot be granted through an organization.', 422);
        }

        return $role;
    }
}
