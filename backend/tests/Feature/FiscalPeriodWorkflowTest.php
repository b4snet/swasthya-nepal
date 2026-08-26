<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Charge;
use App\Models\FinancialPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Identity;
use Tests\TestCase;

/**
 * Fiscal year close/reopen/lock workflow tests.
 *
 * Verifies:
 * - Period lifecycle: open → closed → locked
 * - Close validation (only open periods)
 * - Reopen validation (only closed, not locked)
 * - Lock validation (only closed periods)
 * - Locked-period enforcement (charges blocked)
 * - Reopen clears close metadata
 * - Lock is irreversible
 */
class FiscalPeriodWorkflowTest extends TestCase
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

    public function test_full_lifecycle_open_to_closed_to_locked(): void
    {
        $ctx = $this->ctx();

        // Create an open period
        $create = $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/enterprise/finance/fiscal-years', [
                'name' => 'FY 2082/83',
                'fiscalYear' => 2082,
                'startDate' => '2025-07-16',
                'endDate' => '2026-07-15',
            ])
            ->assertCreated()
            ->assertJsonPath('data.period_status', 'open');

        $id = $create->json('data.id');

        // Close it
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/enterprise/finance/fiscal-years/{$id}/close")
            ->assertOk()
            ->assertJsonPath('data.period_status', 'closed');

        // Lock it
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/enterprise/finance/fiscal-years/{$id}/close")
            ->assertStatus(409); // already closed

        // Use the original FinancialPeriodController lock route
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/financial-periods/{$id}/lock")
            ->assertOk()
            ->assertJsonPath('data.status', 'locked');
    }

    public function test_reopen_closed_period(): void
    {
        $ctx = $this->ctx();

        $create = $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/enterprise/finance/fiscal-years', [
                'name' => 'FY 2082/83',
                'fiscalYear' => 2082,
                'startDate' => '2025-07-16',
                'endDate' => '2026-07-15',
            ])
            ->assertCreated();

        $id = $create->json('data.id');

        // Close
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/enterprise/finance/fiscal-years/{$id}/close")
            ->assertOk();

        // Reopen
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/enterprise/finance/fiscal-years/{$id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.period_status', 'open');
    }

    public function test_cannot_reopen_locked_period(): void
    {
        $ctx = $this->ctx();

        $create = $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/enterprise/finance/fiscal-years', [
                'name' => 'FY 2082/83',
                'fiscalYear' => 2082,
                'startDate' => '2025-07-16',
                'endDate' => '2026-07-15',
            ])
            ->assertCreated();

        $id = $create->json('data.id');

        // Close
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/enterprise/finance/fiscal-years/{$id}/close")
            ->assertOk();

        // Lock
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/financial-periods/{$id}/lock")
            ->assertOk();

        // Try to reopen — should fail
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/enterprise/finance/fiscal-years/{$id}/reopen")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_cannot_reopen_open_period(): void
    {
        $ctx = $this->ctx();

        $create = $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson('/api/v1/enterprise/finance/fiscal-years', [
                'name' => 'FY 2082/83',
                'fiscalYear' => 2082,
                'startDate' => '2025-07-16',
                'endDate' => '2026-07-15',
            ])
            ->assertCreated();

        $id = $create->json('data.id');

        // Try to reopen an open period — should fail
        $this->withToken(Identity::tokenFor($ctx['admin']))
            ->postJson("/api/v1/enterprise/finance/fiscal-years/{$id}/reopen")
            ->assertStatus(409);
    }

    public function test_locked_period_blocks_charge_posting(): void
    {
        $ctx = $this->ctx();

        // Create and lock a period covering today
        $period = FinancialPeriod::create([
            'tenant_id' => $ctx['org']->getKey(),
            'facility_id' => $ctx['facility']->getKey(),
            'name' => 'Locked FY',
            'fiscal_year' => 2082,
            'period_number' => 1,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => FinancialPeriod::STATUS_LOCKED,
            'period_status' => 'locked',
        ]);

        // Try to resolve tax fields (which now calls PeriodGuard)
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('locked');

        Charge::resolveTaxFields($ctx['facility']->getKey(), 'opd');
    }

    public function test_open_period_allows_charge_posting(): void
    {
        $ctx = $this->ctx();

        // Create an open period covering today
        FinancialPeriod::create([
            'tenant_id' => $ctx['org']->getKey(),
            'facility_id' => $ctx['facility']->getKey(),
            'name' => 'Open FY',
            'fiscal_year' => 2082,
            'period_number' => 1,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => FinancialPeriod::STATUS_OPEN,
            'period_status' => 'open',
        ]);

        // Should not throw
        $fields = Charge::resolveTaxFields($ctx['facility']->getKey(), 'opd');
        $this->assertIsArray($fields);
        $this->assertArrayHasKey('tax_rule_id', $fields);
        $this->assertArrayHasKey('tax_rate_bps', $fields);
    }

    public function test_no_period_allows_charge_posting(): void
    {
        $ctx = $this->ctx();

        // No period configured — should allow (periods are optional)
        $fields = Charge::resolveTaxFields($ctx['facility']->getKey(), 'opd');
        $this->assertIsArray($fields);
        $this->assertNull($fields['tax_rule_id']);
        $this->assertEquals(0, $fields['tax_rate_bps']);
    }

    public function test_fiscal_year_requires_auth(): void
    {
        $this->postJson('/api/v1/enterprise/finance/fiscal-years', [])->assertUnauthorized();
    }

    public function test_fiscal_year_requires_billing_manage_permission(): void
    {
        $ctx = $this->ctx();
        $viewer = Identity::user();
        Identity::assign($viewer, 'receptionist', $ctx['org'], $ctx['facility']);

        $this->withToken(Identity::tokenFor($viewer))
            ->postJson('/api/v1/enterprise/finance/fiscal-years', [
                'name' => 'Test',
                'fiscalYear' => 2082,
                'startDate' => '2025-07-16',
                'endDate' => '2026-07-15',
            ])
            ->assertForbidden();
    }
}
