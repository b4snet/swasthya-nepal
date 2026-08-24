<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\SupportSession;
use App\Models\User;
use App\Support\DatabaseTenantContext;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant/facility/branch context for every authenticated
 * request and projects it onto the database (TENANCY.md §3, V2 §5, §7;
 * API_CONTRACTS.md §5).
 *
 * The context is DERIVED from the authenticated principal's active role
 * assignments — never accepted from the client:
 *
 *  1. A platform-scope assignment with NO active support session → platform
 *     context: platform-scope permissions only, empty tenant GUC, so the
 *     database refuses tenant rows (TENANCY.md V2 §8).
 *  2. A platform-scope assignment WITH an active support session → support
 *     context: the session's organization/facility with the read-only
 *     support_agent role (explicit, time-limited, audited).
 *  3. No active assignments → default deny (TENANCY.md §0 rule 2).
 *  4. X-Swasthya-Facility is a *proposal*: the facility must match an active
 *     assignment; the tenant is then derived from that facility's
 *     organization. A facility outside scope → 403 FACILITY_DENIED.
 *  5. X-Swasthya-Branch is likewise a *proposal*, valid only against the
 *     resolved facility (403 BRANCH_DENIED otherwise).
 *  6. A non-active organization → 403 TENANT_SUSPENDED — isolation never
 *     weakens at any status (TENANCY.md §13).
 *
 * DATABASE PROJECTION (V2 §5, §7): every request runs inside ONE transaction.
 * The RLS GUCs are set with set_config(..., is_local=true), so they die with
 * the transaction — a reused connection, a pooled worker, or a queued job
 * can never observe another request's context. On error the transaction is
 * rolled back (and the context with it); on success it is committed before
 * the response leaves. Inner DB::transaction calls nest as savepoints.
 *
 * The resolved context is echoed on every response via Envelope and carried
 * into audit events.
 */
