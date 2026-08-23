<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreTaxRuleRequest;
use App\Models\TaxRule;
use App\Services\TaxRuleService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\FacilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Effective-dated tax rules (Nepal Financial Architecture).
 *
 * - index: list tax rules for the tenant, optionally filtered by facility.
 * - store: create a new tax rule (code unique per tenant).
 * - show: single tax rule.
 * - update: update metadata (rate changes create new rules).
 * - destroy: deactivate a tax rule (soft-delete).
 */
final class TaxRuleController extends Controller
{
    public function __construct(
        private readonly TaxRuleService $service,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $facilityId = $request->input('facilityId');
        $rules = $this->service->list($facilityId);

        return Envelope::success(
            data: $rules->map(fn (TaxRule $rule): array => self::present($rule))->values(),
            request: $request,
        );
    }

    public function store(StoreTaxRuleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $facilityId = $validated['facilityId'] ?? null;
        if ($facilityId !== null) {
            FacilityScope::resolve($facilityId, write: true);
        }

        $rule = $this->service->create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'taxType' => $validated['taxType'],
            'description' => $validated['description'] ?? null,
            'rateMethod' => $validated['rateMethod'],
            'rateValueBps' => $validated['rateValueBps'],
            'currency' => $validated['currency'] ?? 'NPR',
            'fixedAmountMinor' => $validated['fixedAmountMinor'] ?? null,
            'jurisdiction' => $validated['jurisdiction'] ?? 'nepal',
            'serviceCategory' => $validated['serviceCategory'] ?? null,
            'appliesToOpd' => $validated['appliesToOpd'] ?? true,
            'appliesToIpd' => $validated['appliesToIpd'] ?? true,
            'appliesToPharmacy' => $validated['appliesToPharmacy'] ?? true,
            'appliesToLab' => $validated['appliesToLab'] ?? true,
            'appliesToRadiology' => $validated['appliesToRadiology'] ?? true,
            'effectiveFrom' => $validated['effectiveFrom'],
            'effectiveTo' => $validated['effectiveTo'] ?? null,
            'sourceAuthority' => $validated['sourceAuthority'] ?? null,
            'sourceDocument' => $validated['sourceDocument'] ?? null,
            'sourceEffectiveDate' => $validated['sourceEffectiveDate'] ?? null,
            'sourceUrl' => $validated['sourceUrl'] ?? null,
            'sourceVersion' => $validated['sourceVersion'] ?? null,
            'isDefault' => $validated['isDefault'] ?? false,
        ]);

        $event = $this->audit->record(
            'tax_rule.created',
            'tax_rule',
            $rule->getKey(),
            ['code' => $rule->code, 'name' => $rule->name, 'taxType' => $rule->tax_type],
            $request,
        );

        return Envelope::success(
            data: self::present($rule),
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/finance/tax-rules/'.$rule->getKey(),
            ],
        );
    }

    public function show(Request $request, TaxRule $taxRule): JsonResponse
    {
        AccessCheck::tenantScoped($taxRule);

        return Envelope::success(data: self::present($taxRule), request: $request);
    }

    public function update(Request $request, TaxRule $taxRule): JsonResponse
    {
        AccessCheck::tenantScoped($taxRule);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', 'in:active,inactive,superseded'],
            'effectiveTo' => ['nullable', 'date'],
            'sourceAuthority' => ['nullable', 'string', 'max:255'],
            'sourceDocument' => ['nullable', 'string', 'max:500'],
            'sourceUrl' => ['nullable', 'url', 'max:2000'],
        ]);

        $rule = $this->service->update($taxRule, $validated);

        $this->audit->record(
            'tax_rule.updated',
            'tax_rule',
            $rule->getKey(),
            ['changes' => array_keys($validated)],
            $request,
        );

        return Envelope::success(data: self::present($rule), request: $request);
    }

    public function destroy(Request $request, TaxRule $taxRule): JsonResponse
    {
        AccessCheck::tenantScoped($taxRule);

        $rule = $this->service->deactivate($taxRule);

        $this->audit->record(
            'tax_rule.deactivated',
            'tax_rule',
            $rule->getKey(),
            ['code' => $rule->code],
            $request,
        );

        return Envelope::success(data: self::present($rule), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(TaxRule $rule): array
    {
        return [
            'id' => $rule->getKey(),
            'facilityId' => $rule->facility_id,
            'code' => $rule->code,
            'name' => $rule->name,
            'taxType' => $rule->tax_type,
            'description' => $rule->description,
            'rateMethod' => $rule->rate_method,
            'rateValueBps' => $rule->rate_value_bps,
            'currency' => $rule->currency,
            'fixedAmountMinor' => $rule->fixed_amount_minor,
            'jurisdiction' => $rule->jurisdiction,
            'serviceCategory' => $rule->service_category,
            'appliesToOpd' => $rule->applies_to_opd,
            'appliesToIpd' => $rule->applies_to_ipd,
            'appliesToPharmacy' => $rule->applies_to_pharmacy,
            'appliesToLab' => $rule->applies_to_lab,
            'appliesToRadiology' => $rule->applies_to_radiology,
            'effectiveFrom' => $rule->effective_from?->toDateString(),
            'effectiveTo' => $rule->effective_to?->toDateString(),
            'sourceAuthority' => $rule->source_authority,
            'sourceDocument' => $rule->source_document,
            'sourceEffectiveDate' => $rule->source_effective_date?->toDateString(),
            'sourceUrl' => $rule->source_url,
            'sourceVersion' => $rule->source_version,
            'status' => $rule->status,
            'isDefault' => $rule->is_default,
        ];
    }
}
