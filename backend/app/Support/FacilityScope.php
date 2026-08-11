<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Facility;

/**
 * Facility resolution for facility-scoped writes (Phase 4 catalogs).
 *
 * A facility-scoped principal (e.g. hospital_admin) writes inside their
 * context facility — the body may not propose another one. An org-scoped or
 * platform principal must name the facility, and it is validated against the
 * organization scope (TENANCY.md §7): the facility must belong to the
 * caller's tenant or the write is denied.
 */
final class FacilityScope
{
    public static function resolve(?string $proposedFacilityId, bool $write): Facility
    {
        $context = TenantContext::current();

        // Facility-scoped principal: their facility IS the scope.
        if ($context->facilityId() !== null) {
            return Facility::query()->findOrFail($context->facilityId());
        }

        // Platform or org principal: the proposed facility must be inside
        // the tenant (AccessCheck::facility enforces tenant + facility scope).
        if ($proposedFacilityId === null || $proposedFacilityId === '') {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'facilityId is required to create a facility-scoped record.',
                422,
            );
        }

        return AccessCheck::facility($proposedFacilityId, $write);
    }

    /**
     * Optional branch assignment for facility catalog resources (TENANCY.md
     * V2 §4): the branch must belong to the record's (tenant, facility) — a
     * branch from another facility or tenant is a request-shape error (422),
     * never a silently accepted foreign reference.
     */
    public static function resolveBranch(?string $branchId, string $tenantId, string $facilityId): ?string
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        $exists = Branch::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('id', $branchId)
            ->exists();

        if (! $exists) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'The selected branch does not belong to the target facility.',
                422,
            );
        }

        return $branchId;
    }
}
