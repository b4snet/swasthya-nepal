<?php

namespace Database\Seeders;

use App\Models\BenefitRule;
use App\Models\FinancialPeriod;
use App\Models\Payer;
use App\Models\TaxRule;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Nepal Financial Architecture default data seeder.
 *
 * Creates default Nepal-specific financial configuration for a tenant:
 * - Tax rules (VAT 13%, Health Service Tax 5%, Health Equity Fee 3%)
 * - Payers (SSF, HIB, Self Pay)
 * - Benefit rules for SSF and HIB
 * - A Nepal fiscal year period
 *
 * This is NOT part of DatabaseSeeder (MASTER_RULES.md P.9: no demo data).
 * It must be invoked explicitly:
 *
 *   php artisan db:seed --class=NepalFinanceSeeder --force
 *
 * All values are sourced from Nepal government publications and marked with
 * source authority, document, and effective dates. These values MUST be
 * verified against current official sources before production activation.
 *
 * Usage:
 *   php artisan db:seed --class=NepalFinanceSeeder --force
 *     --tenant_id=<uuid>    (required: the organization/tenant to seed)
 *     --facility_id=<uuid>  (optional: facility-specific rules)
 *
 * ⚠️  All statutory values are CONFIGURABLE. This seeder creates initial
 * configuration that MUST be reviewed and verified against current Nepal
 * government publications before production use.
 */
class NepalFinanceSeeder extends Seeder
{
    /**
     * Nepal fiscal year: mid-Shrawan to mid-Shrawan (July 16 to July 15).
     */
    private const NEPAL_FISCAL_YEAR = 2082;
    private const NEPAL_FISCAL_BS = '2082/83';
    private const FY_START = '2025-07-16';
    private const FY_END = '2026-07-15';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error(
                'NepalFinanceSeeder refuses to run on production (APP_ENV=production). '
                .'Use the Nepal Finance Admin UI to configure values manually.'
            );

