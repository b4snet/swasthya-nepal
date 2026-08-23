<?php

namespace App\Services;

use App\Models\TaxRule;
use App\Support\TenantContext;

/**
 * Resolves the correct effective-dated tax rule for a charge.
 *
 * The resolver looks up the tax rule that was active at posting time for the
 * given facility and service category. Historical charges remain reproducible
 * using the rule version that applied.
 *
 * Priority:
 * 1. Facility-specific rule matching the service category
 * 2. Org-wide rule (facility_id IS NULL) matching the service category
 * 3. Facility-specific rule with no service category (applies to all)
 * 4. Org-wide rule with no service category (default)
 *
 * If no rule matches, the charge is posted with tax_rate_bps = 0 and
 * tax_rule_id = null — this is valid for tax-exempt services.
 */
final class TaxResolver
{
    /**
     * Resolve the effective tax rule for a given facility and service category
     * at a given date. Returns null if no rule applies.
     */
    public function resolve(
        string $facilityId,
        ?string $serviceCategory = null,
        ?string $date = null,
    ): ?TaxRule {
        $context = TenantContext::current();
        $date = $date ?? now()->toDateString();

        // Build a query for rules active on the given date.
        $baseQuery = TaxRule::query()
            ->where('tenant_id', $context->tenantId())
            ->where('status', TaxRule::STATUS_ACTIVE)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            });

        // 1. Facility-specific rule matching service category
        if ($serviceCategory !== null) {
            $rule = (clone $baseQuery)
                ->where('facility_id', $facilityId)
                ->where('service_category', $serviceCategory)
                ->orderByDesc('effective_from')
                ->first();

            if ($rule !== null) {
                return $rule;
            }
        }

        // 2. Org-wide rule matching service category
        if ($serviceCategory !== null) {
            $rule = (clone $baseQuery)
                ->whereNull('facility_id')
                ->where('service_category', $serviceCategory)
                ->orderByDesc('effective_from')
                ->first();

            if ($rule !== null) {
                return $rule;
            }
        }

        // 3. Facility-specific rule with no service category (default for facility)
        $rule = (clone $baseQuery)
            ->where('facility_id', $facilityId)
            ->whereNull('service_category')
            ->orderByDesc('effective_from')
            ->first();

        if ($rule !== null) {
            return $rule;
        }

        // 4. Org-wide default rule (no service category, no facility)
        $rule = (clone $baseQuery)
            ->whereNull('facility_id')
            ->whereNull('service_category')
            ->orderByDesc('effective_from')
            ->first();

        return $rule;
    }

    /**
     * Resolve and return the tax rate in basis points for a given facility
     * and service category. Returns 0 if no rule applies.
     */
    public function resolveRateBps(
        string $facilityId,
        ?string $serviceCategory = null,
        ?string $date = null,
    ): int {
        $rule = $this->resolve($facilityId, $serviceCategory, $date);

        return $rule?->rate_value_bps ?? 0;
    }

    /**
     * Calculate tax amount for a charge using the resolved tax rule.
     * Uses integer arithmetic (basis points) — never floating point.
     */
    public function calculateTax(
        string $facilityId,
        int $amountMinor,
        ?string $serviceCategory = null,
        ?string $date = null,
    ): int {
        $rule = $this->resolve($facilityId, $serviceCategory, $date);

        if ($rule === null) {
            return 0;
        }

        return $rule->calculateTax($amountMinor);
    }
}
