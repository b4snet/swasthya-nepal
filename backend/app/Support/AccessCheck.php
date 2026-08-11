<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;

/**
 * Resource access by ownership/scope, never by untrusted ID alone
 * (MASTER_RULES.md §8.3, API_CONTRACTS.md §4).
 *
 * Visibility rule: a resource that exists but is outside the caller's scope
 * returns 404 for READS (existence is never leaked) and 403 for WRITES.
 */
final class AccessCheck
{
    public static function organization(?string $organizationId, bool $write): Organization
    {
        $context = TenantContext::current();

        $organization = $organizationId !== null
            ? Organization::query()->find($organizationId)
            : null;

        if ($organization === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Organization not found.', 404);
        }

        if ($context->isPlatform) {
            return $organization;
        }

        $inScope = $context->assignments->contains(
            static fn ($assignment): bool => $assignment->tenant_id === $organization->getKey()
        );

        if (! $inScope) {
            self::deny($write);
        }

        return $organization;
    }

    public static function facility(?string $facilityId, bool $write): Facility
    {
        $context = TenantContext::current();

        $facility = $facilityId !== null
            ? Facility::query()->find($facilityId)
            : null;

        if ($facility === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Facility not found.', 404);
        }

        if ($context->isPlatform) {
            return $facility;
        }

        // Out of tenant → invisible (404 on reads, 403 on writes).
        if ($facility->tenant_id !== $context->tenantId()) {
            self::deny($write);
        }

        // Facility-scoped principals may only see their own facility; an
        // org-level context (facility null) may see all facilities of the org.
        if ($context->facilityId() !== null && $facility->getKey() !== $context->facilityId()) {
            self::deny($write);
        }

        return $facility;
    }

    /**
     * Generic tenant/facility-scope check for any tenant-scoped model
     * carrying tenant_id and facility_id (Phase 4 catalogs: departments,
     * locations, wards, rooms, beds, staff, services).
     *
     *  - out of tenant            → 404 reads / 403 writes (existence hidden)
     *  - out of the caller's
     *    facility scope           → same
     *  - platform context         → any row
     */
    public static function scoped(Model $model, bool $write): Model
    {
        $context = TenantContext::current();

        if ($context->isPlatform) {
            return $model;
        }

        $modelTenantId = $model->getAttribute('tenant_id');

        if (is_string($modelTenantId) && $modelTenantId !== $context->tenantId()) {
            self::deny($write);
        }

        $modelFacilityId = $model->getAttribute('facility_id');

        if ($context->facilityId() !== null && $modelFacilityId !== $context->facilityId()) {
            self::deny($write);
        }

        return $model;
    }

    /**
     * A staff record within the given organization, scoped by facility.
     */
    public static function staff(?string $staffId, ?string $tenantId, bool $write): Model
    {
        $context = TenantContext::current();

        $staff = $staffId !== null
            ? Staff::query()->find($staffId)
            : null;

        if ($staff === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Staff record not found.', 404);
        }

        if ($context->isPlatform) {
            return $staff;
        }

        if ($tenantId !== null && $staff->tenant_id !== $tenantId) {
            self::deny($write);
        }

        if ($context->facilityId() !== null && $staff->facility_id !== $context->facilityId()) {
            self::deny($write);
        }

        return $staff;
    }

    /**
     * Scope check for a patient child (identifiers, contacts, policies,
     * consents, documents, timeline): these rows carry tenant_id but not
     * facility_id — the effective scope is their PARENT patient's facility.
     */
    public static function patientChild(Model $child, bool $write): Model
    {
        $patientId = $child->getAttribute('patient_id');
        $patient = is_string($patientId)
            ? Patient::query()->find($patientId)
            : null;

        if ($patient === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Resource not found.', 404);
        }

        return self::scoped($patient, $write);
    }

    private static function deny(bool $write): never
    {
        throw new ApiException(
            $write ? ErrorCodes::SCOPE_DENIED : ErrorCodes::NOT_FOUND,
            $write ? 'You are not authorized to perform this action.' : 'Resource not found.',
            $write ? 403 : 404,
        );
    }
}
