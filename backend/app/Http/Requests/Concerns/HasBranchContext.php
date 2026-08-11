<?php

namespace App\Http\Requests\Concerns;

use App\Models\Branch;
use App\Support\TenantContext;

/**
 * Shared validation for the optional branch assignment on facility catalog
 * resources (departments, locations, wards, rooms, beds — TENANCY.md V2 §4).
 *
 * A proposed branch must belong to the (tenant, facility) the record is
 * being created/updated in — a branch from another facility or tenant is a
 * request-shape error (422). The facility is the context facility when the
 * principal is facility-scoped, otherwise the facilityId in the body; for
 * nested resources (rooms under a ward, beds under a room) the parent's
 * facility is passed explicitly.
 */
trait HasBranchContext
{
    /**
     * @param  mixed  $facilityId  explicit parent facility (nested resources)
     * @return array<int, mixed>
     */
    protected function branchIdRules(mixed $facilityId = null): array
    {
        $context = TenantContext::current();

        return [
            'nullable',
            'uuid',
            function (string $attribute, mixed $value, callable $fail) use ($context, $facilityId): void {
                if ($value === null || $context->isPlatform) {
                    return;
                }

                $targetFacilityId = $facilityId
                    ?? $context->facilityId()
                    ?? $this->input('facilityId');

                $exists = Branch::query()
                    ->where('tenant_id', $context->tenantId())
                    ->where('facility_id', $targetFacilityId)
                    ->where('id', $value)
                    ->exists();

                if (! $exists) {
                    $fail('The selected branch does not belong to the target facility.');
                }
            },
        ];
    }
}
