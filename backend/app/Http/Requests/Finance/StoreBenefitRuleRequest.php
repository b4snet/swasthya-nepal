<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\ApiRequest;
use App\Models\BenefitRule;

/**
 * POST /api/v1/finance/payers/{payer}/benefit-rules — create a versioned
 * benefit rule for a payer. Code is unique per tenant+payer.
 */
class StoreBenefitRuleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,49}$/', 'max:50'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'schemeVersion' => ['required', 'string', 'max:100'],
            'serviceCategory' => ['required', 'string', 'in:opd,ipd,medicine,diagnostic,surgery,maternity,emergency'],
            'coverageType' => ['required', 'in:' . implode(',', [
                BenefitRule::COVERAGE_FULL,
                BenefitRule::COVERAGE_CO_PAY,
                BenefitRule::COVERAGE_DEDUCTIBLE,
                BenefitRule::COVERAGE_CAPPED,
                BenefitRule::COVERAGE_EXCLUDED,
            ])],
            'coveragePercentBps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'limitMinor' => ['nullable', 'integer', 'min:0'],
            'copayMinor' => ['nullable', 'integer', 'min:0'],
            'copayPercentBps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'deductibleMinor' => ['nullable', 'integer', 'min:0'],
            'eligibleOpd' => ['nullable', 'boolean'],
            'eligibleIpd' => ['nullable', 'boolean'],
            'eligibleMaternity' => ['nullable', 'boolean'],
            'eligibleDependents' => ['nullable', 'boolean'],
            'maxDependents' => ['nullable', 'integer', 'min:0'],
            'effectiveFrom' => ['required', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after:effectiveFrom'],
            'sourceAuthority' => ['nullable', 'string', 'max:255'],
            'sourceDocument' => ['nullable', 'string', 'max:500'],
            'sourceEffectiveDate' => ['nullable', 'date'],
            'sourceUrl' => ['nullable', 'url', 'max:2000'],
        ];
    }
}
