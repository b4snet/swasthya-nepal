<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreBenefitRuleRequest;
use App\Models\BenefitRule;
use App\Models\Payer;
use App\Services\BenefitRuleService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Versioned benefit rules for payers (SSF, HIB, private insurance).
 *
 * - index: list benefit rules for a specific payer.
 * - store: create a new benefit rule (code unique per tenant+payer).
 * - show: single benefit rule.
 * - update: update metadata (coverage changes create new rules).
 * - destroy: deactivate a benefit rule (soft-delete).
 */
final class BenefitRuleController extends Controller
{
    public function __construct(
        private readonly BenefitRuleService $service,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, string $payer): JsonResponse
    {
        // Scope-check the payer belongs to this tenant.
        $payerModel = Payer::query()->find($payer);
        if ($payerModel === null) {
            return Envelope::error('NOT_FOUND', 'Payer not found.', 404, request: $request);
        }
        AccessCheck::tenantScoped($payerModel);

        $rules = $this->service->list($payer);

        return Envelope::success(
            data: $rules->map(fn (BenefitRule $rule): array => self::present($rule))->values(),
            request: $request,
        );
    }

    public function store(StoreBenefitRuleRequest $request, string $payer): JsonResponse
    {
        $payerModel = Payer::query()->find($payer);
        if ($payerModel === null) {
            return Envelope::error('NOT_FOUND', 'Payer not found.', 404, request: $request);
        }
        AccessCheck::tenantScoped($payerModel);

        $validated = $request->validated();

        $rule = $this->service->create($payer, [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'schemeVersion' => $validated['schemeVersion'],
            'serviceCategory' => $validated['serviceCategory'],
            'coverageType' => $validated['coverageType'],
            'coveragePercentBps' => $validated['coveragePercentBps'] ?? null,
            'limitMinor' => $validated['limitMinor'] ?? null,
            'copayMinor' => $validated['copayMinor'] ?? null,
            'copayPercentBps' => $validated['copayPercentBps'] ?? null,
            'deductibleMinor' => $validated['deductibleMinor'] ?? null,
            'eligibleOpd' => $validated['eligibleOpd'] ?? true,
            'eligibleIpd' => $validated['eligibleIpd'] ?? true,
            'eligibleMaternity' => $validated['eligibleMaternity'] ?? false,
            'eligibleDependents' => $validated['eligibleDependents'] ?? false,
            'maxDependents' => $validated['maxDependents'] ?? null,
            'effectiveFrom' => $validated['effectiveFrom'],
            'effectiveTo' => $validated['effectiveTo'] ?? null,
            'sourceAuthority' => $validated['sourceAuthority'] ?? null,
            'sourceDocument' => $validated['sourceDocument'] ?? null,
            'sourceEffectiveDate' => $validated['sourceEffectiveDate'] ?? null,
            'sourceUrl' => $validated['sourceUrl'] ?? null,
        ]);

        $event = $this->audit->record(
            'benefit_rule.created',
            'benefit_rule',
            $rule->getKey(),
            ['code' => $rule->code, 'name' => $rule->name, 'payerId' => $payer],
            $request,
        );

        return Envelope::success(
            data: self::present($rule),
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/finance/payers/'.$payer.'/benefit-rules/'.$rule->getKey(),
            ],
        );
    }

    public function show(Request $request, string $payer, BenefitRule $benefitRule): JsonResponse
    {
        AccessCheck::tenantScoped($benefitRule);

        return Envelope::success(data: self::present($benefitRule), request: $request);
    }

    public function update(Request $request, string $payer, BenefitRule $benefitRule): JsonResponse
    {
        AccessCheck::tenantScoped($benefitRule);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            'effectiveTo' => ['nullable', 'date'],
            'sourceAuthority' => ['nullable', 'string', 'max:255'],
            'sourceDocument' => ['nullable', 'string', 'max:500'],
            'sourceUrl' => ['nullable', 'url', 'max:2000'],
        ]);

        $rule = $this->service->update($benefitRule, $validated);

        $this->audit->record(
            'benefit_rule.updated',
            'benefit_rule',
            $rule->getKey(),
            ['changes' => array_keys($validated)],
            $request,
        );

        return Envelope::success(data: self::present($rule), request: $request);
    }

    public function destroy(Request $request, string $payer, BenefitRule $benefitRule): JsonResponse
    {
        AccessCheck::tenantScoped($benefitRule);

        $rule = $this->service->deactivate($benefitRule);

        $this->audit->record(
            'benefit_rule.deactivated',
            'benefit_rule',
            $rule->getKey(),
            ['code' => $rule->code],
            $request,
        );

        return Envelope::success(data: self::present($rule), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(BenefitRule $rule): array
    {
        return [
            'id' => $rule->getKey(),
            'payerId' => $rule->payer_id,
            'code' => $rule->code,
            'name' => $rule->name,
            'schemeVersion' => $rule->scheme_version,
            'serviceCategory' => $rule->service_category,
            'coverageType' => $rule->coverage_type,
            'coveragePercentBps' => $rule->coverage_percent_bps,
            'limitMinor' => $rule->limit_minor,
            'copayMinor' => $rule->copay_minor,
            'copayPercentBps' => $rule->copay_percent_bps,
            'deductibleMinor' => $rule->deductible_minor,
            'eligibleOpd' => $rule->eligible_opd,
            'eligibleIpd' => $rule->eligible_ipd,
            'eligibleMaternity' => $rule->eligible_maternity,
            'eligibleDependents' => $rule->eligible_dependents,
            'maxDependents' => $rule->max_dependents,
            'effectiveFrom' => $rule->effective_from?->toDateString(),
            'effectiveTo' => $rule->effective_to?->toDateString(),
            'sourceAuthority' => $rule->source_authority,
            'sourceDocument' => $rule->source_document,
            'sourceEffectiveDate' => $rule->source_effective_date?->toDateString(),
            'sourceUrl' => $rule->source_url,
            'status' => $rule->status,
        ];
    }
}
