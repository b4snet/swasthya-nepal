<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\ApiRequest;
use App\Models\TaxRule;

/**
 * POST /api/v1/finance/tax-rules — create an effective-dated tax rule.
 * Code is unique per tenant.
 */
class StoreTaxRuleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,49}$/', 'max:50'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'taxType' => ['required', 'in:'.implode(',', [
                TaxRule::TYPE_VAT,
                TaxRule::TYPE_HEALTH_SERVICE_TAX,
                TaxRule::TYPE_HEALTH_EQUITY_FEE,
                TaxRule::TYPE_EXCISE,
                TaxRule::TYPE_OTHER,
            ])],
            'description' => ['nullable', 'string', 'max:1000'],
            'rateMethod' => ['required', 'in:'.implode(',', [
                TaxRule::METHOD_PERCENTAGE,
                TaxRule::METHOD_FIXED,
                TaxRule::METHOD_PER_UNIT,
            ])],
            'rateValueBps' => ['required', 'integer', 'min:0', 'max:100000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'fixedAmountMinor' => ['nullable', 'integer', 'min:0'],
            'jurisdiction' => ['nullable', 'string', 'max:50'],
            'serviceCategory' => ['nullable', 'string', 'in:opd,ipd,pharmacy,lab,radiology'],
            'appliesToOpd' => ['nullable', 'boolean'],
            'appliesToIpd' => ['nullable', 'boolean'],
            'appliesToPharmacy' => ['nullable', 'boolean'],
            'appliesToLab' => ['nullable', 'boolean'],
            'appliesToRadiology' => ['nullable', 'boolean'],
            'effectiveFrom' => ['required', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after:effectiveFrom'],
            'sourceAuthority' => ['nullable', 'string', 'max:255'],
            'sourceDocument' => ['nullable', 'string', 'max:500'],
            'sourceEffectiveDate' => ['nullable', 'date'],
            'sourceUrl' => ['nullable', 'url', 'max:2000'],
            'sourceVersion' => ['nullable', 'string', 'max:100'],
            'isDefault' => ['nullable', 'boolean'],
        ];
    }
}
