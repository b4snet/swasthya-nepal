<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\BenefitRule;
use App\Models\Payer;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Versioned benefit rule management for payers (SSF, HIB, private insurance).
 *
 * Each rule defines coverage for a service category under a payer scheme.
 * Benefit changes create new rules with effective_from dates — never UPDATE
 * existing ones. Historical claims remain reproducible using the rule version
 * that applied at claim time.
 */
final class BenefitRuleService
{
    /**
     * List benefit rules for a specific payer within the current tenant.
     */
    public function list(string $payerId): Collection
    {
        $context = TenantContext::current();

        return BenefitRule::query()
            ->where('tenant_id', $context->tenantId())
            ->where('payer_id', $payerId)
            ->orderBy('code')
            ->get();
    }

    /**
     * Create a new benefit rule for a payer. Code must be unique per tenant+payer.
     */
    public function create(string $payerId, array $data): BenefitRule
    {
        $context = TenantContext::current();

        $payer = Payer::query()
            ->where('tenant_id', $context->tenantId())
            ->where('id', $payerId)
            ->first();

        if ($payer === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Payer not found.', 404);
        }

        $existing = BenefitRule::query()
            ->where('tenant_id', $context->tenantId())
            ->where('payer_id', $payerId)
            ->whereRaw('lower(code) = ?', [strtolower($data['code'])])
            ->exists();

        if ($existing) {
            throw new ApiException(ErrorCodes::CONFLICT, 'A benefit rule with this code already exists for this payer.', 409);
        }

        return DB::transaction(function () use ($payerId, $data, $context): BenefitRule {
            return BenefitRule::query()->create([
                'tenant_id' => $context->tenantId(),
                'payer_id' => $payerId,
                'code' => $data['code'],
                'name' => $data['name'],
                'scheme_version' => $data['schemeVersion'],
                'service_category' => $data['serviceCategory'],
                'coverage_type' => $data['coverageType'],
                'coverage_percent_bps' => $data['coveragePercentBps'] ?? null,
                'limit_minor' => $data['limitMinor'] ?? null,
                'copay_minor' => $data['copayMinor'] ?? null,
                'copay_percent_bps' => $data['copayPercentBps'] ?? null,
                'deductible_minor' => $data['deductibleMinor'] ?? null,
                'eligible_opd' => $data['eligibleOpd'] ?? true,
                'eligible_ipd' => $data['eligibleIpd'] ?? true,
                'eligible_maternity' => $data['eligibleMaternity'] ?? false,
                'eligible_dependents' => $data['eligibleDependents'] ?? false,
                'max_dependents' => $data['maxDependents'] ?? null,
                'effective_from' => $data['effectiveFrom'],
                'effective_to' => $data['effectiveTo'] ?? null,
                'source_authority' => $data['sourceAuthority'] ?? null,
                'source_document' => $data['sourceDocument'] ?? null,
                'source_effective_date' => $data['sourceEffectiveDate'] ?? null,
                'source_url' => $data['sourceUrl'] ?? null,
                'status' => BenefitRule::STATUS_ACTIVE,
                'created_by' => $context->user?->getKey(),
            ]);
        });
    }

    /**
     * Update a benefit rule (only metadata — coverage changes create new rules).
     */
    public function update(BenefitRule $rule, array $data): BenefitRule
    {
        foreach (['name', 'status', 'effective_to', 'source_authority', 'source_document', 'source_url'] as $field) {
            $camel = lcfirst(str_replace('_', '', ucwords($field, '_')));
            if (array_key_exists($camel, $data)) {
                $rule->setAttribute($field, $data[$camel]);
            }
        }

        $rule->updated_by = TenantContext::current()->user?->getKey();
        $rule->save();

        return $rule;
    }

    /**
     * Soft-delete a benefit rule (set status to inactive).
     */
    public function deactivate(BenefitRule $rule): BenefitRule
    {
        $rule->status = BenefitRule::STATUS_INACTIVE;
        $rule->updated_by = TenantContext::current()->user?->getKey();
        $rule->save();

        return $rule;
    }
}
