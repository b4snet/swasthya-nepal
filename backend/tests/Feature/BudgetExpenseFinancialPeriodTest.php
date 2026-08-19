<?php

use App\Models\AuditEvent;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Tests\Support\Identity;

/**
 * Phase 17 — Enterprise Procurement, Inventory & Finance.
 *
 * Budgets: CRUD → approve → active → close.
 * Expenses: CRUD → submit → approve/reject → pay → void.
 * Financial Periods: create → close → lock.
 * Segregation of duties: requester ≠ approver.
 * Budget enforcement: expenses cannot exceed remaining allocation.
 * All mutations auditable, tenant/facility isolated, transactional.
 */
beforeEach(function (): void {
    seedIdentity();
});

function enterpriseSetup(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $category = ExpenseCategory::query()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'code' => 'MAINT',
        'name' => 'Maintenance',
        'status' => 'active',
    ]);

    return [$org, $facility, $admin, $category];
}

// ── Budget lifecycle ──

it('creates a budget in draft status', function () {
    [$org, $facility, $admin] = enterpriseSetup();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/budgets', [
            'name' => 'FY2026 Maintenance',
            'fiscalYear' => 2026,
            'budgetType' => 'operational',
            'totalAllocationMinor' => 50000000,
            'facilityId' => $facility->getKey(),
            'lines' => [
                ['description' => 'General maintenance', 'allocationMinor' => 30000000],
                ['description' => 'Emergency repairs', 'allocationMinor' => 20000000],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.budgetType', 'operational')
        ->assertJsonPath('data.fiscalYear', 2026)
        ->assertJsonPath('data.totalAllocationMinor', 50000000);
});

it('approves a draft budget to active', function () {
    [$org, $facility, $admin] = enterpriseSetup();

    $budgetId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/budgets', [
            'name' => 'Test Budget',
            'fiscalYear' => 2026,
            'totalAllocationMinor' => 10000000,
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/budgets/{$budgetId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('closes an active budget', function () {
    [$org, $facility, $admin] = enterpriseSetup();

    $budgetId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/budgets', [
            'name' => 'Closeable Budget',
            'fiscalYear' => 2026,
            'totalAllocationMinor' => 5000000,
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/budgets/{$budgetId}/approve")
        ->assertOk();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/budgets/{$budgetId}/close")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');
});

it('rejects approval of non-draft budget', function () {
    [$org, $facility, $admin] = enterpriseSetup();

    $budgetId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/budgets', [
            'name' => 'Cannot Approve Twice',
            'fiscalYear' => 2026,
            'totalAllocationMinor' => 1000000,
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/budgets/{$budgetId}/approve")
        ->assertOk();

    // Second approval should fail
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/budgets/{$budgetId}/approve")
        ->assertStatus(409);
});

it('adds a budget line and updates total allocation', function () {
    [$org, $facility, $admin] = enterpriseSetup();

    $budgetId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/budgets', [
            'name' => 'Expandable Budget',
            'fiscalYear' => 2026,
            'totalAllocationMinor' => 1000000,
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/budgets/{$budgetId}/lines", [
            'description' => 'New line item',
            'allocationMinor' => 500000,
        ])
        ->assertCreated();

    $budget = Budget::query()->findOrFail($budgetId);
    expect($budget->total_allocation_minor)->toBe(1500000);
});

// ── Expense lifecycle ──

it('creates an expense linked to a category', function () {
    [$org, $facility, $admin, $category] = enterpriseSetup();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/expenses', [
            'description' => 'Plumbing repair',
            'amountMinor' => 2500000,
            'expenseCategoryId' => $category->getKey(),
            'expenseDate' => '2026-08-15',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.amountMinor', 2500000);
});

it('submits and approves an expense', function () {
    [$org, $facility, $admin, $category] = enterpriseSetup();

    $expenseId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/expenses', [
            'description' => 'Office supplies',
            'amountMinor' => 150000,
            'expenseCategoryId' => $category->getKey(),
            'expenseDate' => '2026-08-15',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    // Submit
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/expenses/{$expenseId}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_approval');

    // Approve (different user would be segregation of duties, but admin can approve for test)
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/expenses/{$expenseId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});

it('rejects an expense and returns rejection reason', function () {
    [$org, $facility, $admin, $category] = enterpriseSetup();

    $expenseId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/expenses', [
            'description' => 'Unnecessary expense',
            'amountMinor' => 50000,
            'expenseCategoryId' => $category->getKey(),
            'expenseDate' => '2026-08-15',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/expenses/{$expenseId}/submit")
        ->assertOk();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/expenses/{$expenseId}/reject", [
            'reason' => 'Not aligned with budget',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejectionReason', 'Not aligned with budget');
});

it('pays an approved expense', function () {
    [$org, $facility, $admin, $category] = enterpriseSetup();

    $expenseId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/expenses', [
            'description' => 'Contractor payment',
            'amountMinor' => 750000,
            'expenseCategoryId' => $category->getKey(),
            'expenseDate' => '2026-08-15',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    // Submit + approve
    $this->withToken(Identity::tokenFor($admin))->postJson("/api/v1/enterprise/expenses/{$expenseId}/submit")->assertOk();
    $this->withToken(Identity::tokenFor($admin))->postJson("/api/v1/enterprise/expenses/{$expenseId}/approve")->assertOk();

    // Pay
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/expenses/{$expenseId}/pay", [
            'paymentMethod' => 'bank_transfer',
            'paymentReference' => 'TXN-001',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.paymentMethod', 'bank_transfer');
});

it('voids an expense and reverses budget allocation', function () {
    [$org, $facility, $admin, $category] = enterpriseSetup();

    // Create budget first
    $budgetId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/budgets', [
            'name' => 'Void Test Budget',
            'fiscalYear' => 2026,
            'totalAllocationMinor' => 5000000,
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))->postJson("/api/v1/enterprise/budgets/{$budgetId}/approve")->assertOk();

    // Create expense linked to budget
    $expenseId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/expenses', [
            'description' => 'Expense to void',
            'amountMinor' => 1000000,
            'expenseCategoryId' => $category->getKey(),
            'expenseDate' => '2026-08-15',
            'budgetId' => $budgetId,
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    $budgetBefore = Budget::query()->findOrFail($budgetId);
    expect($budgetBefore->spent_minor)->toBe(1000000);

    // Submit + approve
    $this->withToken(Identity::tokenFor($admin))->postJson("/api/v1/enterprise/expenses/{$expenseId}/submit")->assertOk();
    $this->withToken(Identity::tokenFor($admin))->postJson("/api/v1/enterprise/expenses/{$expenseId}/approve")->assertOk();

    // Void
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/expenses/{$expenseId}/void")
        ->assertOk()
        ->assertJsonPath('data.status', 'void');

    $budgetAfter = Budget::query()->findOrFail($budgetId);
    expect($budgetAfter->spent_minor)->toBe(0);
});

it('rejects expense when amount exceeds budget allocation', function () {
    [$org, $facility, $admin, $category] = enterpriseSetup();

    // Create small budget
    $budgetId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/budgets', [
            'name' => 'Small Budget',
            'fiscalYear' => 2026,
            'totalAllocationMinor' => 100000,
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withToken(Identity::tokenFor($admin))->postJson("/api/v1/enterprise/budgets/{$budgetId}/approve")->assertOk();

    // Try to post expense exceeding budget
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/expenses', [
            'description' => 'Over budget expense',
            'amountMinor' => 200000,
            'expenseCategoryId' => $category->getKey(),
            'expenseDate' => '2026-08-15',
            'budgetId' => $budgetId,
            'facilityId' => $facility->getKey(),
        ])
        ->assertStatus(409);
});

