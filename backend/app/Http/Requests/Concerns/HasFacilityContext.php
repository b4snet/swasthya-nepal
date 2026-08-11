<?php

namespace App\Http\Requests\Concerns;

use App\Models\Facility;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Shared validation for facility-scoped writes (Phase 4 catalogs).
 *
 * A facility-scoped principal (hospital_admin) is bound to their context
 * facility — the body may not propose another one. Org/platform principals
 * name the facility, which the controller resolves and scope-checks.
 */
trait HasFacilityContext
{
    /**
     * @return array<int, mixed>
     */
    protected function facilityIdRules(): array
    {
        $context = TenantContext::current();

        return [
            'nullable',
            'uuid',
            function (string $attribute, mixed $value, callable $fail) use ($context): void {
                if ($context->facilityId() !== null && $value !== null && $value !== $context->facilityId()) {
                    $fail('This record is bound to your facility context and cannot be created in another facility.');
                }
            },
            function (string $attribute, mixed $value, callable $fail) use ($context): void {
                // The proposed facility must belong to the caller's tenant —
                // a cross-tenant facility is a request-shape error (422),
                // not merely an authorization failure.
                if ($value === null || $context->isPlatform) {
                    return;
                }

                $exists = DB::table('facilities')
                    ->where('tenant_id', $context->tenantId())
                    ->where('id', $value)
                    ->exists();

                if (! $exists) {
                    $fail('The selected facility does not belong to your organization.');
                }
            },
        ];
    }
}
