<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\TaxRule;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated tax rule management (Nepal Financial Architecture).
 *
 * Rate changes create new rules with effective_from dates — never UPDATE
 * existing ones. Historical charges/invoices remain reproducible using the
 * rule version that applied at posting time.
 */
final class TaxRuleService
{
    /**
     * List tax rules for the current tenant, optionally filtered by facility.
     */
    public function list(?string $facilityId = null): \Illuminate\Support\Collection
    {
        $context = TenantContext::current();
        $query = TaxRule::query()
            ->where('tenant_id', $context->tenantId())
            ->orderBy('code');

        if ($facilityId !== null) {
            $query->where(function ($q) use ($facilityId): void {
                $q->where('facility_id', $facilityId)
                    ->orWhereNull('facility_id');
            });
        }

        return $query->get();
    }

    /**
     * Create a new tax rule. Code must be unique per tenant.
     */
    public function create(array $data): TaxRule
    {
        $context = TenantContext::current();

        $existing = TaxRule::query()
            ->where('tenant_id', $context->tenantId())
            ->whereRaw('lower(code) = ?', [strtolower($data['code'])])
            ->exists();

        if ($existing) {
            throw new ApiException(ErrorCodes::CONFLICT, 'A tax rule with this code already exists.', 409);
        }

        return DB::transaction(function () use ($data, $context): TaxRule {
            return TaxRule::query()->create([
                'tenant_id' => $context->tenantId(),
                'facility_id' => $data['facilityId'] ?? null,
                'code' => $data['code'],
                'name' => $data['name'],
                'tax_type' => $data['taxType'],
                'description' => $data['description'] ?? null,
                'rate_method' => $data['rateMethod'],
                'rate_value_bps' => $data['rateValueBps'],
                'currency' => $data['currency'] ?? 'NPR',
                'fixed_amount_minor' => $data['fixedAmountMinor'] ?? null,
                'jurisdiction' => $data['jurisdiction'] ?? 'nepal',
                'service_category' => $data['serviceCategory'] ?? null,
                'applies_to_opd' => $data['appliesToOpd'] ?? true,
                'applies_to_ipd' => $data['appliesToIpd'] ?? true,
                'applies_to_pharmacy' => $data['appliesToPharmacy'] ?? true,
                'applies_to_lab' => $data['appliesToLab'] ?? true,
                'applies_to_radiology' => $data['appliesToRadiology'] ?? true,
                'effective_from' => $data['effectiveFrom'],
                'effective_to' => $data['effectiveTo'] ?? null,
                'source_authority' => $data['sourceAuthority'] ?? null,
                'source_document' => $data['sourceDocument'] ?? null,
                'source_effective_date' => $data['sourceEffectiveDate'] ?? null,
                'source_url' => $data['sourceUrl'] ?? null,
                'source_version' => $data['sourceVersion'] ?? null,
                'status' => TaxRule::STATUS_ACTIVE,
                'is_default' => $data['isDefault'] ?? false,
                'created_by' => $context->user?->getKey(),
            ]);
        });
    }

    /**
     * Update a tax rule (only metadata — rate changes create new rules).
     */
    public function update(TaxRule $rule, array $data): TaxRule
    {
        $changes = [];

        foreach (['name', 'description', 'status', 'effective_to', 'source_authority', 'source_document', 'source_url'] as $field) {
            $camel = lcfirst(str_replace('_', '', ucwords($field, '_')));
            if (array_key_exists($camel, $data)) {
                $changes[$field] = [$rule->getAttribute($field), $data[$camel]];
                $rule->setAttribute($field, $data[$camel]);
            }
        }

        $rule->updated_by = TenantContext::current()->user?->getKey();
        $rule->save();

        return $rule;
    }

    /**
     * Soft-delete a tax rule (set status to inactive).
     */
    public function deactivate(TaxRule $rule): TaxRule
    {
        $rule->status = TaxRule::STATUS_INACTIVE;
        $rule->updated_by = TenantContext::current()->user?->getKey();
        $rule->save();

        return $rule;
    }
}