            return;
        }

        $tenantId = $this->parameter('tenant_id');
        if ($tenantId === null || $tenantId === '') {
            $this->command?->error(
                'Usage: php artisan db:seed --class=NepalFinanceSeeder --tenant_id=<uuid>'
            );

            return;
        }

        $facilityId = $this->parameter('facility_id') ?: null;

        $this->seedTaxRules($tenantId, $facilityId);
        $this->seedPayers($tenantId);
        $this->seedBenefitRules($tenantId);
        $this->seedFiscalYear($tenantId, $facilityId);

        $this->command?->info('Nepal financial architecture seeded successfully for tenant: '.$tenantId);
    }

    /**
     * Seed Nepal-specific tax rules with effective dates and source tracking.
     */
    private function seedTaxRules(string $tenantId, ?string $facilityId): void
    {
        $taxRules = [
            [
                'code' => 'VAT_STANDARD',
                'name' => 'Standard VAT',
                'tax_type' => 'vat',
                'description' => 'Nepal standard Value Added Tax on goods and services.',
                'rate_method' => 'percentage',
                'rate_value_bps' => 1300, // 13%
                'currency' => 'NPR',
                'jurisdiction' => 'nepal',
                'service_category' => null, // applies to all
                'applies_to_opd' => true,
                'applies_to_ipd' => true,
                'applies_to_pharmacy' => true,
                'applies_to_lab' => true,
                'applies_to_radiology' => true,
                'effective_from' => '2025-07-16',
                'effective_to' => null,
                'source_authority' => 'Inland Revenue Department, Nepal',
                'source_document' => 'VAT Act 2052 (1996), as amended',
                'source_effective_date' => null,
                'source_url' => 'https://ird.gov.np',
                'source_version' => null,
                'is_default' => true,
            ],
            [
                'code' => 'HEALTH_SVC_TAX',
                'name' => 'Health Service Tax',
                'tax_type' => 'health_service_tax',
                'description' => 'Health service tax on private healthcare services as per Finance Act.',
                'rate_method' => 'percentage',
                'rate_value_bps' => 500, // 5%
                'currency' => 'NPR',
                'jurisdiction' => 'nepal',
                'service_category' => null,
                'applies_to_opd' => true,
                'applies_to_ipd' => true,
                'applies_to_pharmacy' => true,
                'applies_to_lab' => true,
                'applies_to_radiology' => true,
                'effective_from' => '2025-07-16',
                'effective_to' => null,
                'source_authority' => 'Ministry of Finance, Nepal',
                'source_document' => 'Finance Act 2082/83',
                'source_effective_date' => '2025-07-16',
                'source_url' => null,
                'source_version' => null,
                'is_default' => false,
            ],
            [
                'code' => 'HEALTH_EQUITY',
                'name' => 'Health Equity Fee',
                'tax_type' => 'health_equity_fee',
                'description' => 'Health equity fee on private healthcare as per Finance Act.',
                'rate_method' => 'percentage',
                'rate_value_bps' => 300, // 3%
                'currency' => 'NPR',
                'jurisdiction' => 'nepal',
                'service_category' => null,
                'applies_to_opd' => true,
                'applies_to_ipd' => true,
                'applies_to_pharmacy' => true,
                'applies_to_lab' => true,
                'applies_to_radiology' => true,
                'effective_from' => '2025-07-16',
                'effective_to' => null,
                'source_authority' => 'Ministry of Finance, Nepal',
                'source_document' => 'Finance Act 2083/84',
                'source_effective_date' => '2026-07-16',
                'source_url' => null,
                'source_version' => null,
                'is_default' => false,
            ],
        ];

        foreach ($taxRules as $data) {
            $exists = TaxRule::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $data['code'])
                ->exists();

            if (! $exists) {
                TaxRule::query()->create([
                    ...$data,
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'status' => TaxRule::STATUS_ACTIVE,
                ]);
                $this->command?->info("  ✓ Tax rule: {$data['code']} ({$data['rate_value_bps']} bps)");
            }
        }
    }

    /**
     * Seed Nepal-specific payers (SSF, HIB, Self Pay).
     */
    private function seedPayers(string $tenantId): void
    {
        $payers = [
            [
                'code' => 'SSF',
                'name' => 'Social Security Fund',
                'payer_type' => 'insurance',
                'payer_sub_type' => 'ssf',
                'scheme_version' => 'SSF_2082',
            ],
            [
                'code' => 'HIB',
                'name' => 'Health Insurance Board',
                'payer_type' => 'insurance',
                'payer_sub_type' => 'hib',
                'scheme_version' => 'HIB_BP_V3',
            ],
            [
                'code' => 'SELF_PAY',
                'name' => 'Self Pay / Cash',
                'payer_type' => 'self_pay',
                'payer_sub_type' => null,
                'scheme_version' => null,
            ],
        ];

        foreach ($payers as $data) {
            $exists = Payer::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $data['code'])
                ->exists();

            if (! $exists) {
                Payer::query()->create([
                    ...$data,
                    'tenant_id' => $tenantId,
                    'status' => Payer::STATUS_ACTIVE,
                ]);
                $this->command?->info("  ✓ Payer: {$data['code']} ({$data['name']})");
            }
        }
    }

    /**
     * Seed benefit rules for SSF and HIB payers.
     *
     * ⚠️  These values are INITIAL CONFIGURATION based on published SSF/HIB
     * benefit structures. They MUST be verified against current official
     * publications before production use.
     */
    private function seedBenefitRules(string $tenantId): void
    {
        $ssfPayer = Payer::query()
            ->where('tenant_id', $tenantId)
            ->where('code', 'SSF')
            ->first();

        $hibPayer = Payer::query()
            ->where('tenant_id', $tenantId)
            ->where('code', 'HIB')
            ->first();

        if ($ssfPayer !== null) {
            $ssfBenefits = [
                [
                    'code' => 'SSF_OPD_MED',
                    'name' => 'SSF OPD Medicine',
                    'scheme_version' => 'SSF_2082',
                    'service_category' => 'medicine',
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 10000, // 100% covered
                    'limit_minor' => 500000, // NPR 5,000
                    'copay_minor' => 0,
                    'copay_percent_bps' => null,
                    'deductible_minor' => null,
                    'eligible_opd' => true,
                    'eligible_ipd' => false,
                    'eligible_maternity' => false,
                    'eligible_dependents' => true,
                    'max_dependents' => null,
                    'effective_from' => '2025-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Social Security Fund, Nepal',
                    'source_document' => 'SSF Healthcare Scheme Guidelines',
                    'source_effective_date' => null,
                    'source_url' => 'https://www.ssf.gov.np',
                ],
                [
                    'code' => 'SSF_OPD_LAB',
                    'name' => 'SSF OPD Diagnostic',
                    'scheme_version' => 'SSF_2082',
                    'service_category' => 'diagnostic',
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 10000,
                    'limit_minor' => 300000, // NPR 3,000
                    'copay_minor' => 0,
                    'copay_percent_bps' => null,
                    'deductible_minor' => null,
                    'eligible_opd' => true,
                    'eligible_ipd' => false,
                    'eligible_maternity' => false,
                    'eligible_dependents' => true,
                    'max_dependents' => null,
                    'effective_from' => '2025-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Social Security Fund, Nepal',
                    'source_document' => 'SSF Healthcare Scheme Guidelines',
                    'source_effective_date' => null,
                    'source_url' => 'https://www.ssf.gov.np',
                ],
                [
                    'code' => 'SSF_IPD',
                    'name' => 'SSF IPD Coverage',
                    'scheme_version' => 'SSF_2082',
                    'service_category' => 'surgery',
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 7500, // 75%
                    'limit_minor' => 5000000, // NPR 50,000
                    'copay_minor' => null,
                    'copay_percent_bps' => 2500, // 25% co-pay
                    'deductible_minor' => null,
                    'eligible_opd' => false,
                    'eligible_ipd' => true,
                    'eligible_maternity' => true,
                    'eligible_dependents' => true,
                    'max_dependents' => null,
                    'effective_from' => '2025-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Social Security Fund, Nepal',
                    'source_document' => 'SSF Healthcare Scheme Guidelines',
                    'source_effective_date' => null,
                    'source_url' => 'https://www.ssf.gov.np',
                ],
                [
                    'code' => 'SSF_MATERNITY',
                    'name' => 'SSF Maternity Benefit',
                    'scheme_version' => 'SSF_2082',
                    'service_category' => 'maternity',
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 10000,
                    'limit_minor' => 800000, // NPR 8,000
                    'copay_minor' => 0,
                    'copay_percent_bps' => null,
                    'deductible_minor' => null,
                    'eligible_opd' => false,
                    'eligible_ipd' => true,
                    'eligible_maternity' => true,
                    'eligible_dependents' => true,
                    'max_dependents' => null,
                    'effective_from' => '2025-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Social Security Fund, Nepal',
                    'source_document' => 'SSF Healthcare Scheme Guidelines',
                    'source_effective_date' => null,
                    'source_url' => 'https://www.ssf.gov.np',
                ],
            ];

            foreach ($ssfBenefits as $data) {
                $exists = BenefitRule::query()
                    ->where('tenant_id', $tenantId)
                    ->where('payer_id', $ssfPayer->getKey())
                    ->where('code', $data['code'])
                    ->exists();

                if (! $exists) {
                    BenefitRule::query()->create([
                        ...$data,
                        'tenant_id' => $tenantId,
                        'payer_id' => $ssfPayer->getKey(),
                        'status' => BenefitRule::STATUS_ACTIVE,
                    ]);
                    $this->command?->info("  ✓ SSF benefit: {$data['code']}");
                }
            }
        }

        if ($hibPayer !== null) {
            $hibBenefits = [
                [
                    'code' => 'HIB_OPD',
                    'name' => 'HIB OPD Coverage',
                    'scheme_version' => 'HIB_BP_V3',
                    'service_category' => 'opd',
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 7500, // 75%
                    'limit_minor' => 10000000, // NPR 100,000 per year
                    'copay_minor' => null,
                    'copay_percent_bps' => 2500, // 25% co-pay
                    'deductible_minor' => null,
                    'eligible_opd' => true,
                    'eligible_ipd' => false,
                    'eligible_maternity' => false,
                    'eligible_dependents' => true,
                    'max_dependents' => null,
                    'effective_from' => '2025-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Health Insurance Board, Nepal',
                    'source_document' => 'HIB Benefit Package v3',
                    'source_effective_date' => null,
                    'source_url' => 'https://hib.gov.np',
                ],
                [
                    'code' => 'HIB_IPD',
                    'name' => 'HIB IPD Coverage',
                    'scheme_version' => 'HIB_BP_V3',
                    'service_category' => 'ipd',
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 7500, // 75%
                    'limit_minor' => 10000000, // NPR 100,000 per year
                    'copay_minor' => null,
                    'copay_percent_bps' => 2500, // 25% co-pay
                    'deductible_minor' => null,
                    'eligible_opd' => false,
                    'eligible_ipd' => true,
                    'eligible_maternity' => true,
                    'eligible_dependents' => true,
                    'max_dependents' => null,
                    'effective_from' => '2025-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Health Insurance Board, Nepal',
                    'source_document' => 'HIB Benefit Package v3',
                    'source_effective_date' => null,
                    'source_url' => 'https://hib.gov.np',
                ],
                [
                    'code' => 'HIB_MATERNITY',
                    'name' => 'HIB Maternity Coverage',
                    'scheme_version' => 'HIB_BP_V3',
                    'service_category' => 'maternity',
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 7500,
                    'limit_minor' => 5000000, // NPR 50,000
                    'copay_minor' => null,
                    'copay_percent_bps' => 2500,
                    'deductible_minor' => null,
                    'eligible_opd' => false,
                    'eligible_ipd' => true,
                    'eligible_maternity' => true,
                    'eligible_dependents' => true,
                    'max_dependents' => null,
                    'effective_from' => '2025-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Health Insurance Board, Nepal',
                    'source_document' => 'HIB Benefit Package v3',
                    'source_effective_date' => null,
                    'source_url' => 'https://hib.gov.np',
                ],
                [
                    'code' => 'HIB_EMERGENCY',
                    'name' => 'HIB Emergency Coverage',
                    'scheme_version' => 'HIB_BP_V3',
                    'service_category' => 'emergency',
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 10000, // 100%
                    'limit_minor' => 10000000, // NPR 100,000
                    'copay_minor' => 0,
                    'copay_percent_bps' => null,
                    'deductible_minor' => null,
                    'eligible_opd' => false,
                    'eligible_ipd' => true,
                    'eligible_maternity' => false,
                    'eligible_dependents' => true,
                    'max_dependents' => null,
                    'effective_from' => '2025-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Health Insurance Board, Nepal',
                    'source_document' => 'HIB Benefit Package v3',
                    'source_effective_date' => null,
                    'source_url' => 'https://hib.gov.np',
                ],
            ];

            foreach ($hibBenefits as $data) {
                $exists = BenefitRule::query()
                    ->where('tenant_id', $tenantId)
                    ->where('payer_id', $hibPayer->getKey())
                    ->where('code', $data['code'])
                    ->exists();

                if (! $exists) {
                    BenefitRule::query()->create([
                        ...$data,
                        'tenant_id' => $tenantId,
                        'payer_id' => $hibPayer->getKey(),
                        'status' => BenefitRule::STATUS_ACTIVE,
                    ]);
                    $this->command?->info("  ✓ HIB benefit: {$data['code']}");
                }
            }
        }
    }

    /**
     * Seed a Nepal fiscal year period.
     */
    private function seedFiscalYear(string $tenantId, ?string $facilityId): void
    {
        $exists = FinancialPeriod::query()
            ->where('tenant_id', $tenantId)
            ->where('fiscal_year', self::NEPAL_FISCAL_YEAR)
            ->where('period_number', 1)
            ->exists();

        if (! $exists) {
            FinancialPeriod::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'name' => 'Nepal FY '.self::NEPAL_FISCAL_BS,
                'fiscal_year' => self::NEPAL_FISCAL_YEAR,
                'period_number' => 1,
                'period_type' => 'fiscal_year',
                'start_date' => self::FY_START,
                'end_date' => self::FY_END,
                'status' => FinancialPeriod::STATUS_OPEN,
                'calendar_type' => 'nepal_fiscal',
                'nepal_fiscal_year' => self::NEPAL_FISCAL_BS,
                'period_status' => 'open',
            ]);
            $this->command?->info("  ✓ Fiscal year: Nepal FY ".self::NEPAL_FISCAL_BS);
        }
    }
}