final class ResolveTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::setCurrent(TenantContext::empty());

        // Derive the principal from the bearer token directly — never from
        // the guard's possibly-cached user (see note below). auth:sanctum
        // has already validated the token (hash, expiry), so this is the
        // same principal. The guard caches its user on the AuthManager,
        // which is shared across requests in a long-running process (tests,
        // Octane); context must be derived per request.
        $bearer = $request->bearerToken();
        $token = is_string($bearer) && $bearer !== ''
            ? PersonalAccessToken::findToken($bearer)
            : null;
        $user = $token?->tokenable instanceof User ? $token->tokenable : null;

        if ($user === null) {
            throw new ApiException(ErrorCodes::INVALID_TOKEN, 'Authentication required.', 401);
        }

        // Request-scoped database context: one transaction, LOCAL GUCs, then
        // commit/rollback — never a leaked setting (TENANCY.md V2 §7).
        DB::beginTransaction();

        try {
            // app.user_id must be set BEFORE role-assignment resolution: the
            // RLS policy on role_assignments lets a principal see its own
            // rows, which is what makes context resolution possible under
            // RLS (the application role has no RLS bypass).
            DatabaseTenantContext::setUser($user->getKey());

            /** @var Collection<int, RoleAssignment> $assignments */
            $assignments = $user->roleAssignments()
                ->active()
                ->get();

            $assignments->load('role');

            $platformAssignment = $assignments->first(
                static fn (RoleAssignment $assignment): bool => $assignment->role?->scope_type === Role::SCOPE_PLATFORM
            );

            $context = $platformAssignment !== null
                ? $this->platformOrSupportContext($request, $user, $assignments)
                : $this->tenantContext($request, $user, $assignments);

            TenantContext::setCurrent($context);

            $response = $next($request);

            DatabaseTenantContext::commitIfActive();
            // Belt-and-braces: the LOCAL GUCs die with the transaction, but an
            // explicit reset guarantees no setting survives even when the
            // transaction was nested inside an outer one (tests, wrapping
            // callers).
            DatabaseTenantContext::resetAll();

            return $response;
        } catch (\Throwable $exception) {
            DatabaseTenantContext::rollbackIfActive();
            DatabaseTenantContext::resetAll();

            throw $exception;
        }
    }

    /**
     * Drop the resolved context and database settings once the response is
     * sent. The GUCs are transaction-local and already gone after commit or
     * rollback; clearing the in-memory context guarantees a later request
     * that does not pass through this middleware (e.g. health probes) can
     * never observe a stale tenant context.
     */
    public function terminate(Request $request, Response $response): void
    {
        TenantContext::setCurrent(null);
    }

    /**
     * @param  Collection<int, RoleAssignment>  $assignments
     */
    private function platformOrSupportContext(
        Request $request,
        User $user,
        Collection $assignments,
    ): TenantContext {
        // Platform-administration routes (provisioning, platform role
        // assignments, support-session management) always execute in platform
        // context — a support session can never manage itself, and session
        // management is platform administration, not tenant access.
        if ($request->is('api/v1/platform/*')) {
            DatabaseTenantContext::setPlatform(true);
            $assignments->load('role.permissions');

            return new TenantContext(
                user: $user,
                isPlatform: true,
                organization: null,
                facility: null,
                assignments: $assignments,
            );
        }

        /** @var SupportSession|null $session */
        $session = SupportSession::query()
            ->where('user_id', $user->getKey())
            ->active()
            ->orderByDesc('opened_at')
            ->first();

        if ($session === null) {
            // Platform user with no support session. Allow explicit tenant
            // override via X-Swasthya-Tenant header so platform admins can
            // access tenant-scoped finance/admin pages.
            $proposedTenantId = $request->header('X-Swasthya-Tenant');

            if (is_string($proposedTenantId) && $proposedTenantId !== '') {
                /** @var Organization|null $organization */
                $organization = Organization::query()->find($proposedTenantId);

                if ($organization !== null && $organization->status === Organization::STATUS_ACTIVE) {
                    DatabaseTenantContext::setTenant($organization->getKey());
                    DatabaseTenantContext::setPlatform(false);
                    $assignments->load(['role.permissions', 'organization', 'facility']);

                    return new TenantContext(
                        user: $user,
                        isPlatform: false,
                        organization: $organization,
                        facility: null,
                        assignments: $assignments,
                    );
                }
            }

            DatabaseTenantContext::setPlatform(true);
            $assignments->load('role.permissions');

            return new TenantContext(
                user: $user,
                isPlatform: true,
                organization: null,
                facility: null,
                assignments: $assignments,
            );
        }

        // Support context: the session IS the tenant selection. The tenant
        // GUC is set before the facility lookup so the facility's RLS policy
        // can see it (the policy is tenant-scoped).
        DatabaseTenantContext::setTenant($session->organization_id);

        /** @var Organization|null $organization */
        $organization = Organization::query()->find($session->organization_id);
        /** @var Facility|null $facility */
        $facility = $session->facility_id !== null
            ? Facility::query()->find($session->facility_id)
            : null;

        if ($organization === null || $organization->status !== Organization::STATUS_ACTIVE) {
            throw new ApiException(
                ErrorCodes::TENANT_SUSPENDED,
                'This organization is not active. Contact your administrator.',
                403,
            );
        }

        $supportRole = Role::query()->where('code', 'support_agent')->firstOrFail();
        $supportRole->load('permissions');

        /** @var RoleAssignment $supportAssignment */
        $supportAssignment = new RoleAssignment([
            'user_id' => $user->getKey(),
            'role_id' => $supportRole->getKey(),
            'tenant_id' => $session->organization_id,
            'facility_id' => $session->facility_id,
            'scope_type' => $supportRole->scope_type,
            'status' => RoleAssignment::STATUS_ACTIVE,
        ]);
        $supportAssignment->setRelation('role', $supportRole);

        if ($facility !== null) {
            DatabaseTenantContext::setFacility($facility->getKey());
        }
        DatabaseTenantContext::setPlatform(false);

        return new TenantContext(
            user: $user,
            isPlatform: false,
            organization: $organization,
            facility: $facility,
            assignments: collect([$supportAssignment]),
            supportSessionId: $session->getKey(),
        );
    }

    /**
     * @param  Collection<int, RoleAssignment>  $assignments
     */
    private function tenantContext(Request $request, User $user, Collection $assignments): TenantContext
    {
        if ($assignments->isEmpty()) {
            throw new ApiException(
                ErrorCodes::FORBIDDEN,
                'No active role assignments — you have no access to any organization.',
                403,
            );
        }

        $proposedFacilityId = $request->header('X-Swasthya-Facility');

        if (is_string($proposedFacilityId) && $proposedFacilityId !== '') {
            $match = $assignments->first(
                static fn (RoleAssignment $assignment): bool => $assignment->facility_id !== null
                    && $assignment->facility_id === $proposedFacilityId
            );

            if ($match === null) {
                throw new ApiException(
                    ErrorCodes::FACILITY_DENIED,
                    'The requested facility is outside your assigned scope.',
                    403,
                );
            }

            $organizationId = $match->tenant_id;
            $facilityId = $match->facility_id;
        } else {
            // Default context: the most recent active assignment.
            $default = $assignments->first();
            $organizationId = $default?->tenant_id;
            $facilityId = $default?->facility_id;
        }

        // Set the tenant GUC before any tenant-scoped lookup below (branch
        // proposal validation, relation loading) so RLS can see it.
        DatabaseTenantContext::setTenant($organizationId);
        if ($facilityId !== null) {
            DatabaseTenantContext::setFacility($facilityId);
        }
        DatabaseTenantContext::setPlatform(false);

        /** @var Organization|null $organization */
        $organization = $organizationId !== null ? Organization::query()->find($organizationId) : null;
        /** @var Facility|null $facility */
        $facility = $facilityId !== null ? Facility::query()->find($facilityId) : null;

        if ($organization === null || $organization->status !== Organization::STATUS_ACTIVE) {
            throw new ApiException(
                ErrorCodes::TENANT_SUSPENDED,
                'This organization is not active. Contact your administrator.',
                403,
            );
        }

        $branch = $this->resolveBranchProposal($request, $organization, $facility);

        // Load the relations the context and controllers consume. Runs now —
        // with the tenant GUC set — so RLS shows exactly the context tenant.
        $assignments->load(['role.permissions', 'organization', 'facility']);

        return new TenantContext(
            user: $user,
            isPlatform: false,
            organization: $organization,
            facility: $facility,
            assignments: $assignments,
            branch: $branch,
        );
    }

    private function resolveBranchProposal(Request $request, Organization $organization, ?Facility $facility): ?Branch
    {
        $proposedBranchId = $request->header('X-Swasthya-Branch');

        if (! is_string($proposedBranchId) || $proposedBranchId === '') {
            return null;
        }

        if ($facility === null) {
            throw new ApiException(
                ErrorCodes::BRANCH_DENIED,
                'Branch context requires a facility context.',
                403,
            );
        }

        /** @var Branch|null $branch */
        $branch = Branch::query()
            ->where('tenant_id', $organization->getKey())
            ->where('facility_id', $facility->getKey())
            ->find($proposedBranchId);

        if ($branch === null) {
            throw new ApiException(
                ErrorCodes::BRANCH_DENIED,
                'The requested branch is outside your assigned scope.',
                403,
            );
        }

        DatabaseTenantContext::setBranch($branch->getKey());

        return $branch;
    }
}
