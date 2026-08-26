<?php

namespace Database\Seeders;

use App\Models\BenefitRule;
use App\Models\FinancialPeriod;
use App\Models\Payer;
use App\Models\TaxRule;
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
    private const NEPAL_FISCAL_YEAR = 2083;

    private const NEPAL_FISCAL_BS = '2083/84';

    private const FY_START = '2026-07-16';

    private const FY_END = '2027-07-15';

    /**
     * Seed Nepal financial data.
     *
     * Accepts parameters via:
     *  - run(['tenant_id' => '...', 'facility_id' => '...'])
     *  - Or via artisan command options when called from SeedNepalFinance command.
     */
    public function run(array $params = []): void
    {
        if (app()->environment('production')) {
            $this->command?->error(
                'NepalFinanceSeeder refuses to run on production (APP_ENV=production). '
                .'Use the Nepal Finance Admin UI to configure values manually.'
            );

            return;
        }

        $tenantId = $params['tenant_id'] ?? $this->command?->option('tenant_id') ?? null;
        if ($tenantId === null || $tenantId === '') {
            $this->command?->error(
                'Usage: php artisan nepal:finance:seed --tenant_id=<uuid>'
            );

            return;
        }

        $facilityId = $params['facility_id'] ?? $this->command?->option('facility_id') ?? null;

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
                'service_category' => 'all', // applies to all
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
                'name' => 'Health Service Tax (WITHDRAWN)',
                'tax_type' => 'health_service_tax',
                'description' => 'Health service tax on private healthcare — WITHDRAWN in FY 2083/84 budget. Archived for historical transaction reproducibility.',
                'rate_method' => 'percentage',
                'rate_value_bps' => 500, // 5%
                'currency' => 'NPR',
                'jurisdiction' => 'nepal',
                'service_category' => 'all',
                'applies_to_opd' => true,
                'applies_to_ipd' => true,
                'applies_to_pharmacy' => true,
                'applies_to_lab' => true,
                'applies_to_radiology' => true,
                'effective_from' => '2025-07-16',
                'effective_to' => '2026-07-15', // Withdrawn in FY 2083/84
                'source_authority' => 'Ministry of Finance, Nepal',
                'source_document' => 'Finance Act 2082/83 (withdrawn in 2083/84)',
                'source_effective_date' => '2025-07-16',
                'source_url' => 'https://mof.gov.np',
                'source_version' => 'withdrawn',
                'is_default' => false,
                'status' => 'superseded',
            ],
            [
                'code' => 'HEALTH_EQUITY',
                'name' => 'Health Equity Fee (WITHDRAWN)',
                'tax_type' => 'health_equity_fee',
                'description' => 'Health equity fee on private healthcare — INTRODUCED then WITHDRAWN in July 2026 following public criticism. Refunds being processed.',
                'rate_method' => 'percentage',
                'rate_value_bps' => 300, // 3%
                'currency' => 'NPR',
                'jurisdiction' => 'nepal',
                'service_category' => 'all',
                'applies_to_opd' => true,
                'applies_to_ipd' => true,
                'applies_to_pharmacy' => true,
                'applies_to_lab' => true,
                'applies_to_radiology' => true,
                'effective_from' => '2026-07-16',
                'effective_to' => '2026-07-21', // Withdrawn 5 days after introduction
                'source_authority' => 'Ministry of Finance, Nepal',
                'source_document' => 'Finance Act 2083/84 (withdrawn following public criticism)',
                'source_effective_date' => '2026-07-16',
                'source_url' => 'https://mof.gov.np',
                'source_version' => 'withdrawn',
                'is_default' => false,
                'status' => 'superseded',
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
                'payer_type' => 'government',
                'payer_sub_type' => 'ssf',
                'scheme_version' => 'SSF_2082',
            ],
            [
                'code' => 'HIB',
                'name' => 'Health Insurance Board',
                'payer_type' => 'government',
                'payer_sub_type' => 'hib',
                'scheme_version' => 'HIB_BP_V3',
            ],
            [
                'code' => 'SELF_PAY',
                'name' => 'Self Pay / Cash',
                'payer_type' => 'other',
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
                    'code' => 'SSF_MEDICAL',
                    'name' => 'SSF Medical Treatment Benefit',
                    'scheme_version' => 'SSF_2083',
                    'service_category' => 'all', // covers all medical services
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 8000, // 80% (20% co-payment)
                    'limit_minor' => 10000000, // NPR 100,000 per annum
                    'copay_minor' => null,
                    'copay_percent_bps' => 2000, // 20% co-payment per SSF rules
                    'deductible_minor' => null,
                    'eligible_opd' => true,
                    'eligible_ipd' => true,
                    'eligible_maternity' => true,
                    'eligible_dependents' => true,
                    'max_dependents' => null,
                    'effective_from' => '2026-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Social Security Fund, Nepal',
                    'source_document' => 'SSF Medical Treatment, Health and Maternity Protection Scheme',
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
                    'code' => 'HIB_FAMILY',
                    'name' => 'HIB Family Coverage (up to 5 members)',
                    'scheme_version' => 'HIB_2083',
                    'service_category' => 'all', // covers OPD, IPD, emergency
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 10000, // 100% up to limit
                    'limit_minor' => 10000000, // NPR 100,000 per year per family of 5
                    'copay_minor' => null,
                    'copay_percent_bps' => null,
                    'deductible_minor' => null,
                    'eligible_opd' => true,
                    'eligible_ipd' => true,
                    'eligible_maternity' => true,
                    'eligible_dependents' => true,
                    'max_dependents' => 5,
                    'effective_from' => '2026-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Health Insurance Board, Nepal',
                    'source_document' => 'HIB Benefit Package — NPR 100,000 per family of 5',
                    'source_effective_date' => null,
                    'source_url' => 'https://hib.gov.np',
                ],
                [
                    'code' => 'HIB_ADDITIONAL',
                    'name' => 'HIB Additional Member Coverage',
                    'scheme_version' => 'HIB_2083',
                    'service_category' => 'all',
                    'coverage_type' => 'capped',
                    'coverage_percent_bps' => 10000,
                    'limit_minor' => 2000000, // NPR 20,000 per additional member
                    'copay_minor' => null,
                    'copay_percent_bps' => null,
                    'deductible_minor' => null,
                    'eligible_opd' => true,
                    'eligible_ipd' => true,
                    'eligible_maternity' => true,
                    'eligible_dependents' => true,
                    'max_dependents' => null,
                    'effective_from' => '2026-07-16',
                    'effective_to' => null,
                    'source_authority' => 'Health Insurance Board, Nepal',
                    'source_document' => 'HIB Benefit Package — NPR 20,000 per additional member beyond 5',
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
            $this->command?->info('  ✓ Fiscal year: Nepal FY '.self::NEPAL_FISCAL_BS);
        }
    }
}
