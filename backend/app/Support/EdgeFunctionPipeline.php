<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase 4 — Edge Function pipeline (local contract harness).
 *
 * This class executes the EDGE FUNCTION SECURITY CONTRACT against the real
 * database, mirroring the pure TypeScript pipeline in
 * supabase/functions/_shared (health_auth.ts / context.ts). It is the
 * DB-coupled half of the local proof; the TS modules are the deployable
 * artifact, executed and tested by the Node harness.
 *
 *   Bearer GoTrue JWT
 *     → JwtClaims::verify            (signature, expiry, issuer/audience)
 *     → users.auth_subject_id = sub  (application identity)
 *     → status gate                  (pending/locked/disabled → 403)
 *     → server-side context          (platform / support / tenant; facility
 *                                     & branch proposals validated; suspended
 *                                     organization → 403)
 *     → AuthClaims::fromContext      (the five authoritative RLS claims)
 *
 * Client-supplied tenant/facility/branch/platform values NEVER become
 * claims — the payload's app_* claims are ignored entirely; only `sub` is
 * read from the token. The caller writes the returned claims into
 * `request.jwt.claims` (DatabaseTenantContext::setClaims) exactly the way the
 * deployed function sets the GUC on its least-privilege connection.
 *
 * This is a deliberate migration-path mirror of ResolveTenantContext — it is
 * NOT wired into any route or controller yet (Phase 4 keeps Sanctum live).
 */
final class EdgeFunctionPipeline
{
    /**
     * Resolve a GoTrue-shaped access token into the authoritative context.
     *
     * @param  array{facilityId?: string|null, branchId?: string|null}  $proposals
     * @return array{0: User, 1: TenantContext, 2: array<string, string>} user, context, claims
     *
     * @throws ApiException INVALID_TOKEN / TOKEN_EXPIRED / FORBIDDEN /
     *                      TENANT_SUSPENDED / FACILITY_DENIED / BRANCH_DENIED
     */
    public static function resolve(string $bearer, array $proposals = [], bool $isPlatformRoute = false): array
    {
        // 1–2. Verify the JWT and extract the GoTrue subject. The token is
        // locally minted by JwtClaims (the GoTrue-shaped stand-in); in
        // deployment the same contract is enforced by verifyJwt() in the
        // Edge Function with the real SUPABASE_JWT_SECRET.
        $payload = JwtClaims::verify($bearer);
        $sub = is_string($payload['sub'] ?? null) ? $payload['sub'] : null;

        // 3. Map the subject to the application account (users.auth_subject_id).
        $user = $sub !== null && $sub !== ''
            ? User::query()->where('auth_subject_id', $sub)->first()
            : null;

        if ($user === null) {
            throw new ApiException(
                ErrorCodes::INVALID_TOKEN,
                'The identity is not linked to an application account.',
                401,
            );
        }

        // 4. Status gate — locked/disabled/pending identities are refused
        // before any context is resolved.
        if ($user->status !== User::STATUS_ACTIVE) {
            throw new ApiException(ErrorCodes::FORBIDDEN, 'This account is not active.', 403);
        }

        // 5. Server-side context resolution (mirror of ResolveTenantContext).
        $context = self::resolveContext($user, $proposals, $isPlatformRoute);

        // 6. The five authoritative claims.
        $claims = AuthClaims::fromContext($context);

        return [$user, $context, $claims];
    }

    /**
     * The decision tree, reproduced from the middleware so the edge path and
     * the HTTP path cannot drift silently (both are covered by tests).
     *
     * @param  array{facilityId?: string|null, branchId?: string|null}  $proposals
     */
    private static function resolveContext(User $user, array $proposals, bool $isPlatformRoute): TenantContext
    {
        /** @var Collection<int, RoleAssignment> $assignments */
        $assignments = $user->roleAssignments()->active()->get();
        $assignments->load('role');

        $platformAssignment = $assignments->first(
            static fn (RoleAssignment $assignment): bool => $assignment->role?->scope_type === Role::SCOPE_PLATFORM
        );

        if ($platformAssignment !== null) {
            if ($isPlatformRoute) {
                $assignments->load('role.permissions');

                return new TenantContext($user, true, null, null, $assignments);
            }

            /** @var SupportSession|null $session */
            $session = SupportSession::query()
                ->where('user_id', $user->getKey())
                ->active()
                ->orderByDesc('opened_at')
                ->first();

            if ($session === null) {
                $assignments->load('role.permissions');

                return new TenantContext($user, true, null, null, $assignments);
            }

            $organization = Organization::query()->find($session->organization_id);
            if ($organization === null || $organization->status !== Organization::STATUS_ACTIVE) {
                throw new ApiException(
                    ErrorCodes::TENANT_SUSPENDED,
                    'This organization is not active. Contact your administrator.',
                    403,
                );
            }
            $facility = $session->facility_id !== null
                ? Facility::query()->find($session->facility_id)
                : null;

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

            return new TenantContext(
                $user,
                false,
                $organization,
                $facility,
                collect([$supportAssignment]),
                supportSessionId: $session->getKey(),
            );
        }

        if ($assignments->isEmpty()) {
            throw new ApiException(
                ErrorCodes::FORBIDDEN,
                'No active role assignments — you have no access to any organization.',
                403,
            );
        }

        $proposedFacilityId = $proposals['facilityId'] ?? null;

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
            $default = $assignments->first();
            $organizationId = $default?->tenant_id;
            $facilityId = $default?->facility_id;
        }

        $organization = $organizationId !== null ? Organization::query()->find($organizationId) : null;

        if ($organization === null || $organization->status !== Organization::STATUS_ACTIVE) {
            throw new ApiException(
                ErrorCodes::TENANT_SUSPENDED,
                'This organization is not active. Contact your administrator.',
                403,
            );
        }

        $facility = $facilityId !== null ? Facility::query()->find($facilityId) : null;

        $branch = self::resolveBranchProposal($proposals['branchId'] ?? null, $organization, $facility);

        $assignments->load(['role.permissions', 'organization', 'facility']);

        return new TenantContext(
            $user,
            false,
            $organization,
            $facility,
            $assignments,
            branch: $branch,
        );
    }

    private static function resolveBranchProposal(?string $proposedBranchId, Organization $organization, ?Facility $facility): ?Branch
    {
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

        return $branch;
    }
}
