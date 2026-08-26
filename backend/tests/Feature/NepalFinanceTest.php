<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Payer;
use App\Models\TaxRule;
use App\Services\TaxResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Tests for the Nepal Financial Architecture controllers:
 * TaxRuleController, BenefitRuleController, and NepalFinanceController.
 *
 * Covers: CRUD, authorization, tenant isolation, validation, and
 * effective-dated tax rule behavior.
 */
class NepalFinanceTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(): array
    {
        $org = Identity::organization();
        $facility = Identity::facility($org);
        $admin = Identity::user();
        Identity::assign($admin, 'hospital_admin', $org, $facility);

        return ['org' => $org, 'facility' => $facility, 'admin' => $admin];
    }

    // ── Tax Rule Tests ────────────────────────────────────────

    public function test_tax_rule_requires_auth(): void
    {
        $this->getJson('/api/v1/finance/tax-rules')->assertUnauthorized();
        $this->postJson('/api/v1/finance/tax-rules', [])->assertUnauthorized();
    }

    public function test_tax_rule_requires_billing_view(): void
    {
        $ctx = $this->ctx();
        $viewer = Identity::user();
        Identity::assign($viewer, 'receptionist', $ctx['org'], $ctx['facility']);

        $this->withToken(Identity::tokenFor($viewer))
            ->getJson('/api/v1/finance/tax-rules')
            ->assertForbidden();
    }

    public function test_tax_rule_index_empty(): void
    {
        $ctx = $this->ctx();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson('/api/v1/finance/tax-rules')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_tax_rule_create_and_list(): void
    {
        $ctx = $this->ctx();

        $payload = [
            'code' => 'VAT_13',
            'name' => 'Standard VAT 13%',
            'taxType' => 'vat',
            'rateMethod' => 'percentage',
            'rateValueBps' => 1300,
            'effectiveFrom' => '2025-07-16',
            'sourceAuthority' => 'Inland Revenue Department',
            'sourceDocument' => 'VAT Act 2052',
        ];

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/tax-rules', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'VAT_13')
            ->assertJsonPath('data.rateValueBps', 1300)
            ->assertJsonPath('data.status', 'active');

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson('/api/v1/finance/tax-rules')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'VAT_13');
    }

    public function test_tax_rule_rejects_duplicate_code(): void
    {
        $ctx = $this->ctx();

        $payload = [
            'code' => 'VAT_13',
            'name' => 'Standard VAT',
            'taxType' => 'vat',
            'rateMethod' => 'percentage',
            'rateValueBps' => 1300,
            'effectiveFrom' => '2025-07-16',
        ];

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/tax-rules', $payload)
            ->assertCreated();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/tax-rules', $payload)
            ->assertStatus(409);
    }

    public function test_tax_rule_validates_required_fields(): void
    {
        $ctx = $this->ctx();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/tax-rules', [])
            ->assertStatus(422);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/tax-rules', [
                'code' => 'bad',
                'name' => 'X',
                'taxType' => 'vat',
                'rateMethod' => 'percentage',
                'rateValueBps' => 1300,
                'effectiveFrom' => '2025-07-16',
            ])
            ->assertStatus(422); // code must match regex
    }

    public function test_tax_rule_update_metadata(): void
    {
        $ctx = $this->ctx();

        $create = $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/tax-rules', [
                'code' => 'VAT_13',
                'name' => 'VAT',
                'taxType' => 'vat',
                'rateMethod' => 'percentage',
                'rateValueBps' => 1300,
                'effectiveFrom' => '2025-07-16',
            ])
            ->assertCreated();

        $id = $create->json('data.id');

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->patchJson("/api/v1/finance/tax-rules/{$id}", [
                'name' => 'Standard VAT 13%',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Standard VAT 13%');
    }

    public function test_tax_rule_deactivate(): void
    {
        $ctx = $this->ctx();

        $create = $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/tax-rules', [
                'code' => 'VAT_13',
                'name' => 'VAT',
                'taxType' => 'vat',
                'rateMethod' => 'percentage',
                'rateValueBps' => 1300,
                'effectiveFrom' => '2025-07-16',
            ])
            ->assertCreated();

        $id = $create->json('data.id');

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->deleteJson("/api/v1/finance/tax-rules/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');
    }

    public function test_tax_rule_tenant_isolation(): void
    {
        $ctxA = $this->ctx();
        $ctxB = $this->ctx();

        // Create tax rule in tenant A
        $this->withToken(Identity::tokenFor($ctxA['admin']))
            ->postJson('/api/v1/finance/tax-rules', [
                'code' => 'VAT_A',
                'name' => 'Tenant A VAT',
                'taxType' => 'vat',
                'rateMethod' => 'percentage',
                'rateValueBps' => 1300,
                'effectiveFrom' => '2025-07-16',
            ])
            ->assertCreated();

        // Tenant B should see empty list
        $this->withToken(Identity::tokenFor($ctxB['admin']))
            ->getJson('/api/v1/finance/tax-rules')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── Benefit Rule Tests ────────────────────────────────────

    public function test_benefit_rule_requires_auth(): void
    {
        $this->getJson('/api/v1/finance/payers/nonexistent/benefit-rules')->assertUnauthorized();
    }

    public function test_benefit_rule_crud(): void
    {
        $ctx = $this->ctx();

        // Create payer first
        $payer = Payer::create([
            'tenant_id' => $ctx['org']->getKey(),
            'name' => 'SSF',
            'code' => 'SSF',
            'payer_type' => 'insurance',
            'status' => 'active',
        ]);

        // List empty
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson("/api/v1/finance/payers/{$payer->getKey()}/benefit-rules")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Create benefit rule
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/finance/payers/{$payer->getKey()}/benefit-rules", [
                'code' => 'SSF_OPD_MED',
                'name' => 'SSF OPD Medicine',
                'schemeVersion' => 'SSF_2082',
                'serviceCategory' => 'medicine',
                'coverageType' => 'capped',
                'coveragePercentBps' => 10000,
                'limitMinor' => 500000,
                'effectiveFrom' => '2025-07-16',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SSF_OPD_MED');

        // List should have 1
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson("/api/v1/finance/payers/{$payer->getKey()}/benefit-rules")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_benefit_rule_rejects_invalid_payer(): void
    {
        $ctx = $this->ctx();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson('/api/v1/finance/payers/nonexistent/benefit-rules')
            ->assertStatus(404);
    }

    public function test_benefit_rule_validates_required_fields(): void
    {
        $ctx = $this->ctx();

        $payer = Payer::create([
            'tenant_id' => $ctx['org']->getKey(),
            'name' => 'SSF',
            'code' => 'SSF',
            'payer_type' => 'insurance',
            'status' => 'active',
        ]);

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/finance/payers/{$payer->getKey()}/benefit-rules", [])
            ->assertStatus(422);
    }

    // ── NepalFinanceController Tests ──────────────────────────

    public function test_fiscal_years_crud(): void
    {
        $ctx = $this->ctx();

        // List empty
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson('/api/v1/finance/fiscal-years')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Create
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/fiscal-years', [
                'name' => 'Nepal FY 2082/83',
                'fiscalYear' => 2082,
                'startDate' => '2025-07-16',
                'endDate' => '2026-07-15',
                'calendarType' => 'nepal_fiscal',
                'nepalFiscalYear' => '2082/83',
            ])
            ->assertCreated()
            ->assertJsonPath('data.fiscal_year', 2082)
            ->assertJsonPath('data.calendar_type', 'nepal_fiscal')
            ->assertJsonPath('data.period_status', 'open');

        // List should have 1
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson('/api/v1/finance/fiscal-years')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_fiscal_year_close(): void
    {
        $ctx = $this->ctx();

        $create = $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/fiscal-years', [
                'name' => 'Nepal FY 2082/83',
                'fiscalYear' => 2082,
                'startDate' => '2025-07-16',
                'endDate' => '2026-07-15',
            ])
            ->assertCreated();

        $id = $create->json('data.id');

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/finance/fiscal-years/{$id}/close")
            ->assertOk()
            ->assertJsonPath('data.period_status', 'closed');

        // Cannot close again
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/finance/fiscal-years/{$id}/close")
            ->assertStatus(409);
    }

    public function test_payers_crud(): void
    {
        $ctx = $this->ctx();

        // List empty
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson('/api/v1/finance/payers')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Create SSF payer
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/payers', [
                'name' => 'Social Security Fund',
                'code' => 'SSF',
                'payerType' => 'insurance',
                'payerSubType' => 'ssf',
                'schemeVersion' => 'SSF_2082',
            ])
            ->assertCreated()
            ->assertJsonPath('data.payer_sub_type', 'ssf')
            ->assertJsonPath('data.scheme_version', 'SSF_2082');

        // Create HIB payer
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/payers', [
                'name' => 'Health Insurance Board',
                'code' => 'HIB',
                'payerType' => 'insurance',
                'payerSubType' => 'hib',
                'schemeVersion' => 'HIB_BP_V3',
            ])
            ->assertCreated();

        // List should have 2
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson('/api/v1/finance/payers')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_payer_rejects_duplicate_code(): void
    {
        $ctx = $this->ctx();

        $payload = [
            'name' => 'SSF',
            'code' => 'SSF',
            'payerType' => 'insurance',
        ];

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/payers', $payload)
            ->assertCreated();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/finance/payers', $payload)
            ->assertStatus(409);
    }

    public function test_claims_list(): void
    {
        $ctx = $this->ctx();

        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->getJson('/api/v1/finance/claims')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_nepal_finance_requires_auth(): void
    {
        $this->getJson('/api/v1/finance/fiscal-years')->assertUnauthorized();
        $this->getJson('/api/v1/finance/payers')->assertUnauthorized();
        $this->getJson('/api/v1/finance/claims')->assertUnauthorized();
    }

    public function test_nepal_finance_requires_billing_permission(): void
    {
        $ctx = $this->ctx();
        $viewer = Identity::user();
        Identity::assign($viewer, 'receptionist', $ctx['org'], $ctx['facility']);

        $this->withToken(Identity::tokenFor($viewer))
            ->getJson('/api/v1/finance/fiscal-years')
            ->assertForbidden();

        $this->withToken(Identity::tokenFor($viewer))
            ->getJson('/api/v1/finance/payers')
            ->assertForbidden();

        $this->withToken(Identity::tokenFor($viewer))
            ->getJson('/api/v1/finance/claims')
            ->assertForbidden();
    }

    // ── TaxResolver Tests ─────────────────────────────────────

    public function test_tax_resolver_resolves_effective_rule(): void
    {
        $ctx = $this->ctx();

        TaxRule::create([
            'tenant_id' => $ctx['org']->getKey(),
            'code' => 'VAT_13',
            'name' => 'VAT',
            'tax_type' => 'vat',
            'rate_method' => 'percentage',
            'rate_value_bps' => 1300,
            'effective_from' => '2025-07-16',
            'status' => 'active',
        ]);

        $resolver = app(TaxResolver::class);
        $rule = $resolver->resolve($ctx['facility']->getKey());

        $this->assertNotNull($rule);
        $this->assertEquals('VAT_13', $rule->code);
        $this->assertEquals(1300, $rule->rate_value_bps);
    }

    public function test_tax_resolver_returns_null_when_no_rules(): void
    {
        $ctx = $this->ctx();

        $resolver = app(TaxResolver::class);
        $rule = $resolver->resolve($ctx['facility']->getKey());

        $this->assertNull($rule);
    }

    public function test_charge_resolves_tax_fields(): void
    {
        $ctx = $this->ctx();

        TaxRule::create([
            'tenant_id' => $ctx['org']->getKey(),
            'code' => 'VAT_13',
            'name' => 'VAT',
            'tax_type' => 'vat',
            'rate_method' => 'percentage',
            'rate_value_bps' => 1300,
            'effective_from' => '2025-07-16',
            'status' => 'active',
        ]);

        $fields = Charge::resolveTaxFields($ctx['facility']->getKey(), 'opd');

        $this->assertNotNull($fields['tax_rule_id']);
        $this->assertEquals(1300, $fields['tax_rate_bps']);
    }

    public function test_charge_resolve_tax_fields_returns_zero_when_no_rules(): void
    {
        $ctx = $this->ctx();

        $fields = Charge::resolveTaxFields($ctx['facility']->getKey(), 'opd');

        $this->assertNull($fields['tax_rule_id']);
        $this->assertEquals(0, $fields['tax_rate_bps']);
    }

    public function test_tax_rule_model_calculates_tax(): void
    {
        $rule = new TaxRule([
            'rate_method' => 'percentage',
            'rate_value_bps' => 1300,
        ]);

        // 100 NPR = 10000 minor units; 13% = 1300
        $this->assertEquals(1300, $rule->calculateTax(10000));

        // 500 NPR = 50000 minor units; 13% = 6500
        $this->assertEquals(6500, $rule->calculateTax(50000));
    }

    public function test_tax_rule_model_fixed_amount(): void
    {
        $rule = new TaxRule([
            'rate_method' => 'fixed_amount',
            'fixed_amount_minor' => 500,
        ]);

        $this->assertEquals(500, $rule->calculateTax(10000));
        $this->assertEquals(500, $rule->calculateTax(50000));
    }
}