it('records an audit event for expense creation', function () {
    [$org, $facility, $admin, $category] = enterpriseSetup();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/expenses', [
            'description' => 'Audited expense',
            'amountMinor' => 100000,
            'expenseCategoryId' => $category->getKey(),
            'expenseDate' => '2026-08-15',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated();

    $audit = AuditEvent::query()
        ->where('action', 'expense.created')
        ->latest()
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->tenant_id)->toBe($org->getKey());
});

// ── Financial Periods ──

it('creates and closes a financial period', function () {
    [$org, $facility, $admin] = enterpriseSetup();

    $periodId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/financial-periods', [
            'name' => 'August 2026',
            'fiscalYear' => 2026,
            'periodNumber' => 8,
            'periodType' => 'monthly',
            'startDate' => '2026-08-01',
            'endDate' => '2026-08-31',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->json('data.id');

    // Close
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/financial-periods/{$periodId}/close")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    // Lock
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/financial-periods/{$periodId}/lock")
        ->assertOk()
        ->assertJsonPath('data.status', 'locked');
});

it('rejects closing a non-open period', function () {
    [$org, $facility, $admin] = enterpriseSetup();

    $periodId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/financial-periods', [
            'name' => 'Test Period',
            'fiscalYear' => 2026,
            'periodNumber' => 9,
            'periodType' => 'monthly',
            'startDate' => '2026-09-01',
            'endDate' => '2026-09-30',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    // Close
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/financial-periods/{$periodId}/close")
        ->assertOk();

    // Try to close again
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/financial-periods/{$periodId}/close")
        ->assertStatus(409);
});

it('rejects locking a non-closed period', function () {
    [$org, $facility, $admin] = enterpriseSetup();

    $periodId = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/financial-periods', [
            'name' => 'Open Period',
            'fiscalYear' => 2026,
            'periodNumber' => 10,
            'periodType' => 'monthly',
            'startDate' => '2026-10-01',
            'endDate' => '2026-10-31',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated()
        ->json('data.id');

    // Try to lock open period
    $this->withToken(Identity::tokenFor($admin))
        ->postJson("/api/v1/enterprise/financial-periods/{$periodId}/lock")
        ->assertStatus(409);
});

it('prevents duplicate financial period for same fiscal year and period number', function () {
    [$org, $facility, $admin] = enterpriseSetup();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/financial-periods', [
            'name' => 'First Period',
            'fiscalYear' => 2026,
            'periodNumber' => 11,
            'periodType' => 'monthly',
            'startDate' => '2026-11-01',
            'endDate' => '2026-11-30',
            'facilityId' => $facility->getKey(),
        ])
        ->assertCreated();

    // Duplicate
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/financial-periods', [
            'name' => 'Duplicate Period',
            'fiscalYear' => 2026,
            'periodNumber' => 11,
            'periodType' => 'monthly',
            'startDate' => '2026-11-01',
            'endDate' => '2026-11-30',
            'facilityId' => $facility->getKey(),
        ])
        ->assertStatus(409);
});

// ── Expense Categories ──

it('creates and lists expense categories', function () {
    [$org, $facility, $admin] = enterpriseSetup();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/enterprise/organizations/'.$org->getKey().'/expense-categories', [
            'code' => 'UTIL',
            'name' => 'Utilities',
            'description' => 'Electricity, water, gas',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'UTIL');

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/enterprise/organizations/'.$org->getKey().'/expense-categories')
        ->assertOk()
        ->assertJsonCount(2, 'data'); // MAINT from setup + UTIL created in test
});
